<?php

declare(strict_types=1);

/**
 * Liest das zentrale EDA-Postfach (platform_mail_config.eda_import_mailbox_address, z.B.
 * eda@stromfueralle.at) über Microsoft Graph aus, lädt die im EDA-Anwenderportal per Mail
 * verschickte Exportdatei automatisch herunter und übergibt sie an eda-parser/parser.py --
 * ersetzt für den monatlichen Regelfall das manuelle Herunterladen + Hochladen über
 * /portal/eda/upload (das bleibt als Fallback bestehen, z.B. für Nachimporte).
 *
 * WICHTIG -- was hier NICHT automatisiert wird: das Anfordern/Auslösen des Exports im
 * EDA-Anwenderportal selbst (Login + Klick auf "Export") bleibt ein manueller Schritt, dessen
 * genauer Ablauf (Formularfelder, evtl. 2FA) uns nicht vorliegt. Sobald EDA den Exportlink
 * per Mail an das hier hinterlegte Postfach schickt, übernimmt diese Klasse den Rest.
 *
 * Annahme, die sich nur an einer echten EDA-Mail verifizieren lässt: die Exportmail enthält
 * entweder die XLSX direkt als Anhang, oder einen Download-Link im Mailtext, der OHNE weiteren
 * Portal-Login abrufbar ist (übliches Muster für zeitlich befristete, signierte Download-Links).
 * Verlangt der Link stattdessen eine aktive Portal-Session, schlägt der Download fehl und es
 * gibt eine Alarm-Mail -- die betroffene Mail bleibt dann ungelesen für die manuelle Prüfung.
 */
class EdaAutoImporter
{
    /** Verarbeitet alle ungelesenen Mails im konfigurierten Postfach. Gibt eine Liste von Log-Zeilen zurück. */
    public static function run(): array
    {
        $cfg = DB::fetchOne('SELECT eda_import_mailbox_address FROM platform_mail_config WHERE id = 1');
        $mailbox = trim((string)($cfg['eda_import_mailbox_address'] ?? ''));
        if ($mailbox === '') {
            return ['EDA-Auto-Import nicht konfiguriert (Platform-Admin → E-Mail-Einstellungen → Postfachadresse eintragen).'];
        }

        $messages = GraphMailReader::listUnread($mailbox);
        if (!$messages) {
            return ["Keine ungelesenen Mails in {$mailbox}."];
        }

        $log = [];
        foreach ($messages as $msg) {
            $log[] = self::processMessage($mailbox, $msg);
        }
        return $log;
    }

    private static function processMessage(string $mailbox, array $msg): string
    {
        $subject = $msg['subject'] ?? '(ohne Betreff)';
        $id      = $msg['id'] ?? '';

        try {
            [$filename, $content] = self::extractFile($mailbox, $msg);
        } catch (\Throwable $e) {
            self::fail($mailbox, $id, $subject, 'Datei konnte nicht ermittelt/heruntergeladen werden: ' . $e->getMessage());
            return "FEHLER [{$subject}]: " . $e->getMessage();
        }

        $marktpartnerId = self::extractMarktpartnerId($filename);
        if ($marktpartnerId === null) {
            self::fail($mailbox, $id, $subject, "Marktpartner-ID nicht aus Dateiname '{$filename}' ablesbar.");
            return "FEHLER [{$subject}]: Marktpartner-ID nicht ablesbar (Datei: {$filename})";
        }

        $community = DB::fetchOne('SELECT id, slug, name FROM communities WHERE LOWER(marktpartner_id) = ?', [strtolower($marktpartnerId)]);
        if (!$community) {
            self::fail($mailbox, $id, $subject, "Keine EEG mit Marktpartner-ID '{$marktpartnerId}' gefunden (Datei: {$filename}).");
            return "FEHLER [{$subject}]: keine EEG für Marktpartner-ID {$marktpartnerId}";
        }

        $savePath = '/var/www/html/storage/uploads/' . uniqid('eda_auto_') . '_' . basename($filename);
        file_put_contents($savePath, $content);

        $cmd = sprintf(
            'python3 /var/www/html/eda-parser/parser.py --file %s --community %s 2>&1',
            escapeshellarg($savePath),
            escapeshellarg($community['slug'])
        );
        $output = shell_exec($cmd);
        $result = json_decode((string)$output, true);

        if ($result === null) {
            self::fail($mailbox, $id, $subject, 'Parser-Fehler für ' . $community['name'] . ': ' . substr((string)$output, 0, 500));
            DB::execute(
                'INSERT INTO audit_log (community_id, aktion, beschreibung, ist_fehler) VALUES (?, ?, ?, true)',
                [$community['id'], 'eda.auto_import_error', 'Automatischer EDA-Import fehlgeschlagen: ' . substr((string)$output, 0, 500)]
            );
            return "FEHLER [{$subject}]: Parser-Fehler für {$community['name']}";
        }

        DB::execute(
            'INSERT INTO audit_log (community_id, aktion, beschreibung) VALUES (?, ?, ?)',
            [
                $community['id'],
                'eda.auto_import',
                'Automatischer EDA-Import (Postfach): ' . ($result['records'] ?? '?') . ' Datensätze importiert'
                    . (!empty($result['warnings']) ? ', ' . count($result['warnings']) . ' Warnung(en)' : ''),
            ]
        );
        GraphMailReader::markRead($mailbox, $id);
        return "OK [{$subject}]: {$community['name']}, " . ($result['records'] ?? '?') . ' Datensätze';
    }

