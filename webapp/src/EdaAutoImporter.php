<?php

declare(strict_types=1);

/**
 * Liest das zentrale EDA-Postfach (platform_mail_config.eda_import_mailbox_address, z.B.
 * eda@stromfueralle.at) über Microsoft Graph aus, lädt die im EDA-Anwenderportal per Mail
 * verschickte Exportdatei automatisch herunter und übergibt sie an den passenden Parser --
 * ersetzt für den Regelfall das manuelle Herunterladen + Hochladen über /portal/eda/upload
 * bzw. /portal/eda/upload-interval (beide bleiben als Fallback bestehen, z.B. für Nachimporte).
 *
 * WICHTIG -- was hier NICHT automatisiert wird: das Anfordern/Auslösen des Exports im
 * EDA-Anwenderportal selbst (Login + Klick auf "Export") bleibt ein manueller Schritt, dessen
 * genauer Ablauf (Formularfelder, evtl. 2FA) uns nicht vorliegt. Sobald EDA den Exportlink
 * per Mail an das hier hinterlegte Postfach schickt, übernimmt diese Klasse den Rest.
 *
 * Verifiziert an einer echten EDA-Mail (Patrick, 13.08.2026, Betreff "EDA Portal –
 * Energiedatenreport RC108175", Absender no-reply@eda.at): die Exportmail enthält KEINEN
 * Anhang, sondern einen signierten Download-Link im HTML-Mailtext
 * (https://prod-api.eda-portal.at/exports/download/<uuid>?expires=...&signature=...,
 * 7 Tage gültig), der OHNE weiteren Portal-Login abrufbar ist -- der XLSX-Anhang-Pfad in
 * extractFile() unten bleibt trotzdem als Fallback bestehen, falls EDA das Format irgendwann
 * ändert. Verlangt der Link doch einmal eine aktive Portal-Session, schlägt der Download fehl
 * und es gibt eine Alarm-Mail -- die betroffene Mail bleibt dann ungelesen für die manuelle
 * Prüfung.
 *
 * ZWEI EDA-Export-Typen teilen sich dasselbe Postfach (Patrick, 09.10.2026, nach einem
 * fehlgeschlagenen Auto-Import-Versuch: "Das ist ein anderer Report, ein Detailreport, den ich
 * für die Darstellung der Diagramme von den Mitgliedern nehme [...] Können wir da auch machen,
 * dass er [...] alle 15 Minuten überprüft und mir auch den Detail-Report hochlädt?"):
 * - "EDA Portal – Energiedatenreport <ID>" -- der monatliche, abrechnungsrelevante Report
 *   (Sheets "Gesamtübersicht"/"Detailübersicht") -- siehe eda-parser/parser.py.
 * - "EDA Portal – Detailreport <ID> <von> – <bis>" -- Viertelstundenwerte für die
 *   Mitglieder-Diagramme (Sheet "Energiedaten", beliebiger Datumsbereich statt Kalendermonat)
 *   -- siehe eda-parser/parser_interval.py, bisher nur über /portal/eda/upload-interval von
 *   Hand hochgeladen. Am Betreff unterscheidbar (stripos($subject, 'Detailreport'), siehe
 *   processMessage()) -- braucht KEINEN eigenen Cron-Job, derselbe Postfach-Check (alle 5
 *   Minuten, siehe scripts/eda_auto_import.php) deckt jetzt beide Typen ab.
 *
 * LESESTATUS als Sichtbarkeits-Signal (Patrick, 10.10.2026): die Mail wird bereits direkt nach
 * erfolgreichem DOWNLOAD als gelesen markiert, nicht erst nach erfolgreichem Import -- so ist am
 * Lesestatus im Postfach sofort erkennbar, ob die Mail überhaupt gefunden/geöffnet wurde,
 * unabhängig davon, ob der Import danach noch an etwas anderem scheitert (Community-Zuordnung,
 * Parser). Nur wenn schon der Download selbst fehlschlägt, bleibt die Mail ungelesen. Über einen
 * Fehlschlag NACH dem Download informiert weiterhin die Alarm-Mail aus fail() -- dafür gibt es
 * dann aber keinen erneuten automatischen Versuch mehr (die Mail gilt ja als gesehen), nur noch
 * den manuellen Fallback über /portal/eda/upload(-interval).
 */
