<?php

declare(strict_types=1);

/**
 * Übernimmt die bestehenden Plattform-Konten in Keycloak (docs/adr/0007-keycloak.md).
 *
 * Niemand muss ein neues Passwort setzen: Argon2id- und bcrypt-Hashes werden unverändert übernommen
 * (bcrypt prüft die Erweiterung keycloak/providers, Keycloak stellt beim ersten Login still auf Argon2
 * um). Eingerichtete 2FA (TOTP) wird mit übernommen. Passkeys nicht: die richtet man in Keycloak neu ein.
 *
 * Sicher mehrfach ausführbar: Konten mit gesetzter users.keycloak_id werden übersprungen (mit --all
 * abgeglichen). Gibt es in Keycloak schon ein Konto mit derselben E-Mail (z. B. abgebrochener Lauf), wird
 * es verknüpft statt doppelt angelegt. Die Arbeit macht App\\Infrastructure\\Auth\\KeycloakSync.
 *
 * Aufruf (auf dem Server, im Repo-Root):
 *   docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php --dry-run
 *   docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php
 * Ausgabe enthält nur Zähler und Benutzer-IDs, keine E-Mail-Adressen.
 */

use App\Infrastructure\Auth\KeycloakCredentialMapper;
use App\Infrastructure\Auth\KeycloakSync;

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/AppConfig.php';
require __DIR__ . '/../src/autoload.php';

$dryRun = in_array('--dry-run', $argv, true);
// --all: auch bereits verknüpfte Konten abgleichen (z. B. beim Umschalten auf AUTH_PROVIDER=keycloak).
$all = in_array('--all', $argv, true);
$out = static fn(string $s) => fwrite(STDOUT, $s . "\n");

$users = DB::fetchAll(
    "SELECT u.id, u.password_hash, u.totp_enabled, u.totp_secret FROM users u"
    . ($all ? '' : ' WHERE u.keycloak_id IS NULL') . ' ORDER BY u.created_at'
);

$stats = ['argon2' => 0, 'bcrypt' => 0, 'ohne_passwort' => 0, 'totp' => 0, 'verknuepft' => 0, 'angelegt' => 0,
          'aktualisiert' => 0, 'unveraendert' => 0, 'fehler' => 0];
$sync = $dryRun ? null : KeycloakSync::fromEnv();
if (!$dryRun && $sync === null) {
    $out('Keycloak ist nicht eingerichtet (KC_PORTAL_CLIENT_SECRET fehlt). Erst ./scripts/keycloak_setup.sh, dann docker compose up -d webapp.');
    exit(1);
}

foreach ($users as $u) {
    $hash = (string) $u['password_hash'];
    if (KeycloakCredentialMapper::password($hash) !== null) {
        $stats[str_starts_with($hash, '$argon2') ? 'argon2' : 'bcrypt']++;
    } else {
        $stats['ohne_passwort']++;
        $out("  Hinweis: Benutzer {$u['id']} hat kein übernehmbares Passwort (setzt es über „Passwort vergessen“).");
    }
    if (in_array($u['totp_enabled'], [true, 't', 'true', 1, '1'], true)) {
        if (KeycloakCredentialMapper::totp(totpSecretFromStorage($u['totp_secret'])) !== null) {
            $stats['totp']++;
        } else {
            $out("  Hinweis: Benutzer {$u['id']}: 2FA-Secret nicht lesbar, 2FA muss neu eingerichtet werden.");
        }
    }
    if ($sync === null) {
        continue;
    }
    try {
        $action = $sync->syncUser((string) $u['id']);
        if (isset($stats[$action])) {
            $stats[$action]++;
        }
    } catch (\Throwable $e) {
        $stats['fehler']++;
        $out("  FEHLER bei Benutzer {$u['id']}: " . $e->getMessage());
    }
}

$out(($dryRun ? '[Probelauf] ' : '') . count($users) . ($all ? ' Konto/Konten geprüft.' : ' Konto/Konten ohne Keycloak-Verknüpfung.'));
foreach ($stats as $k => $v) {
    $out(sprintf('  %-14s %d', $k, $v));
}
exit($stats['fehler'] > 0 ? 1 : 0);
