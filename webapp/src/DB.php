<?php

declare(strict_types=1);

/**
 * Datenbankverbindung mit PostgreSQL Row-Level Security.
 * Vor jeder Abfrage mit Community-Kontext wird app.community_id gesetzt,
 * sodass RLS-Policies greifen und Mandanten sich nie gegenseitig sehen.
 */
class DB
{
    private static ?PDO $pdo = null;
    private static ?string $currentCommunityId = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'timescaledb',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'eeg_platform'
            );
            [$dbUser, $dbPassword] = self::credentials();
            self::$pdo = new PDO($dsn, $dbUser, $dbPassword, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Zugangsdaten der Webapp. Row-Level Security greift nur für die eingeschränkte Rolle
     * APP_DB_USER (kein Superuser, kein Tabellenbesitzer, kein BYPASSRLS; siehe
     * scripts/db_runtime_role_setup.sh). In Produktion (APP_ENV=production, Standard in
     * docker-compose.yml) ist sie Pflicht: fehlt sie, bricht die Verbindung mit klarer Meldung ab,
     * statt still als privilegierte Rolle weiterzulaufen. Nur in der Entwicklung bleibt der
     * Rückfall auf DB_USER erlaubt.
     *
     * @param array<string, string|false>|null $env nur für Tests; null = echte Umgebung
     * @return array{0: string, 1: string}
     */
    public static function credentials(?array $env = null): array
    {
        $get = static fn(string $k): string => (string) ($env === null ? getenv($k) : ($env[$k] ?? ''));
        $appUser = $get('APP_DB_USER');
        $appPassword = $get('APP_DB_PASSWORD');

        if (self::isProduction($env === null ? null : (string) ($env['APP_ENV'] ?? ''))) {
            if ($appUser === '' || $appPassword === '') {
                throw new RuntimeException(
                    'APP_ENV=production: APP_DB_USER und APP_DB_PASSWORD fehlen. Ohne eingeschränkte '
                    . 'Datenbank-Rolle greift die Mandantentrennung (RLS) nicht. Abhilfe: '
                    . './scripts/db_runtime_role_setup.sh ausführen.'
                );
            }
            if ($appUser === $get('DB_USER')) {
                throw new RuntimeException(
                    'APP_ENV=production: APP_DB_USER darf nicht dieselbe Rolle wie DB_USER sein.'
                );
            }
            return [$appUser, $appPassword];
        }

        if ($appUser !== '') {
            return [$appUser, $appPassword];
        }
        return [$get('DB_USER'), $get('DB_PASSWORD')];
    }

    /** Produktion ist der Normalfall; nur APP_ENV=development/test schaltet die Prüfungen ab. */
    public static function isProduction(?string $appEnv = null): bool
    {
        $appEnv = strtolower(trim($appEnv ?? (string) getenv('APP_ENV')));
        return !in_array($appEnv, ['development', 'dev', 'local', 'test', 'testing'], true);
    }

    /**
     * Prüft die tatsächlich verbundene Rolle: Superuser, BYPASSRLS oder Besitz einer Tabelle mit
     * RLS hebeln die Mandantentrennung aus. Leeres Ergebnis = in Ordnung. Wird vom Healthcheck
     * (webapp/bin/db_role_check.php) und vom RLS-Integrationstest benutzt.
     *
     * @return list<string>
     */
    public static function runtimeRoleProblems(?PDO $pdo = null): array
    {
        $pdo ??= self::get();
        $role = $pdo->query(
            "SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user"
        )->fetch(PDO::FETCH_ASSOC);
        $problems = [];
        if (!$role) {
            return ['Rolle nicht gefunden'];
        }
        if ($role['rolsuper']) {
            $problems[] = "Rolle {$role['name']} ist Superuser";
        }
        if ($role['rolbypassrls']) {
            $problems[] = "Rolle {$role['name']} hat BYPASSRLS";
        }
        $owned = $pdo->query(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relrowsecurity
               AND pg_get_userbyid(c.relowner) = current_user ORDER BY 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($owned) {
            $problems[] = "Rolle {$role['name']} besitzt RLS-Tabellen: " . implode(', ', $owned);
        }
        return $problems;
    }

    /**
     * Setzt die Community-ID für Row-Level Security.
     * Muss vor jeder mandantenspezifischen Abfrage aufgerufen werden.
     *
     * Bewusst SET (session-scoped), nicht SET LOCAL: SET LOCAL gilt nur bis zum Ende der
     * aktuellen Transaktion, die App läuft aber ohne explizite Transaktionen (jeder
     * PDO-Call ist im Autocommit-Modus eine eigene Mini-Transaktion) — SET LOCAL wäre also
     * schon beim nächsten Query wieder verloren. SET auf Session-Ebene passt korrekt zu
     * "eine PDO-Connection pro Request".
     */
    public static function setCommunity(string $communityId): void
    {
        if (self::$currentCommunityId === $communityId) {
            return;
        }
        // Validierung: muss UUID-Format haben
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $communityId)) {
            throw new InvalidArgumentException('Ungültige Community-ID');
        }
        self::get()->exec("SET app.community_id = " . self::get()->quote($communityId));
        self::$currentCommunityId = $communityId;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    public static function beginTransaction(): void { self::get()->beginTransaction(); }
    public static function commit(): void { self::get()->commit(); }
    public static function rollback(): void { self::get()->rollBack(); }
}