class EdaAutoImporter
{
    /** Einziger bekannter Absender echter EDA-Exportmails -- alles andere im dedizierten
     * Postfach (z.B. Zustellfehler, Antworten, Spam) wird ignoriert statt fälschlich als
     * fehlgeschlagener Import behandelt zu werden (kein Alarm, bleibt ungelesen zur manuellen
     * Durchsicht). */
    private const EDA_SENDER_ADDRESS = 'no-reply@eda.at';

    /** Wie oft ein fehlgeschlagener DOWNLOAD automatisch wiederholt wird (ein Versuch je Lauf,
     *  Lauf alle 5 Minuten -> rund eine Stunde), bevor die Mail aufgegeben wird. */
    private const MAX_DOWNLOAD_ATTEMPTS = 12;

    /**
     * Verarbeitet neue EDA-Mails im konfigurierten Postfach. Gibt eine Liste von Log-Zeilen zurück.
     *
     * Seit 14.11.2026 NICHT mehr nur ungelesene Mails (Patrick: "er holt sich die Daten nicht.
     * Er überprüft das Postfach nicht"): wer die EDA-Mail selbst öffnete oder den Download-Link
     * darin anklickte, hat sie dadurch als gelesen markiert und damit unbemerkt vom Import
     * ausgeschlossen. Jetzt werden alle Mails der letzten 7 Tage (Gültigkeit des Download-Links)
     * angesehen; welche schon erledigt sind, steht in eda_mail_processed (migrate_20261114b.sql).
     * Eine Datei, die schon importiert wurde (automatisch oder von Hand hochgeladen, gleicher
     * Dateiname), wird nicht noch einmal importiert. Ohne die Migration: altes Verhalten.
     */
    public static function run(): array
    {
        $lines = self::runChecks();
        try {
            DB::execute(
                'UPDATE platform_mail_config SET eda_import_last_run_at = now(), eda_import_last_run_result = ? WHERE id = 1',
                [mb_substr(implode("\n", $lines), 0, 4000)]
            );
        } catch (\Throwable $e) { /* Spalte fehlt (Migration noch nicht eingespielt) -- egal */ }
        return $lines;
    }

    private static function runChecks(): array
    {
        $cfg = DB::fetchOne('SELECT eda_import_mailbox_address FROM platform_mail_config WHERE id = 1');
        $mailbox = trim((string)($cfg['eda_import_mailbox_address'] ?? ''));
        if ($mailbox === '') {
            return ['EDA-Auto-Import nicht konfiguriert (Platform-Admin → Einstellungen → Postfachadresse eintragen).'];
        }

        // Nie zwei Läufe gleichzeitig (Cron alle 5 Minuten + "Jetzt prüfen"-Knopf) -- ein
        // Download + Parser-Lauf kann länger dauern als der Abstand zwischen zwei Läufen.
        $lock = @fopen(sys_get_temp_dir() . '/eeg_eda_auto_import.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['Ein anderer Postfach-Check läuft gerade noch -- übersprungen.'];
        }

        $tracking = self::trackingAvailable();
        $messages = $tracking ? GraphMailReader::listRecent($mailbox, 7) : GraphMailReader::listUnread($mailbox);
        if (!$messages) {
            return [$tracking ? "Keine Mails der letzten 7 Tage in {$mailbox}." : "Keine ungelesenen Mails in {$mailbox}."];
        }

        $state = $tracking ? self::processedState(array_column($messages, 'id')) : [];
        $log = [];
        $alreadyDone = 0;
        foreach ($messages as $msg) {
            $id = (string)($msg['id'] ?? '');
            $prev = $state[$id] ?? null;
            if ($prev !== null && ($prev['status'] !== 'download_fehler' || (int)$prev['attempts'] >= self::MAX_DOWNLOAD_ATTEMPTS)) {
                $alreadyDone++;
                continue;
            }
            // Eine einzelne Mail darf den ganzen Lauf nicht abschießen -- sonst verhindert EIN
            // unerwarteter Fehler (z.B. der RLS-Vorfall vom 10.10.2026, siehe VORFAELLE.md), dass
            // überhaupt irgendeine andere, an sich unproblematische Mail im selben Lauf verarbeitet
            // wird.
            try {
                [$status, $line] = self::processMessage($mailbox, $msg, $prev);
            } catch (\Throwable $e) {
                $subject = $msg['subject'] ?? '(ohne Betreff)';
                self::fail($mailbox, $id, $subject, 'Unerwarteter Fehler: ' . $e->getMessage());
                [$status, $line] = ['fehler', "FEHLER [{$subject}]: Unerwarteter Fehler: " . $e->getMessage()];
            }
            $log[] = $line;
            if ($tracking && $id !== '') {
                self::recordProcessed($mailbox, $msg, $status, $line);
            }
        }
        if ($alreadyDone > 0) {
            $log[] = "{$alreadyDone} Mail(s) der letzten 7 Tage bereits verarbeitet.";
        }
        return $log;
    }

