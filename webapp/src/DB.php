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
            self::$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Setzt die Community-ID für Row-Level Security.
     * Muss vor jeder mandantenspezifischen Abfrage aufgerufen werden.
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
        self::get()->exec("SET LOCAL app.community_id = " . self::get()->quote($communityId));
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
