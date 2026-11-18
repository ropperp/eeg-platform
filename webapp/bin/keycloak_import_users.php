<?php

declare(strict_types=1);

/**
 * Übernimmt die bestehenden Plattform-Konten in Keycloak (docs/adr/0007-keycloak.md).
 *
 * Niemand muss ein neues Passwort setzen: Argon2id- und bcrypt-Hashes werden unverändert übernommen
 * (bcrypt prüft die Erweiterung keycloak/providers, Keycloak stellt beim ersten Login still auf Argon2
 * um). Eingerichtete 2FA (TOTP) wird mit übernommen. Passkeys nicht: die richtet man in Keycloak neu ein.
 *
 * Sicher mehrfach ausführbar: Konten mit gesetzter users.keycloak_id werden übersprungen. Gibt es in
 * Keycloak schon ein Konto mit derselben E-Mail (z. B. abgebrochener Lauf), wird es verknüpft statt
 * doppelt angelegt.
 *
 * Aufruf (auf dem Server, im Repo-Root):
 *   docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php --dry-run
 *   docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php
 * Ausgabe enthält nur Zähler und Benutzer-IDs, keine E-Mail-Adressen.
 */

use App\Infrastructure\Auth\KeycloakAdminClient;
use App\Infrastructure\Auth\KeycloakCredentialMapper;

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/autoload.php';

$dryRun = in_array('--dry-run', $argv, true);
$out = static fn(string $s) => fwrite(STDOUT, $s . "\n");

$users = DB::fetchAll(
    "SELECT u.id, u.email, u.password_hash, u.first_name, u.last_name, u.active, u.totp_enabled, u.totp_secret,
            EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'platform_admin') AS is_platform_admin
     FROM users u
     WHERE u.keycloak_id IS NULL
     ORDER BY u.created_at"
);

$stats = ['argon2' => 0, 'bcrypt' => 0, 'ohne_passwort' => 0, 'totp' => 0, 'verknuepft' => 0, 'angelegt' => 0, 'fehler' => 0];
$client = $dryRun ? null : KeycloakAdminClient::fromEnv();

foreach ($users as $u) {
    $hash = (string) $u['password_hash'];
    $credentials = [];
    $pw = KeycloakCredentialMapper::password($hash);
    if ($pw !== null) {
        $credentials[] = $pw;
        $stats[str_starts_with($hash, '$argon2') ? 'argon2' : 'bcrypt']++;
    } else {
        $stats['ohne_passwort']++;
        $out("  Hinweis: Benutzer {$u['id']} hat kein übernehmbares Passwort (setzt es über „Passwort vergessen“).");
    }
    $totpEnabled = in_array($u['totp_enabled'], [true, 't', 'true', 1, '1'], true);
    if ($totpEnabled) {
        $otp = KeycloakCredentialMapper::totp(totpSecretFromStorage($u['totp_secret']));
        if ($otp !== null) {
            $credentials[] = $otp;
            $stats['totp']++;
        } else {
            $out("  Hinweis: Benutzer {$u['id']}: 2FA-Secret nicht lesbar, 2FA muss neu eingerichtet werden.");
        }
    }
    if ($dryRun || $client === null) {
        continue;
    }

    try {
        $email = strtolower(trim((string) $u['email']));
        $existing = $client->findUserByEmail($email);
        if ($existing !== null) {
            $kcId = (string) $existing['id'];
            $stats['verknuepft']++;
        } else {
            $kcId = $client->createUser([
                'username' => $email,
                'email' => $email,
                'emailVerified' => true,
                'firstName' => (string) $u['first_name'],
                'lastName' => (string) $u['last_name'],
                'enabled' => in_array($u['active'], [true, 't', 'true', 1, '1'], true),
                'credentials' => $credentials,
            ]);
            $stats['angelegt']++;
        }
        if (in_array($u['is_platform_admin'], [true, 't', 'true', 1, '1'], true)) {
            $client->assignRealmRole($kcId, 'platform_admin');
        }
        DB::execute('UPDATE users SET keycloak_id = ?, keycloak_synced_at = now() WHERE id = ?', [$kcId, $u['id']]);
    } catch (\Throwable $e) {
        $stats['fehler']++;
        $out("  FEHLER bei Benutzer {$u['id']}: " . $e->getMessage());
    }
}

$out(($dryRun ? '[Probelauf] ' : '') . count($users) . ' Konto/Konten ohne Keycloak-Verknüpfung.');
foreach ($stats as $k => $v) {
    $out(sprintf('  %-14s %d', $k, $v));
}
exit($stats['fehler'] > 0 ? 1 : 0);