    /**
     * Liefert [Dateiname, Rohinhalt]. Bevorzugt einen direkten XLSX-Anhang; sonst wird der erste
     * http(s)-Link im HTML-Mailtext heruntergeladen (Annahme siehe Klassen-Kommentar oben).
     */
    private static function extractFile(string $mailbox, array $msg): array
    {
        if (!empty($msg['hasAttachments'])) {
            foreach (GraphMailReader::attachments($mailbox, $msg['id']) as $a) {
                if (str_ends_with(strtolower($a['name']), '.xlsx')) {
                    return [$a['name'], $a['content']];
                }
            }
        }

        $html = $msg['body']['content'] ?? '';
        if (!preg_match('/href="(https?:\/\/[^"]+)"/i', $html, $m)) {
            throw new \RuntimeException('Weder XLSX-Anhang noch Download-Link im Mailtext gefunden.');
        }
        $url = html_entity_decode($m[1]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 60,
            'follow_location' => 1,
            'max_redirects' => 10,
            'ignore_errors' => true,
        ]]);
        $content = @file_get_contents($url, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
        if ($code !== 200 || $content === false || $content === '') {
            throw new \RuntimeException("Download fehlgeschlagen (HTTP {$code}) von {$url}");
        }

        $filename = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/filename="?([^"\r\n;]+)"?/i', $h, $fm)) { $filename = $fm[1]; break; }
        }
        if ($filename === null) {
            $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'export.xlsx');
        }
        return [$filename, $content];
    }

    /** Marktpartner-ID steht laut echtem EDA-Dateinamensschema vor dem ersten Unterstrich, z.B. "RC108175_...". */
    private static function extractMarktpartnerId(string $filename): ?string
    {
        return preg_match('/^([A-Za-z0-9]+)_/', $filename, $m) ? $m[1] : null;
    }

    /**
     * Alarm-Mail bei nicht automatisch lösbaren Fällen (Community-Zuordnung, Download, Parser).
     * Die Mail bleibt bewusst ungelesen -- kein erneuter automatischer Versuch, bis jemand
     * nachgesehen hat (verhindert eine stille Endlosschleife bei einem dauerhaft kaputten Fall).
     */
    private static function fail(string $mailbox, string $messageId, string $subject, string $reason): void
    {
        $recipients = [];
        try {
            $cfg = DB::fetchOne('SELECT backup_alert_email_1, backup_alert_email_2 FROM platform_mail_config WHERE id = 1');
            foreach (['backup_alert_email_1', 'backup_alert_email_2'] as $k) {
                $v = trim((string)($cfg[$k] ?? ''));
                if ($v !== '') $recipients[] = $v;
            }
        } catch (\Throwable $e) { /* ignorieren, Fallback unten */ }
        if (!$recipients) {
            try {
                $row = DB::fetchOne(
                    "SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id
                     WHERE ur.role = 'platform_admin' AND u.active = true ORDER BY u.created_at LIMIT 1"
                );
                if (!empty($row['email'])) $recipients[] = $row['email'];
            } catch (\Throwable $e) { /* keinen Empfänger gefunden -- unten geloggt */ }
        }

        $body = '<p><strong>Automatischer EDA-Import konnte eine Mail nicht verarbeiten.</strong></p>'
            . '<p><strong>Postfach:</strong> ' . htmlspecialchars($mailbox) . '<br>'
            . '<strong>Betreff:</strong> ' . htmlspecialchars($subject) . '<br>'
            . '<strong>Grund:</strong> ' . htmlspecialchars($reason) . '</p>'
            . '<p>Die Mail bleibt ungelesen im Postfach, bis das Problem behoben oder die Datei manuell über '
            . '/portal/eda/upload importiert wurde.</p>';

        foreach (array_unique($recipients) as $to) {
            try { Mailer::send($to, 'EDA-Auto-Import fehlgeschlagen: ' . $subject, $body); } catch (\Throwable $e) { /* siehe error_log */ }
        }
        error_log('[eda_auto_import] ' . $subject . ': ' . $reason);
    }
}
