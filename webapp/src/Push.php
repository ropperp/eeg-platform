<?php

declare(strict_types=1);

/**
 * Push-Zustellung über Apple Push Notification service (APNs, HTTP/2-Provider-API).
 * Zugangsdaten (Team-ID/Key-ID/Bundle-ID/.p8-Auth-Key) kommen ausschließlich aus
 * platform_apns_config (Platform-Admin-Oberfläche), NIE aus dem Repo -- wie Mailer.php.
 * Bewusst kein fertiges JWT-/APNs-Paket (siehe AppApiAuth.php, gleiches Prinzip): der
 * ES256-JWT für die APNs-Authentifizierung wird direkt über openssl_sign() gebaut.
 *
 * APNs verlangt zwingend HTTP/2 (keine HTTP/1.1-Fallback-API mehr) -- PHPs eingebauter
 * http://-Stream-Wrapper (siehe Mailer.php/GraphMailReader.php) kann das nicht, deshalb hier
 * ausnahmsweise cURL mit CURLOPT_HTTP_VERSION_2 statt des sonst in diesem Projekt üblichen
 * stream_context-Ansatzes.
 *
 * Trigger, die push_notifications_queue befüllen: siehe database/migrate_20260903.sql. Diese
 * Klasse leert nur die Warteschlange (siehe sendPending(), aufgerufen von
 * scripts/send_pending_push.php über Host-Cron) -- Datenbank-Trigger machen selbst keine
 * Netzwerk-Aufrufe.
 */
class Push
{
    public static function config(): ?array
    {
        $cfg = DB::fetchOne('SELECT * FROM platform_apns_config WHERE id = 1');
        if (!$cfg || empty($cfg['team_id']) || empty($cfg['key_id']) || empty($cfg['bundle_id']) || empty($cfg['private_key_enc'])) {
            return null;
        }
        return $cfg;
    }

