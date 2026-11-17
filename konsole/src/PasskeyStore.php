<?php

declare(strict_types=1);

namespace Konsole;

/**
 * Öffentliche Passkey-Schlüssel der Platform-Admins. Quelle ist die Plattform-Datenbank
 * (user_passkeys, dieselben Passkeys wie beim Portal-Login). Jede erfolgreiche Abfrage wird in
 * eine eigene Datei gespiegelt; ist die Datenbank nicht erreichbar, gilt die letzte Kopie -- so
 * kommt man auch dann in die Konsole, wenn Datenbank oder Webapp ausgefallen sind.
 * Gespeichert werden nur öffentliche Schlüssel (kein Geheimnis).
 */
final class PasskeyStore
{
    /** @var (callable(): ?list<array<string, mixed>>)|null */
    private $loader;

    /** @param (callable(): ?list<array<string, mixed>>)|null $loader liefert Zeilen aus der DB oder null */
    public function __construct(private readonly string $file, ?callable $loader)
    {
        $this->loader = $loader;
    }

    public static function dbLoader(Config $c): callable
    {
        return function () use ($c): ?array {
            try {
                $pdo = new \PDO($c->dbDsn, $c->dbUser, $c->dbPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
                $rows = $pdo->query(
                    "SELECT p.credential_id, p.public_key_pem, p.sign_count, u.id AS user_id,
                            trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) AS name
                       FROM user_passkeys p JOIN users u ON u.id = p.user_id
                      WHERE u.active = true
                        AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'platform_admin')"
                )->fetchAll(\PDO::FETCH_ASSOC);
                return $rows;
            } catch (\Throwable $e) {
                error_log('[konsole] Datenbank nicht erreichbar, nutze Passkey-Kopie: ' . $e->getMessage());
                return null;
            }
        };
    }

    /**
     * Holt die aktuelle Liste (DB, sonst Datei) und spiegelt sie. Zähler: es gilt der höhere Wert
     * aus DB und Kopie, damit eine in der Konsole benutzte Anmeldung nicht wiederholbar wird.
     *
     * @return array<string, array{pem: string, count: int, user_id: string, name: string}> nach credential_id
     */
    public function all(): array
    {
        $local = $this->readFile();
        $rows = $this->loader ? ($this->loader)() : null;
        if ($rows === null) {
            return $local;
        }
        $fresh = [];
        foreach ($rows as $r) {
            $id = (string) $r['credential_id'];
            $fresh[$id] = [
                'pem'     => (string) $r['public_key_pem'],
                'count'   => max((int) $r['sign_count'], (int) ($local[$id]['count'] ?? 0)),
                'user_id' => (string) $r['user_id'],
                'name'    => (string) $r['name'],
            ];
        }
        $this->writeFile($fresh);
        return $fresh;
    }

    public function updateCount(string $credentialId, int $count): void
    {
        $all = $this->readFile();
        if (isset($all[$credentialId])) {
            $all[$credentialId]['count'] = max($count, $all[$credentialId]['count']);
            $this->writeFile($all);
        }
    }

    /** @return array<string, array{pem: string, count: int, user_id: string, name: string}> */
    private function readFile(): array
    {
        $raw = @file_get_contents($this->file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeFile(array $data): void
    {
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $this->file);
        }
    }
}
