<?php

declare(strict_types=1);

/**
 * scripts/migrate_encrypt_totp_secrets.php — einmalige Migration: verschlüsselt bereits
 * bestehende TOTP-Secrets (users.totp_secret) nachträglich mit encryptSecret() (OWASP-Audit,
 * 13.08.2026 -- Secrets lagen bis jetzt im Klartext in der DB). Ab diesem Update
 * (webapp/public/index.php, /portal/profile/2fa/enable + /portal/login/2fa) werden neue
 * Secrets ohnehin nur noch verschlüsselt gespeichert bzw. vor der Prüfung entschlüsselt --
 * dieses Skript holt bestehende, VOR dem Update aktivierte 2FA-Konten nach, damit niemand seine
 * Zwei-Faktor-Authentifizierung neu einrichten muss (kein "erneut registrieren").
 *
 * Sicher mehrfach ausführbar: totpGenerateSecret() liefert reines Base32 (nur A-Z2-7, keine
 * Kleinbuchstaben, kein '+'/'/'/'='), encryptSecret() liefert dagegen base64(IV+Ciphertext) --
 * praktisch garantiert mindestens ein Zeichen außerhalb des Base32-Alphabets. Ein Secret wird
 * nur verschlüsselt, wenn es AUSSCHLIESSLICH aus Base32-Zeichen besteht; bereits verschlüsselte
 * Secrets (aus einem vorherigen Lauf oder frisch über /2fa/enable gespeicherte) werden erkannt
 * und übersprungen.
 *
 * Aufruf (im Repo-Root, auf dem Server, EINMALIG nach diesem Update):
 *   docker compose exec -T webapp php < scripts/migrate_encrypt_totp_secrets.php
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

$rows = DB::fetchAll("SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret != ''");

$encrypted = 0;
$skipped = 0;

foreach ($rows as $row) {
    $secret = $row['totp_secret'];
    // Reines Base32 (RFC 4648, ohne Padding) => noch unverschlüsselter Klartext-Secret.
    if (!preg_match('/^[A-Z2-7]+$/', $secret)) {
        $skipped++;
        continue;
    }
    DB::execute('UPDATE users SET totp_secret = ? WHERE id = ?', [encryptSecret($secret), $row['id']]);
    $encrypted++;
}

fwrite(STDERR, "[migrate_encrypt_totp_secrets] {$encrypted} Secret(s) verschlüsselt, {$skipped} bereits verschlüsselt/übersprungen.\n");