    public static function isConfigured(): bool
    {
        return self::config() !== null;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * openssl_sign() liefert bei EC-Schlüsseln eine DER-kodierte ECDSA-Signatur, JWS (ES256)
     * verlangt aber die rohe, auf feste Länge aufgefüllte R||S-Verkettung (je 32 Byte bei
     * P-256). DER: 0x30 <len> 0x02 <rlen> R 0x02 <slen> S -- für P-256 passen R/S-Längen
     * (max. 33 Byte inkl. Vorzeichen-Padding) immer in ein einzelnes Längen-Byte, deshalb
     * keine Mehrbyte-Längenkodierung zu behandeln.
     */
    private static function derSignatureToRaw(string $der, int $partLen = 32): string
    {
        $offset = 1; // 0x30 (SEQUENCE)
        $offset += (ord($der[$offset]) & 0x80) ? (ord($der[$offset]) & 0x7f) + 1 : 1;
        $offset++; // 0x02 (INTEGER, R)
        $rLen = ord($der[$offset]);
        $offset++;
        $r = ltrim(substr($der, $offset, $rLen), "\x00");
        $offset += $rLen;
        $offset++; // 0x02 (INTEGER, S)
        $sLen = ord($der[$offset]);
        $offset++;
        $s = ltrim(substr($der, $offset, $sLen), "\x00");
        return str_pad($r, $partLen, "\x00", STR_PAD_LEFT) . str_pad($s, $partLen, "\x00", STR_PAD_LEFT);
    }

    /**
     * Baut einen neuen ES256-JWT für die "Bearer"-Authentifizierung gegenüber APNs. Apple
     * erlaubt Wiederverwendung bis zu 60 Minuten, hier bewusst pro Lauf frisch erzeugt -- dieser
     * Prozess lebt nur für die Dauer eines Cron-Aufrufs (kein persistenter Zustand), bei der
     * hier üblichen Geräteanzahl/Cron-Taktung unproblematisch.
     */
    private static function buildJwt(array $cfg): string
    {
        $privateKeyPem = decryptSecret($cfg['private_key_enc']);
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('APNs-Auth-Key konnte nicht gelesen werden (ungültiges .p8-Format?).');
        }
        $signingInput = self::b64url(json_encode(['alg' => 'ES256', 'kid' => $cfg['key_id']]))
            . '.' . self::b64url(json_encode(['iss' => $cfg['team_id'], 'iat' => time()]));
        if (!openssl_sign($signingInput, $derSignature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APNs-JWT-Signatur fehlgeschlagen.');
        }
        return $signingInput . '.' . self::b64url(self::derSignatureToRaw($derSignature));
    }

    /**
     * Sendet EINE Push-Benachrichtigung an EIN Gerät. Rückgabe 'ok' (zugestellt), 'revoke'
     * (Token dauerhaft ungültig -- BadDeviceToken/Unregistered/DeviceTokenNotForTopic, Aufrufer
     * soll app_push_tokens entsprechend widerrufen) oder 'retry' (transienter Fehler, z.B.
     * Netzwerk oder APNs-5xx -- $errorOut enthält die Details für die Queue-Spalte `error`).
     */
    private static function sendOne(array $cfg, string $jwt, string $deviceToken, array $payload, ?string &$errorOut): string
    {
        $host = !empty($cfg['sandbox']) ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $ch = curl_init('https://' . $host . '/3/device/' . $deviceToken);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $cfg['bundle_id'],
                'apns-push-type: alert',
                'apns-priority: 10',
                'content-type: application/json',
            ],
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            $errorOut = 'cURL-Fehler: ' . $curlErr;
            return 'retry';
        }
        if ($httpCode === 200) {
            return 'ok';
        }
        $json = json_decode((string)$body, true);
        $reason = $json['reason'] ?? ('HTTP ' . $httpCode);
        $errorOut = 'APNs-Fehler: ' . $reason;
        return in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true) ? 'revoke' : 'retry';
    }

    /**
     * Leert push_notifications_queue: pro offenem Eintrag an ALLE passenden, nicht widerrufenen
     * Geräte des Nutzers zustellen (role NULL in der Queue = alle Rollen dieses Accounts, sonst
     * nur Geräte mit exakt dieser Rolle -- ein Account kann gleichzeitig als Mitglied UND Obmann
     * angemeldet sein, je mit eigenen Push-Tokens). Aufrufer: scripts/send_pending_push.php
     * (Host-Cron). Ist APNs nicht konfiguriert, bleibt die Queue unangetastet liegen (kein
     * Datenverlust, einfach nichts zu tun) statt jeden Eintrag fälschlich als Fehler zu
     * markieren -- sobald Patrick die Zugangsdaten hinterlegt, greift der nächste Cron-Lauf sie
     * ganz normal ab.
     */
    public static function sendPending(int $limit = 200): array
    {
        $cfg = self::config();
        if (!$cfg) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => 'APNs nicht konfiguriert.'];
        }
        $jwt = self::buildJwt($cfg);

        $pending = DB::fetchAll(
            'SELECT * FROM push_notifications_queue WHERE sent_at IS NULL ORDER BY created_at LIMIT ?',
            [$limit]
        );

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($pending as $item) {
            $tokens = DB::fetchAll(
                'SELECT id, device_token FROM app_push_tokens
                 WHERE user_id = ? AND revoked_at IS NULL AND (? IS NULL OR role = ?)',
                [$item['user_id'], $item['role'], $item['role']]
            );
            if (!$tokens) {
                DB::execute(
                    'UPDATE push_notifications_queue SET sent_at = now(), error = ? WHERE id = ?',
                    ['Kein registriertes Gerät.', $item['id']]
                );
                $skipped++;
                continue;
            }

            $payload = ['aps' => ['alert' => ['title' => $item['title'], 'body' => $item['body']], 'sound' => 'default']];
            if (!empty($item['data'])) {
                $extra = json_decode($item['data'], true);
                if (is_array($extra)) $payload = array_merge($payload, $extra);
            }

            $anySuccess = false;
            $stillPending = false;
            $lastError = null;
            foreach ($tokens as $t) {
                $error = null;
                $result = self::sendOne($cfg, $jwt, $t['device_token'], $payload, $error);
                if ($result === 'ok') {
                    $anySuccess = true;
                } elseif ($result === 'revoke') {
                    DB::execute('UPDATE app_push_tokens SET revoked_at = now() WHERE id = ?', [$t['id']]);
                    $lastError = $error;
                } else {
                    $stillPending = true;
                    $lastError = $error;
                }
            }

            if ($anySuccess || !$stillPending) {
                // Zugestellt, ODER endgültig gescheitert (alle Ziel-Geräte gerade widerrufen) --
                // in beiden Fällen kein weiterer Zustellversuch nötig.
                DB::execute(
                    'UPDATE push_notifications_queue SET sent_at = now(), error = ? WHERE id = ?',
                    [$anySuccess ? null : $lastError, $item['id']]
                );
                $anySuccess ? $sent++ : $failed++;
            } else {
                // Transienter Fehler (Netzwerk, APNs 5xx) -- sent_at bleibt NULL, nächster
                // Cron-Lauf versucht es erneut.
                DB::execute('UPDATE push_notifications_queue SET error = ? WHERE id = ?', [$lastError, $item['id']]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }
}