    /** Gibt es die Merk-Tabelle schon (migrate_20261114b.sql)? */
    private static function trackingAvailable(): bool
    {
        try {
            DB::fetchOne('SELECT 1 FROM eda_mail_processed LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<string, array{status:string, attempts:int}> */
    private static function processedState(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if (!$ids) return [];
        $rows = DB::fetchAll(
            'SELECT message_id, status, attempts FROM eda_mail_processed WHERE message_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['message_id']] = ['status' => $r['status'], 'attempts' => (int)$r['attempts']];
        }
        return $out;
    }

    private static function recordProcessed(string $mailbox, array $msg, string $status, string $detail): void
    {
        try {
            DB::execute(
                'INSERT INTO eda_mail_processed (message_id, mailbox, subject, received_at, status, detail)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (message_id) DO UPDATE SET status = EXCLUDED.status, detail = EXCLUDED.detail,
                     attempts = eda_mail_processed.attempts + 1, processed_at = now()',
                [(string)$msg['id'], $mailbox, mb_substr((string)($msg['subject'] ?? ''), 0, 500),
                 $msg['receivedDateTime'] ?? null, $status, mb_substr($detail, 0, 2000)]
            );
        } catch (\Throwable $e) {
            error_log('[eda_auto_import] Konnte Verarbeitungsstand nicht speichern: ' . $e->getMessage());
        }
    }

    /** Wurde genau diese Exportdatei (gleicher Dateiname) schon importiert -- automatisch oder
     *  von Hand? Gespeichert wird sie jeweils als "<Präfix>_<Originalname>". */
    private static function alreadyImported(string $filename, bool $interval): bool
    {
        $table = $interval ? 'eda_interval_imports' : 'eda_imports';
        try {
            $row = DB::fetchOne(
                "SELECT 1 FROM {$table} WHERE filename = ? OR right(filename, char_length(?) + 1) = '_' || ? LIMIT 1",
                [$filename, $filename, $filename]
            );
            return $row !== null && $row !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{0:string, 1:string} [Status für eda_mail_processed, Log-Zeile] */
    private static function processMessage(string $mailbox, array $msg, ?array $prev = null): array
    {
        $subject = $msg['subject'] ?? '(ohne Betreff)';
        $id      = $msg['id'] ?? '';

        $senderAddress = strtolower(trim((string)($msg['from']['emailAddress']['address'] ?? '')));
        if ($senderAddress !== self::EDA_SENDER_ADDRESS) {
            // Bewusst KEIN fail()/Alarm-Mail -- eine Mail von irgendwem anderen im dedizierten
            // EDA-Postfach ist kein fehlgeschlagener Import, sondern schlicht nicht unsere
            // Zuständigkeit (Zustellfehler, Antwort, Spam). Bleibt ungelesen zur manuellen
            // Durchsicht, aber ohne jeden Lauf erneut eine Alarm-Mail zu verschicken.
            return ['ignoriert', "IGNORIERT [{$subject}]: Absender '{$senderAddress}' ist nicht " . self::EDA_SENDER_ADDRESS];
        }

        try {
            [$filename, $content] = self::extractFile($mailbox, $msg);
        } catch (\Throwable $e) {
            // Alarm nur beim ERSTEN Fehlversuch -- danach wird bei jedem Lauf still erneut
            // versucht (bis MAX_DOWNLOAD_ATTEMPTS), statt alle 5 Minuten eine Mail zu schicken.
            if ($prev === null) {
                self::fail($mailbox, $id, $subject, 'Datei konnte nicht ermittelt/heruntergeladen werden: ' . $e->getMessage()
                    . ' -- wird noch rund eine Stunde lang automatisch erneut versucht.');
            }
            return ['download_fehler', "FEHLER [{$subject}]: " . $e->getMessage()];
        }

        // Sobald die Datei heruntergeladen ist, gilt die Mail als "gesehen" -- unabhängig davon,
        // ob der Import danach noch an etwas anderem scheitert (Community-Zuordnung, Parser).
        // Patrick, 10.10.2026, nachdem ein erfolgreicher RLS-Fix trotzdem nicht sofort erkennbar
        // war: "Kannst du, wenn du die Datei runterlädst, auch die E-Mail auf 'gelesen' setzen?
        // Dann weiß ich, ob meine Webseite [...] auch wirklich die Datei oder die E-Mail gelesen
        // und gefunden hat." -- vorher wurde erst ganz am Ende (nach erfolgreichem Parser-Lauf)
        // als gelesen markiert, wodurch ein Fehlschlag mitten im Import (z.B. Parser-Fehler) von
        // außen nicht von "Mail wurde nie gefunden" zu unterscheiden war. Über Fehlschläge
        // danach informiert weiterhin die Alarm-Mail aus fail().
        GraphMailReader::markRead($mailbox, $id);

        $marktpartnerId = self::extractMarktpartnerId($filename);
        // Fallback/Gegenprobe: der Betreff enthält laut echter EDA-Mail ebenfalls die
        // Marktpartner-ID ("EDA Portal – Energiedatenreport RC108175") -- nützlich, falls der
        // heruntergeladene Dateiname mal nicht dem erwarteten Schema folgt, UND als Sicherheitsnetz
        // gegen einen falsch zugeordneten Export (mehrere EEGs teilen sich dasselbe Postfach).
        $subjectMarktpartnerId = preg_match('/\b([A-Z]{2}\d{4,})\b/', $subject, $sm) ? $sm[1] : null;
        if ($marktpartnerId === null) {
            $marktpartnerId = $subjectMarktpartnerId;
        } elseif ($subjectMarktpartnerId !== null && strcasecmp($marktpartnerId, $subjectMarktpartnerId) !== 0) {
            self::fail($mailbox, $id, $subject, "Marktpartner-ID aus Dateiname ('{$marktpartnerId}') und Betreff ('{$subjectMarktpartnerId}') stimmen nicht überein -- Datei: {$filename}.");
            return ['fehler', "FEHLER [{$subject}]: Marktpartner-ID-Widerspruch (Datei: {$marktpartnerId}, Betreff: {$subjectMarktpartnerId})"];
        }
        if ($marktpartnerId === null) {
            self::fail($mailbox, $id, $subject, "Marktpartner-ID weder aus Dateiname '{$filename}' noch aus dem Betreff ablesbar.");
            return ['fehler', "FEHLER [{$subject}]: Marktpartner-ID nicht ablesbar (Datei: {$filename})"];
        }

        $community = DB::fetchOne('SELECT id, slug, name FROM communities WHERE LOWER(marktpartner_id) = ?', [strtolower($marktpartnerId)]);
        if (!$community) {
            self::fail($mailbox, $id, $subject, "Keine EEG mit Marktpartner-ID '{$marktpartnerId}' gefunden (Datei: {$filename}).");
            return ['fehler', "FEHLER [{$subject}]: keine EEG für Marktpartner-ID {$marktpartnerId}"];
        }
        // Dieses Skript läuft als eigenständiger CLI-Prozess (scripts/eda_auto_import.php), nicht
        // über eine normale HTTP-Request-Lifecycle, in der app.community_id längst irgendwo gesetzt
        // worden wäre (siehe DB.php-Klassenkommentar: "Muss vor jeder mandantenspezifischen Abfrage
        // aufgerufen werden"). Ohne das hier schlägt jeder folgende community-gebundene
        // DB::execute()-Aufruf (v.a. der audit_log-INSERT unten) mit
        // "new row violates row-level security policy" fehl -- genau das Symptom, das dazu führte,
        // dass seit Einrichtung des Crons KEIN einziger Auto-Import je als gelesen markiert wurde.
        DB::setCommunity($community['id']);

        // Report-Typ am Betreff unterscheiden (siehe Klassen-Kommentar oben) -- "Detailreport"
        // braucht den Viertelstundenwerte-Parser (parser_interval.py), alles andere (der
        // reguläre monatliche "Energiedatenreport") wie bisher den Abrechnungs-Parser.
        $isIntervalReport = stripos($subject, 'Detailreport') !== false;
        $importLabel = $isIntervalReport ? 'Viertelstundenwerte-Import' : 'EDA-Import';

        // Schon importiert (z.B. von Hand hochgeladen, oder vom alten, nur-ungelesen-Abruf vor
        // dem 14.11.2026)? Dann nicht noch einmal -- erst NACH setCommunity(), damit die
        // mandantengebundene Abfrage (RLS) überhaupt etwas sieht.
        if (self::alreadyImported(basename($filename), $isIntervalReport)) {
            return ['bereits_importiert', "ÜBERSPRUNGEN [{$subject}]: {$filename} wurde bereits importiert"];
        }

        $savePath = '/var/www/html/storage/uploads/' . uniqid('eda_auto_') . '_' . basename($filename);
        file_put_contents($savePath, $content);

        // stdout (JSON) und stderr (Logzeilen) sauber getrennt -- siehe EdaParserRunner.php:
        // ein simples "2>&1" hätte json_decode() auf jedem Lauf fehlschlagen lassen, sobald der
        // Parser mindestens eine Logzeile ausgegeben hat (immer der Fall), auch bei vollem Erfolg.
        $parserResult = $isIntervalReport
            ? EdaParserRunner::runInterval($savePath, $community['slug'])
            : EdaParserRunner::run($savePath, $community['slug']);
        $result = json_decode($parserResult['stdout'], true);

        if ($result === null) {
            $diag = EdaParserRunner::diagnostics($parserResult);
            self::fail($mailbox, $id, $subject, "Parser-Fehler ({$importLabel}) für " . $community['name'] . ': ' . substr($diag, 0, 4000));
            DB::execute(
                'INSERT INTO audit_log (community_id, aktion, beschreibung, ist_fehler) VALUES (?, ?, ?, true)',
                [$community['id'], $isIntervalReport ? 'eda.interval_import_error' : 'eda.auto_import_error', "Automatischer {$importLabel} fehlgeschlagen: " . substr($diag, 0, 4000)]
            );
            return ['fehler', "FEHLER [{$subject}]: Parser-Fehler ({$importLabel}) für {$community['name']}"];
        }

        DB::execute(
            'INSERT INTO audit_log (community_id, aktion, beschreibung) VALUES (?, ?, ?)',
            [
                $community['id'],
                $isIntervalReport ? 'eda.interval_import' : 'eda.auto_import',
                "Automatischer {$importLabel} (Postfach): " . ($result['records'] ?? '?') . ' Datensätze importiert'
                    . (!empty($result['warnings']) ? ', ' . count($result['warnings']) . ' Warnung(en)' : ''),
            ]
        );
        self::notifySuccess($mailbox, $subject, $community['name'], $importLabel, $result);
        return ['importiert', "OK [{$subject}]: {$community['name']} ({$importLabel}), " . ($result['records'] ?? '?') . ' Datensätze'];
    }

    /**
     * Liefert [Dateiname, Rohinhalt]. Bevorzugt einen direkten XLSX-Anhang (Fallback-Pfad, falls
     * EDA das Format irgendwann ändert); der reguläre Fall laut echter EDA-Mail ist der
     * signierte Download-Link im HTML-Mailtext (siehe Klassen-Kommentar oben). Sucht dabei
     * gezielt nach einem Link auf die bekannte EDA-Export-Domain -- NICHT einfach den ersten
     * href im HTML nehmen, sonst könnte z.B. der Support-Link ("support.eda.at") in der
     * Mail-Signatur fälschlich erwischt werden, falls er vor dem eigentlichen Download-Link im
     * HTML-Quelltext steht. Fällt nur auf "irgendein href" zurück, wenn EDA die Export-Domain
     * mal ändert.
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
        if (preg_match('/href="(https?:\/\/[^"]*eda-portal\.at\/exports\/download\/[^"]+)"/i', $html, $m)) {
            $url = html_entity_decode($m[1]);
        } elseif (preg_match('/href="(https?:\/\/[^"]+)"/i', $html, $m)) {
            $url = html_entity_decode($m[1]);
        } else {
            throw new \RuntimeException('Weder XLSX-Anhang noch Download-Link im Mailtext gefunden.');
        }

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
        // Der echte EDA-Download-Link selbst enthält keine erkennbare Dateiendung (nur eine
        // UUID im Pfad, siehe Klassen-Kommentar) -- fehlt auch der Content-Disposition-Header
        // mal, würde die gespeicherte Datei ohne ".xlsx" enden. pandas.ExcelFile() in
        // eda-parser/parser.py bestimmt das Dateiformat aber anhand der Endung und würde dann
        // fehlschlagen, obwohl der Inhalt eine gültige XLSX-Datei ist -- deshalb hier zur
        // Sicherheit erzwingen, unabhängig davon, was der Header sagt.
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }
        return [$filename, $content];
    }

    /** Marktpartner-ID steht laut echtem EDA-Dateinamensschema vor dem ersten Unterstrich, z.B. "RC108175_...". */
    private static function extractMarktpartnerId(string $filename): ?string
    {
        return preg_match('/^([A-Za-z0-9]+)_/', $filename, $m) ? $m[1] : null;
    }

    /**
     * Empfänger für Alarm- UND Erfolgs-Mails (siehe fail()/notifySuccess()) -- dieselbe Adresse,
     * die auch der Backup-Alarm nutzt: zuerst die beiden konfigurierten Alarm-Adressen, sonst der
     * erste Platform-Admin als Fallback.
     */
    private static function alertRecipients(): array
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
        return array_unique($recipients);
    }

    /**
     * Alarm-Mail bei nicht automatisch lösbaren Fällen (Community-Zuordnung, Download, Parser).
     * Schlägt schon der Download selbst fehl, bleibt die Mail ungelesen (processMessage()
     * markiert erst NACH erfolgreichem Download als gelesen) -- alle späteren Fehler (ab
     * Community-Zuordnung) treffen eine bereits gelesene Mail, es gibt dafür keinen erneuten
     * automatischen Versuch mehr. Bewusst so: Patrick soll am Lesestatus im Postfach sofort
     * erkennen, ob die Datei überhaupt gefunden/heruntergeladen wurde (10.10.2026: "Dann weiß
     * ich, ob meine Webseite [...] auch wirklich die Datei oder die E-Mail gelesen und gefunden
     * hat") -- über den eigentlichen Fehler informiert ohnehin diese Alarm-Mail.
     */
    private static function fail(string $mailbox, string $messageId, string $subject, string $reason): void
    {
        $body = '<p><strong>Automatischer EDA-Import konnte eine Mail nicht verarbeiten.</strong></p>'
            . '<p><strong>Postfach:</strong> ' . htmlspecialchars($mailbox) . '<br>'
            . '<strong>Betreff:</strong> ' . htmlspecialchars($subject) . '<br>'
            . '<strong>Grund:</strong> ' . htmlspecialchars($reason) . '</p>'
            . '<p>Bitte bei Bedarf manuell über /portal/eda/upload bzw. /portal/eda/upload-interval '
            . '(Viertelstundenwerte) nachholen.</p>';

        foreach (self::alertRecipients() as $to) {
            try { Mailer::send($to, 'EDA-Auto-Import fehlgeschlagen: ' . $subject, $body); } catch (\Throwable $e) { /* siehe error_log */ }
        }
        error_log('[eda_auto_import] ' . $subject . ': ' . $reason);
    }

    /**
     * Bestätigungs-Mail bei JEDEM erfolgreichen Import -- unabhängig vom Report-Typ (Patrick,
     * 10.10.2026, nachdem zwei Reports tagelang unbemerkt im Postfach liegen blieben: "bei jedem
     * Report, egal welche [Betreffzeile], irgendwie [...] reinschreiben, dass ich auch weiß, dass
     * etwas passiert wurde und dass was gemacht wurde"). Bisher gab es nur bei einem FEHLER eine
     * Mail -- ein erfolgreicher Lauf war von außen nicht von "noch nicht dran gewesen" zu
     * unterscheiden.
     */
    private static function notifySuccess(string $mailbox, string $subject, string $communityName, string $importLabel, array $result): void
    {
        $records = $result['records'] ?? '?';
        $warnings = $result['warnings'] ?? [];
        $body = "<p><strong>Automatischer {$importLabel} erfolgreich.</strong></p>"
            . '<p><strong>Postfach:</strong> ' . htmlspecialchars($mailbox) . '<br>'
            . '<strong>Betreff:</strong> ' . htmlspecialchars($subject) . '<br>'
            . '<strong>EEG:</strong> ' . htmlspecialchars($communityName) . '<br>'
            . '<strong>Datensätze:</strong> ' . htmlspecialchars((string)$records) . '</p>';
        if (!empty($warnings)) {
            $body .= '<p><strong>Warnungen (' . count($warnings) . '):</strong><br>'
                . implode('<br>', array_map('htmlspecialchars', array_map('strval', $warnings))) . '</p>';
        }

        foreach (self::alertRecipients() as $to) {
            try { Mailer::send($to, "{$importLabel} erfolgreich: {$subject}", $body); } catch (\Throwable $e) { /* siehe error_log, kein harter Fehler */ }
        }
    }
}
