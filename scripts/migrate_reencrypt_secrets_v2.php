<?php

declare(strict_types=1);

/**
 * scripts/migrate_reencrypt_secrets_v2.php -- einmalige Umstellung gespeicherter Zugangsdaten vom
 * alten Format (AES-256-CBC) auf das neue (AES-256-GCM "v2:", siehe encryptSecret() in
 * functions.php). Die alten Werte bleiben auch ohne dieses Skript lesbar -- das Skript holt nur den
 * Manipulationsschutz für bereits gespeicherte Daten nach.
 *
 * Sicher mehrfach ausführbar: Werte mit "v2:" werden übersprungen. Lässt sich ein alter Wert nicht
 * entschlüsseln (z.B. weil APP_SECRET inzwischen geändert wurde), bleibt er UNVERÄNDERT stehen und
 * wird gemeldet -- es wird nie ein Wert überschrieben, dessen Klartext nicht bekannt ist.
 *
 * Betrifft: users.totp_secret, communities.eda_login_password_enc, platform_apns_config.private_key_enc,
 * metering_points.p1_key_enc und metering_points.wifi_password_enc.
 *
 * Aufruf (im Repo-Root, auf dem Server, EINMALIG nach dem Update vom 15.11.2026):
 *   docker compose exec -T webapp php < scripts/migrate_reencrypt_secrets_v2.php
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

$done = 0; $skipped = 0; $failed = 0;

/** @param array<int, array{id: string, v: string}> $rows */
function reencryptRows(string $table, string $column, array $rows, bool $totpBase32, int &$done, int &$skipped, int &$failed): void
{
    foreach ($rows as $row) {
        $value = (string)$row['v'];
        if ($value === '' || str_starts_with($value, 'v2:')) { $skipped++; continue; }
        // 2FA-Secrets aus der Zeit vor der ersten Verschlüsselung liegen noch als reines Base32 vor
        $plain = ($totpBase32 && preg_match('/^[A-Z2-7]+$/', $value)) ? $value : decryptSecret($value);
        if ($plain === '') {
            $failed++;
            fwrite(STDERR, "[reencrypt] WARNUNG: {$table}.{$column} (id {$row['id']}) nicht entschlüsselbar -- unverändert gelassen.\n");
            continue;
        }
        DB::execute("UPDATE {$table} SET {$column} = ? WHERE id = ?", [encryptSecret($plain), $row['id']]);
        $done++;
    }
}

// Tabellen ohne Row-Level-Security
reencryptRows('users', 'totp_secret',
    DB::fetchAll("SELECT id, totp_secret AS v FROM users WHERE totp_secret IS NOT NULL AND totp_secret <> ''"), true, $done, $skipped, $failed);
reencryptRows('communities', 'eda_login_password_enc',
    DB::fetchAll("SELECT id, eda_login_password_enc AS v FROM communities WHERE eda_login_password_enc IS NOT NULL"), false, $done, $skipped, $failed);
try {
    reencryptRows('platform_apns_config', 'private_key_enc',
        DB::fetchAll("SELECT id, private_key_enc AS v FROM platform_apns_config WHERE private_key_enc IS NOT NULL"), false, $done, $skipped, $failed);
} catch (\Throwable $e) {
    fwrite(STDERR, "[reencrypt] platform_apns_config übersprungen: " . $e->getMessage() . "\n");
}

// metering_points hat Row-Level-Security -> je EEG einzeln
foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
    DB::setCommunity($c['id']);
    foreach (['p1_key_enc', 'wifi_password_enc'] as $col) {
        reencryptRows('metering_points', $col,
            DB::fetchAll("SELECT id, {$col} AS v FROM metering_points WHERE community_id = ? AND {$col} IS NOT NULL", [$c['id']]), false, $done, $skipped, $failed);
    }
}

fwrite(STDERR, "[reencrypt] {$done} Wert(e) umgestellt, {$skipped} bereits neu/leer, {$failed} nicht entschlüsselbar (unverändert).\n");
exit($failed > 0 ? 1 : 0);
