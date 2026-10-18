<?php

declare(strict_types=1);

/**
 * Reine (seiteneffektfreie) Hilfsfunktionen -- bewusst aus public/index.php ausgelagert,
 * damit sie ohne den Router/HTTP-Kontext geladen und automatisiert getestet werden können
 * (siehe tests/). index.php bindet diese Datei beim Start ein; die Funktionsnamen bleiben
 * global, alle bestehenden Aufrufstellen funktionieren unverändert weiter.
 */

/**
 * Liest einen JSON-Request-Body (App-API, /api/v1/*) als assoziatives Array. PHP füllt $_POST
 * NUR bei application/x-www-form-urlencoded bzw. multipart/form-data automatisch -- bei
 * application/json (das die App verwendet) bleibt $_POST leer, der Body muss selbst aus
 * php://input gelesen werden. Liefert [] statt eines Fehlers bei leerem/kaputtem JSON, damit
 * ein Endpunkt einfach per ($body['feld'] ?? '') weiterarbeiten kann.
 */
function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Rendert ein Phosphor-Icon aus dem selbst gehosteten Sprite
 * (webapp/public/assets/icons/phosphor-sprite.svg) als <svg>-Snippet. Ersetzt die früher
 * verwendeten Emojis durch ein einheitliches, professionelles Icon-Set (siehe CHANGELOG).
 *
 * Nutzt <use href="..."> statt eines Icon-Fonts oder Web-Components-Scripts -- kein externer
 * CDN-Request nötig (eine Datei, einmal vom Browser gecacht), Farbe folgt automatisch über
 * `currentColor` dem umgebenden Text (inkl. Dark Mode, ohne eigene Theming-Logik).
 *
 * @param string $name    Icon-Name ohne "ph-"-Präfix, z.B. "lightning", "check-circle".
 * @param string $classes Zusätzliche CSS-Klassen (neben der Basis-Klasse "icon").
 */
function icon(string $name, string $classes = ''): string
{
    static $v = null;
    // Cache-Busting nötig: kommt ein neues Symbol dazu (wie hier laufend), liefern Browser
    // sonst ihre gecachte alte Sprite-Datei weiter aus -- das neue Icon bliebe bis zum
    // Hard-Refresh unsichtbar. Gleiches Muster wie app.css?v=filemtime(...).
    if ($v === null) {
        $v = @filemtime(__DIR__ . '/../public/assets/icons/phosphor-sprite.svg') ?: time();
    }
    $cls = trim('icon ' . $classes);
    return '<svg class="' . htmlspecialchars($cls) . '" aria-hidden="true" focusable="false">'
        . '<use href="/assets/icons/phosphor-sprite.svg?v=' . $v . '#ph-' . htmlspecialchars($name) . '"></use>'
        . '</svg>';
}

/**
 * Verschlüsselt einen String mit AES-256-CBC (Schlüssel aus APP_SECRET). Für Werte gedacht,
 * die wieder im Klartext angezeigt werden müssen (z.B. WLAN-Passwort des Mitglieds fürs
 * ESP-Debugging, siehe docs/ESP_IDEEN.md) -- daher Verschlüsselung statt Hash. IV wird
 * zufällig erzeugt und dem Ciphertext vorangestellt (beides base64), damit decryptSecret()
 * ohne separate IV-Speicherung auskommt.
 */
function encryptSecret(string $plain): string
{
    $key = hash('sha256', getenv('APP_SECRET') ?: '', true);
    $iv  = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/**
 * Kehrt encryptSecret() um. Gibt bei ungültigen/leeren Daten '' zurück statt eines Fehlers,
 * damit ein fehlendes/kaputtes Feld die Seite nicht mit einer Exception abreißen lässt.
 */
function decryptSecret(?string $encoded): string
{
    if (!$encoded) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $key = hash('sha256', getenv('APP_SECRET') ?: '', true);
    $iv  = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : '';
}

/**
 * Liest ein TOTP-Secret aus der DB, egal ob es (alt, vor dem OWASP-Audit 13.08.2026 gespeichert)
 * noch im Klartext oder (neu bzw. nach scripts/migrate_encrypt_totp_secrets.php) verschlüsselt
 * vorliegt -- macht den Rollout unabhängig von der Reihenfolge "Code deployen" vs. "einmalige
 * Migration ausführen". Ein direktes decryptSecret() auf ein noch unverschlüsseltes Secret
 * würde es fälschlich als Base64-Ciphertext interpretieren (ein reines Base32-Secret ist rein
 * zufällig auch gültiges Base64) und mit hoher Wahrscheinlichkeit '' zurückliefern -- das hätte
 * jeden bestehenden 2FA-Nutzer sofort nach dem Deploy ausgesperrt, bis die Migration läuft.
 * Reines Base32 (nur A-Z2-7, siehe totpGenerateSecret()) => noch Klartext, unverändert
 * zurückgeben; alles andere => verschlüsselt, entschlüsseln.
 */
function totpSecretFromStorage(?string $stored): string
{
    if (!$stored) return '';
    if (preg_match('/^[A-Z2-7]+$/', $stored)) {
        return $stored;
    }
    return decryptSecret($stored);
}

/**
 * Formatiert ein Datum (bzw. einen von PostgreSQL gelieferten Zeitstempel-String, z.B. aus
 * date_trunc('month', ...)) als deutschen Monatsnamen + Jahr, z.B. "Juni 2026". Bewusst eine
 * eigene, feste Monatsnamen-Liste statt strftime() (seit PHP 8.1 deprecated) oder setlocale()
 * (braucht installierte Locale-Pakete im Container, sonst stiller Fallback auf Englisch).
 */
function monatsLabel(string $dateStr): string
{
    $monate = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return $monate[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// ─── Demo-Login: PII-Maskierung für ECHTE Mitglieder/Nutzer (Patrick, 05.09.2026: "Bei
// Plattform, Admin und Obmann auch keine personenbezogenen Daten [...] Die anderen müssen auch
// mit Sternchen ausgegraut sein, damit was personenbezogen sein kann, unkennbar oder unlesbar
// ist"). Nimmt $isDemo als expliziten Parameter statt selbst Auth::isDemo() abzufragen, damit
// diese Funktionen wie der Rest dieser Datei ohne Router/Session-Kontext testbar bleiben --
// Aufrufer übergeben Auth::isDemo(). Wirkt NIE auf fiktive Demo-Mitglieder selbst (is_demo=true
// in der Zeile) -- deren Daten sind schon komplett erfunden. Nur tatsächlich vorhandene
// Array-Schlüssel werden verändert, damit dieselbe Funktion zu unterschiedlichen
// SELECT-Spaltenlisten passt.

/** Erste $keep Zeichen sichtbar, Rest durch Punkte ersetzt (Vorname) -- Patrick: "die ersten
 *  vier Buchstaben vom Vornamen [...] Rest mit Sternchen ausfüllen". */
function demoMaskKeepStart(string $value, int $keep = 4): string
{
    if ($value === '') return $value;
    $start = mb_substr($value, 0, $keep);
    $restLen = max(3, mb_strlen($value) - mb_strlen($start));
    return $start . str_repeat('•', $restLen);
}

/** Nur die letzten $keep Zeichen sichtbar (Telefonnummer) -- Patrick: "die letzten vier Stellen
 *  von der Telefonnummer sichtbar". */
function demoMaskKeepEnd(string $value, int $keep = 4): string
{
    if ($value === '') return $value;
    $end = mb_substr($value, -$keep);
    $restLen = max(3, mb_strlen($value) - mb_strlen($end));
    return str_repeat('•', $restLen) . ' ' . $end;
}

/** Komplett unkenntlich, Länge nur grob angedeutet (max. 10 Punkte, sonst wäre die tatsächliche
 *  Länge selbst schon ein Hinweis auf den echten Wert). */
function demoMaskFull(string $value): string
{
    return str_repeat('•', min(10, max(3, mb_strlen($value))));
}

/** Maskiert die üblichen personenbezogenen Spalten eines Mitglieds-Arrays (members-Tabelle,
 *  inkl. der abgeleiteten Spalten znr_bezug/znr_einspeisung aus der Mitgliederliste). */
function demoMaskMember(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo || !empty($row['is_demo'])) return $row;
    if (array_key_exists('first_name', $row) && $row['first_name'] !== '') {
        $row['first_name'] = demoMaskKeepStart((string)$row['first_name']);
    }
    foreach ([
        'last_name', 'company_name', 'email', 'address', 'city', 'invoice_name', 'invoice_uid',
        'member_iban', 'member_bic', 'kontoinhaber', 'konto_adresse', 'mandatsreferenz',
        'znr_bezug', 'znr_einspeisung',
    ] as $f) {
        if (array_key_exists($f, $row) && $row[$f] !== null && $row[$f] !== '') {
            $row[$f] = demoMaskFull((string)$row[$f]);
        }
    }
    if (array_key_exists('phone', $row) && $row['phone']) {
        $row['phone'] = demoMaskKeepEnd((string)$row['phone']);
    }
    if (array_key_exists('geburtsdatum', $row) && $row['geburtsdatum']) {
        $row['geburtsdatum'] = '••.••.••••';
    }
    if (array_key_exists('zip', $row) && $row['zip']) {
        $row['zip'] = '••••';
    }
    if (array_key_exists('photo_path', $row)) {
        $row['photo_path'] = null; // Foto zeigt das Gesicht der echten Person -- Default-Avatar statt Maskierung.
    }
    return $row;
}

/** @param array<int,array> $rows */
function demoMaskMembers(array $rows, bool $isDemo): array
{
    return array_map(fn($r) => demoMaskMember($r, $isDemo), $rows);
}

/** Maskiert die üblichen personenbezogenen Spalten eines Login-Accounts (users-Tabelle) --
 *  fürs Platform-Admin-Nutzerverzeichnis. */
function demoMaskUser(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo || !empty($row['is_demo'])) return $row;
    if (array_key_exists('first_name', $row) && $row['first_name'] !== '') {
        $row['first_name'] = demoMaskKeepStart((string)$row['first_name']);
    }
    if (array_key_exists('last_name', $row) && $row['last_name'] !== '') {
        $row['last_name'] = demoMaskFull((string)$row['last_name']);
    }
    if (array_key_exists('email', $row) && $row['email'] !== '') {
        $row['email'] = demoMaskFull((string)$row['email']);
    }
    if (array_key_exists('phone', $row) && $row['phone']) {
        $row['phone'] = demoMaskKeepEnd((string)$row['phone']);
    }
    if (array_key_exists('photo_path', $row)) {
        $row['photo_path'] = null;
    }
    return $row;
}

/** @param array<int,array> $rows */
function demoMaskUsers(array $rows, bool $isDemo): array
{
    return array_map(fn($r) => demoMaskUser($r, $isDemo), $rows);
}

/** Maskiert die identifizierenden Spalten eines Zählpunkts (metering_points-Tabelle). */
function demoMaskMeteringPoint(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo) return $row;
    foreach (['zaehlpunkt_nr', 'meter_code', 'wifi_ssid', 'wifi_ip'] as $f) {
        if (array_key_exists($f, $row) && $row[$f] !== null && $row[$f] !== '') {
            $row[$f] = demoMaskFull((string)$row[$f]);
        }
    }
    return $row;
}

/** @param array<int,array> $rows */
function demoMaskMeteringPoints(array $rows, bool $isDemo): array
{
    return array_map(fn($r) => demoMaskMeteringPoint($r, $isDemo), $rows);
}

/**
 * Maskiert PII in Postfach-Benachrichtigungen (notifications-Tabelle) für den Demo-Zugang
 * (Patrick, 23.08.2026: "bitte auch bei Online-Beitragserklärungen die Namen unkenntlich
 * machen [...] die Zählernummer bitte unkenntlich machen, wenn eine Nachricht reinkommt, dass
 * ein neues ESP [...] Daten schickt und das ESP noch keinem Mitglied zugeordnet worden ist").
 * titel/text sind hier freier Fließtext statt eigener Spalten (siehe INSERT INTO notifications
 * in index.php bzw. notify_unknown_meter() in mqtt-subscriber/main.py) -- deshalb ein gezielter
 * Textbaustein-Ersatz je bekanntem Benachrichtigungstyp statt einer generischen Spaltenliste wie
 * bei demoMaskMember(). Unbekannte Typen bleiben unverändert (kein PII-Muster bekannt).
 */
function demoMaskNotification(array $row, bool $isDemo): array
{
    if (!$isDemo) return $row;
    if (($row['typ'] ?? null) === 'beitrittserklaerung' && !empty($row['titel'])) {
        // Format: "Neue Beitrittserklärung: Max Mustermann" -- Präfix behalten, Namen maskieren.
        $row['titel'] = preg_replace_callback(
            '/^(Neue Beitrittserklärung:\s*)(.+)$/u',
            fn($m) => $m[1] . demoMaskFull($m[2]),
            (string)$row['titel']
        );
    }
    if (($row['typ'] ?? null) === 'unbekannter_zaehler' && !empty($row['text'])) {
        // Format: "<zaehlernummer>: Ein Gerät sendet Daten für die Zählernummer ..." -- die
        // Zählernummer steht als führendes Token vor dem ersten ": " (Dedup-Muster von
        // notify_unknown_meter()), Rest des Satzes bleibt lesbar.
        $row['text'] = preg_replace_callback(
            '/^(\S+)(:\s*)/u',
            fn($m) => demoMaskFull($m[1]) . $m[2],
            (string)$row['text']
        );
    }
    return $row;
}

/** @param array<int,array> $rows */
function demoMaskNotifications(array $rows, bool $isDemo): array
{
    return array_map(fn($r) => demoMaskNotification($r, $isDemo), $rows);
}

/**
 * Maskiert personenbezogene/sensible Felder der EEG-Einstellungen (/portal/settings) für den
 * Demo-Zugang -- ZVR-Nummer und EEG-Name bleiben bewusst sichtbar (Patrick, 23.08.2026: "die
 * ZVR-Nummer darf bestehen bleiben, so wie auch der Name", Vereins-Stammdaten statt
 * personenbezogener Daten). Gläubiger-ID/Marktpartner-ID nur die ersten Zeichen sichtbar
 * (technische Kennungen), Kontoinhaber/Kontakt-E-Mail komplett unkenntlich.
 */
function demoMaskCommunitySettings(array $community, bool $isDemo): array
{
    if (!$isDemo) return $community;
    if (!empty($community['contact_email'])) {
        $community['contact_email'] = demoMaskFull((string)$community['contact_email']);
    }
    if (!empty($community['account_holder'])) {
        $community['account_holder'] = demoMaskFull((string)$community['account_holder']);
    }
    if (!empty($community['creditor_id'])) {
        $community['creditor_id'] = demoMaskKeepStart((string)$community['creditor_id'], 4);
    }
    if (!empty($community['marktpartner_id'])) {
        $community['marktpartner_id'] = demoMaskKeepStart((string)$community['marktpartner_id'], 4);
    }
    return $community;
}

/** Maskiert den Namen des eingeloggten Obmann/Kontos auf /portal/settings (Unterschrift-Bereich)
 *  für den Demo-Zugang -- bewusst nur 3 sichtbare Anfangsbuchstaben (Patrick, 23.08.2026: "mein
 *  Name: nur die ersten drei Buchstaben", abweichend vom sonst üblichen 4er-Standard). */
function demoMaskSettingsUser(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo) return $row;
    if (!empty($row['first_name'])) { $row['first_name'] = demoMaskKeepStart((string)$row['first_name'], 3); }
    if (!empty($row['last_name'])) { $row['last_name'] = demoMaskFull((string)$row['last_name']); }
    return $row;
}

/** Maskiert die UID-Nummer der Steuerkonfiguration auf /portal/settings für den Demo-Zugang --
 *  nur die ersten paar Zeichen sichtbar (Patrick, 23.08.2026: "Steuerkonfigurationen bitte ganz
 *  unkenntlich machen, auch nur die ersten 2, 3 Zeichen"). */
function demoMaskTaxConfig(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo) return $row;
    if (!empty($row['uid_number'])) { $row['uid_number'] = demoMaskKeepStart((string)$row['uid_number'], 3); }
    return $row;
}

/**
 * Maskiert personenbezogene Felder einer Beitrittserklärung (membership_applications) für den
 * Demo-Zugang -- eigene Spaltennamen (iban/bic statt member_iban/member_bic, bezug_zaehlpunkt
 * statt znr_bezug, ...), deshalb eine eigene Funktion statt demoMaskMember(). Keine
 * is_demo-Ausnahme nötig: Beitrittserklärungen gehören ausnahmslos zu echten
 * Antragsteller:innen, die beiden fiktiven Demo-Mitglieder durchlaufen diesen Workflow nie
 * (siehe create_demo_members.php). Unterschriftsbilder (Beitritt + SEPA) werden komplett
 * ausgeblendet statt maskiert, wie beim Profilbild.
 */
function demoMaskApplication(?array $row, bool $isDemo): ?array
{
    if (!$row || !$isDemo) return $row;
    if (array_key_exists('first_name', $row) && $row['first_name'] !== '') {
        $row['first_name'] = demoMaskKeepStart((string)$row['first_name']);
    }
    foreach ([
        'last_name', 'email', 'address', 'city', 'kontoinhaber', 'konto_adresse',
        'andere_eeg_name', 'iban', 'bic', 'bezug_zaehlpunkt', 'einspeisung_zaehlpunkt', 'signer_ip',
    ] as $f) {
        if (array_key_exists($f, $row) && $row[$f] !== null && $row[$f] !== '') {
            $row[$f] = demoMaskFull((string)$row[$f]);
        }
    }
    if (array_key_exists('phone', $row) && $row['phone']) {
        $row['phone'] = demoMaskKeepEnd((string)$row['phone']);
    }
    if (array_key_exists('geburtsdatum', $row) && $row['geburtsdatum']) {
        $row['geburtsdatum'] = '••.••.••••';
    }
    if (array_key_exists('zip', $row) && $row['zip']) {
        $row['zip'] = '••••';
    }
    foreach (['signature_image', 'sepa_signature_image'] as $f) {
        if (array_key_exists($f, $row)) { $row[$f] = null; }
    }
    return $row;
}

/** @param array<int,array> $rows */
function demoMaskApplications(array $rows, bool $isDemo): array
{
    return array_map(fn($r) => demoMaskApplication($r, $isDemo), $rows);
}

/**
 * Maskiert das Aktivitätslog (audit_log) für den Demo-Zugang. Der/die Handelnde
 * (first_name/last_name/email, aus users) wird wie überall über demoMaskUser() maskiert.
 * beschreibung ist dagegen freier Fließtext aus über 50 verschiedenen logAudit()-Aufrufen im
 * ganzen Code (Mitgliedernamen, E-Mails, IBANs, EEG-Namen, ...) -- ein gezielter
 * Textbaustein-Ersatz je Aufrufer wie bei demoMaskNotification() wäre hier in dieser
 * Größenordnung nicht robust zu pflegen. Deshalb bewusst komplett ausgeblendet statt einzeln zu
 * parsen -- Aktion/Objekttyp/EEG/Zeitpunkt bleiben sichtbar, nur der Freitext-Detail nicht
 * (Patrick, 23.08.2026: "Aktivitätslog [...] auch noch maskieren").
 */
function demoMaskAuditLog(array $rows, bool $isDemo): array
{
    if (!$isDemo) return $rows;
    return array_map(function (array $row) {
        $row = demoMaskUser($row, true);
        if (array_key_exists('beschreibung', $row) && $row['beschreibung'] !== null && $row['beschreibung'] !== '') {
            $row['beschreibung'] = 'Details ausgeblendet (Demo-Zugang).';
        }
        return $row;
    }, $rows);
}

/**
 * Maskiert author_label in support_ticket_messages für den Demo-Zugang (Patrick, 24.08.2026:
 * "wenn man als Admin ein Ticket [...] aufmacht, steht drinnen trotzdem immer der volle Name").
 * author_label ist freier Text (der volle Name zum Zeitpunkt des Absendens, siehe die
 * INSERT-Stellen in index.php) statt einer members-Fremdschlüssel-Spalte -- die bereits
 * bestehende Maskierung des Ticket-Headers (demoMaskMember() auf $ticket) griff deshalb nicht
 * auf die einzelnen Nachrichten im Thread durch. Alle Mitglied-Nachrichten (is_staff=false)
 * eines Tickets gehören zum selben Ticket-Mitglied, deshalb reicht ein einziges
 * $memberIsDemo-Flag (vom Ticket, nicht pro Nachricht) statt eines member_id-Lookups je
 * Nachricht. Verwaltungs-Antworten (is_staff=true, author_label = Auth::userName()) sind
 * ebenfalls ein echter Name und werden immer maskiert.
 */
function demoMaskSupportMessages(array $messages, bool $isDemo, bool $memberIsDemo): array
{
    if (!$isDemo) return $messages;
    return array_map(function (array $m) use ($memberIsDemo) {
        if ((!empty($m['is_staff']) || !$memberIsDemo) && !empty($m['author_label'])) {
            $m['author_label'] = demoMaskFull((string)$m['author_label']);
        }
        return $m;
    }, $messages);
}

/**
 * Prüft eine IBAN per Mod-97-Verfahren (ISO 7064). Erwartet die IBAN ohne
 * Leerzeichen/Kleinbuchstaben-Normalisierung durch den Aufrufer.
 */
function validateIban(string $iban): bool
{
    $iban = strtoupper(str_replace(' ', '', $iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
    }
    // Mod-97 blockweise ohne bcmath: Rest + max. 7 neue Ziffern bleibt immer < PHP_INT_MAX
    $remainder = 0;
    for ($offset = 0; $offset < strlen($numeric); $offset += 7) {
        $remainder = (int)((string)$remainder . substr($numeric, $offset, 7)) % 97;
    }
    return $remainder === 1;
}

/**
 * Prüft eine österreichische Zählpunktnummer: "AT" + 31 alphanumerische
 * Stellen = 33 Zeichen gesamt. Leerzeichen werden dabei NICHT mitgezählt (siehe
 * normalizeZaehlpunkt()) -- manche Netzbetreiber-Portale (z.B. Kelag) zeigen die Nummer mit
 * Leerzeichen zur Lesbarkeit an; kopiert ein Mitglied das 1:1, zählen die Leerzeichen sonst als
 * "Zeichen" mit und drücken bei einem längenbegrenzten Eingabefeld die letzten echten Ziffern
 * heraus (Patrick, 03.09.2026: dadurch fehlten einem Mitglied die letzten drei Stellen).
 */
function validateZaehlpunkt(string $zp): bool
{
    return (bool)preg_match('/^AT[A-Z0-9]{31}$/', normalizeZaehlpunkt($zp));
}

/** Entfernt Leerzeichen und normalisiert auf Großbuchstaben, ohne die Gültigkeit zu prüfen --
 *  gemeinsame Grundlage für validateZaehlpunkt() und den beim Speichern tatsächlich
 *  abgelegten Wert (siehe Aufrufstellen in webapp/public/index.php). */
function normalizeZaehlpunkt(string $zp): string
{
    return strtoupper(str_replace(' ', '', trim($zp)));
}

/**
 * Escaped einen String für sichere Verwendung in LaTeX-Zellwerten.
 * Nicht für RAW_-Variablen verwenden (die enthalten bereits LaTeX-Syntax).
 */
function texEscape(string $s): string
{
    return strtr($s, [
        '\\' => '\\textbackslash{}',
        '&'  => '\\&',
        '%'  => '\\%',
        '$'  => '\\$',
        '#'  => '\\#',
        '_'  => '\\_',
        '{'  => '\\{',
        '}'  => '\\}',
        '~'  => '\\textasciitilde{}',
        '^'  => '\\textasciicircum{}',
        '—'  => '--',
        '–'  => '--',
    ]);
}

/**
 * Baut die LaTeX-Tabellenzeilen für manuelle Rechnungs-Zusatzpositionen (z.B. ein einmaliger
 * Rabatt) -- als RAW_-Variable, da hier bereits fertiges LaTeX (Tabellenzeilen mit \\)
 * hineinkommt. $items: Liste von ['label' => string, 'quantity' => float, 'unit' => string,
 * 'amount_eur' => float]. Leere Liste ergibt einen leeren String (keine zusätzliche Zeile).
 *
 * 4-Spalten-Format (Position / Menge / Tarif / Betrag) passend zur aktuellen rechnung.tex:
 * Zusatzpositionen haben keine Energiemenge/keinen Tarif -> diese beiden Zellen bleiben leer.
 * Eine von 1 abweichende Stückzahl wird in den Positionstext übernommen (die 4-spaltige
 * Tabelle hat keine eigene Mengen-/Einheitenspalte mehr für Nicht-Energie-Positionen).
 */
function rechnungExtraItemsLatex(array $items): string
{
    return implode("\n", array_map(function (array $it): string {
        $amount = (float)$it['amount_eur'];
        $amountStr = rechnungBetragUnicode($amount);
        $label = (string)$it['label'];
        $qtyFloat = (float)($it['quantity'] ?? 1);
        $unit = trim((string)($it['unit'] ?? ''));
        if ($qtyFloat != 1.0 || ($unit !== '' && $unit !== 'Stk')) {
            $qty = fmod($qtyFloat, 1.0) === 0.0
                ? number_format($qtyFloat, 0, ',', '.')
                : rtrim(rtrim(number_format($qtyFloat, 3, ',', '.'), '0'), ',');
            $label .= ' (' . $qty . ($unit !== '' ? ' ' . $unit : '') . ')';
        }
        return '  ' . texEscape($label) . ' & & & ' . $amountStr . ' \\\\';
    }, $items));
}

/**
 * Baut die vorformatierten Positionszeilen (RAW_BEZUG_POSITIONEN_LISTE bzw.
 * RAW_EINSPEISUNG_POSITIONEN_LISTE) für rechnung.tex -- eine Zeile PRO Zählpunkt. Jede Zeile
 * zeigt den Produktnamen und, falls eine Zählpunktnummer vorliegt, darunter (via \newline,
 * NICHT \\ -- sonst bricht die Tabellenzeile um) die 33-stellige Zählpunktnummer als kleine
 * graue Zweitzeile. Mehrere Zeilen werden mit einer feinen Trennlinie verbunden (nicht nach
 * der letzten -- die setzt die Vorlage selbst zwischen Bezugs- und Einspeisungsblock).
 * $items: invoice_items eines Typs (bezug oder einspeisung), jeweils mit optionalem
 * 'teilnahme_ab' (Y-m-d) -- PRO ZÄHLPUNKT aus dem echten EDA-Teilnahmezeitraum (siehe
 * Billing::generateDrafts(), Spalte "Zeitraum" der EDA-Gesamtübersicht), NICHT aus dem
 * Beitrittsdatum des Mitglieds: Bezugs- und Einspeise-Zählpunkt desselben Mitglieds können
 * unterschiedliche tatsächliche Start-Zeitpunkte haben (Patrick, 18.10.2026). Nur gesetzt, wenn
 * SPÄTER als der Zeitraumbeginn (z.B. unterjähriger Beitritt) -- wird dann als zusätzliche Zeile
 * unter dem jeweiligen Zählpunkt angezeigt. $gutschrift=true stellt dem Betrag ein Minuszeichen
 * voran (Einspeisung wird von der Summe abgezogen).
 */
function rechnungPositionenLatex(array $items, string $label, bool $gutschrift): string
{
    $zeilen = array_map(function (array $it) use ($label, $gutschrift): string {
        $cell = $label;
        $zp = trim((string)($it['zaehlpunkt_nr'] ?? ''));
        if ($zp !== '') {
            $cell .= '\\newline{\\footnotesize\\color{midgray}Zählpunkt: ' . texEscape($zp) . '}';
        }
        $teilnahmeAb = $it['teilnahme_ab'] ?? null;
        if ($teilnahmeAb !== null) {
            $cell .= '\\newline{\\footnotesize\\color{midgray}Teilnahme ab ' . date('d.m.Y', strtotime($teilnahmeAb)) . '}';
        }
        $kwh    = number_format((float)$it['kwh'], 2, ',', '.');
        $tarif  = number_format((float)$it['rate_ct_kwh'], 4, ',', '.');
        $betrag = rechnungBetragUnicode($gutschrift ? -abs((float)$it['amount_eur']) : abs((float)$it['amount_eur']));
        return '  ' . $cell . ' & ' . $kwh . ' & ' . $tarif . ' & ' . $betrag . ' \\\\';
    }, $items);
    return implode("\n  \\arrayrulecolor{rulegray}\\hline\n", $zeilen);
}

/**
 * Bereinigt einen String für SEPA-pain.001/pain.008-XML-Textfelder (EndToEndId, MsgId, PmtInfId,
 * MndtId, Namen, Verwendungszweck) auf den von SEPA erlaubten "Latin Character Set" --
 * `a-z A-Z 0-9 / - ? : ( ) . , ' +` und Leerzeichen. Ohne das weist die Bank die GESAMTE Datei
 * zurück, sobald auch nur EIN Feld ein unerlaubtes Zeichen enthält (Vorfall 18.10.2026:
 * "RE-260007_RC108175" mit Unterstrich in der EndToEndId -- George/Sparkasse lehnte die komplette
 * Gutschriften-Datei ab).
 *
 * Reihenfolge wichtig:
 * 1. Deutsche Umlaute/ß explizit ausgeschrieben (ä→ae, ß→ss, ...) -- eine reine Diakritik-Tabelle
 *    würde nur die Pünktchen/den Bogen entfernen ("Höfferer" → "Hofferer" statt korrekt
 *    "Hoefferer"), außerdem Unterstrich → Bindestrich (siehe Vorfall oben).
 * 2. Weitere diakritische Zeichen (é, ñ, ç, č, …) über eine fest hinterlegte Tabelle
 *    (Latin-1 Supplement + Latin Extended-A) transliteriert -- BEWUSST nicht per `iconv(...,
 *    'ASCII//TRANSLIT', ...)`: Alpine/musl (das Basis-Image von `webapp`, siehe
 *    `webapp/Dockerfile`) unterstützt `//TRANSLIT` anders als glibc nur unzuverlässig/gar nicht,
 *    eine selbst hinterlegte Tabelle funktioniert dagegen identisch auf jedem System.
 * 3. Alles danach noch verbleibende, nicht erlaubte Zeichen (Emoji, Sonderzeichen jeder Art)
 *    wird durch ein Leerzeichen ersetzt statt einfach entfernt, damit z.B. "Müller&Co" nicht zu
 *    "MllerCo" verschmilzt -- mehrfache Leerzeichen werden danach zusammengefasst.
 *
 * $maxLen kürzt NACH der Bereinigung (nicht abbrechen/werfen) -- SEPA-Felder haben feste
 * Längengrenzen (EndToEndId/MsgId/PmtInfId 35, Verwendungszweck 140, Namen 70), eine zu lange
 * Rechnungsnummer/ein zu langer Name soll nicht die ganze Datei scheitern lassen.
 *
 * $isIdField=true wendet zusätzlich die SEPA-Regeln für Id-Felder an (EndToEndId/MsgId/
 * PmtInfId/MndtId, NICHT Namen/Verwendungszweck): kein "/" am Anfang/Ende, kein "//".
 */
function sepaSanitize(string $s, int $maxLen = 140, bool $isIdField = false): string
{
    static $translitMap = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I',
        'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ý' => 'Y', 'Þ' => 'Th',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
        'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
        'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a', 'Ć' => 'C',
        'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c', 'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c',
        'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd', 'Ē' => 'E', 'ē' => 'e', 'Ĕ' => 'E',
        'ĕ' => 'e', 'Ė' => 'E', 'ė' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e',
        'Ĝ' => 'G', 'ĝ' => 'g', 'Ğ' => 'G', 'ğ' => 'g', 'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G',
        'ģ' => 'g', 'Ĥ' => 'H', 'ĥ' => 'h', 'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i',
        'Ĭ' => 'I', 'ĭ' => 'i', 'Į' => 'I', 'į' => 'i', 'İ' => 'I', 'Ĵ' => 'J', 'ĵ' => 'j',
        'Ķ' => 'K', 'ķ' => 'k', 'Ĺ' => 'L', 'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L',
        'ľ' => 'l', 'Ł' => 'L', 'ł' => 'l', 'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n',
        'Ň' => 'N', 'ň' => 'n', 'Ō' => 'O', 'ō' => 'o', 'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'O',
        'ő' => 'o', 'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r',
        'Ś' => 'S', 'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S',
        'š' => 's', 'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't', 'Ũ' => 'U', 'ũ' => 'u',
        'Ū' => 'U', 'ū' => 'u', 'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U', 'ů' => 'u', 'Ű' => 'U',
        'ű' => 'u', 'Ų' => 'U', 'ų' => 'u', 'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y', 'ŷ' => 'y',
        'Ÿ' => 'Y', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
    ];

    $s = strtr($s, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        '_' => '-',
    ]);
    $s = strtr($s, $translitMap);
    $s = preg_replace('/[^a-zA-Z0-9\/\-?:().,\'+ ]/', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($isIdField) {
        $s = trim($s, '/');
        $s = preg_replace('/\/{2,}/', '/', $s);
    }
    return mb_substr($s, 0, $maxLen);
}

/**
 * Baut den optionalen <PstlAdr>-Block (strukturierte Postadresse) für ein Dbtr-/Cdtr-/
 * InitgPty-Element einer SEPA-XML-Datei. Hintergrund: das EPC-Regelwerk verlangt ab einem
 * Stichtag (ursprünglich 15.11.2026 für pain.001/pain.008, vom EPC im September 2026 verschoben,
 * neuer Termin laut EPC-Ankündigung für Oktober 2026 angekündigt, Stand dieses Codes noch nicht
 * bekannt -- https://www.europeanpaymentscouncil.eu, Guidance Document EPC153-22) für Dbtr/Cdtr
 * entweder eine vollständig strukturierte ODER eine "hybride" Adresse (mindestens TwnNm + Ctry,
 * zusätzlich optional freie AdrLine-Textzeilen) -- eine rein unstrukturierte Adresse (nur freie
 * Textzeilen ohne TwnNm/Ctry) wird die Bank ab diesem Stichtag zurückweisen, SOBALD überhaupt
 * eine Adresse mitgeschickt wird (fehlt sie ganz, ist das weiterhin unproblematisch).
 *
 * Elementreihenfolge laut XSD (PostalAddress6 in pain.001.001.03, PostalAddress24 in
 * pain.008.001.08 UND im künftigen pain.001.001.09 -- identische Reihenfolge für die hier
 * verwendeten Felder): StrtNm, BldgNb, PstCd, TwnNm, Ctry, AdrLine. Bewusst OHNE BldgNb/AdrLine:
 * die Hausnummer steckt in unseren Adressdaten untrennbar in $street (z.B. "Hauptstraße 12a"),
 * ein verlässliches Auftrennen wäre fehleranfällig und ist laut EPC-Leitfaden nur EMPFOHLEN,
 * nicht vorgeschrieben -- StrtNm/PstCd/TwnNm/Ctry allein gelten bereits als strukturiert.
 *
 * Land ist mangels eigenem DB-Feld hart auf "AT" gesetzt -- reine Inlands-Plattform, ebenso wie
 * z.B. der Banktag-/Feiertagskalender (bankingDayBlockReasonAT()) österreichspezifisch ist.
 *
 * @return string '' (kein <PstlAdr>-Block), falls $city leer ist -- sonst unvollständige/leere
 *         Adresselemente, die mehr schaden als nützen.
 */
function sepaPostalAddressXml(string $street, string $zip, string $city, string $country = 'AT'): string
{
    $city = trim($city);
    if ($city === '') { return ''; }
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = '<PstlAdr>';
    $street = trim($street);
    if ($street !== '') {
        $xml .= '<StrtNm>' . $x(sepaSanitize($street, 70)) . '</StrtNm>';
    }
    $zip = trim($zip);
    if ($zip !== '') {
        $xml .= '<PstCd>' . $x(sepaSanitize($zip, 16)) . '</PstCd>';
    }
    $xml .= '<TwnNm>' . $x(sepaSanitize($city, 35)) . '</TwnNm>';
    $xml .= '<Ctry>' . $x(strtoupper(trim($country))) . '</Ctry>';
    return $xml . '</PstlAdr>';
}

/**
 * Zerlegt die EEG-Adresse (communities.address, ein einzelnes Freitextfeld, z.B.
 * "Hauptstraße 5, 9020 Klagenfurt") in Straße/PLZ/Ort für die strukturierte SEPA-Postadresse
 * (siehe sepaPostalAddressXml()). Anders als der Rechnungs-PDF-Footer (invoicePdfPayload(),
 * zeigt PLZ+Ort dort weiterhin als EINE Zeile) braucht SEPA PLZ und Ort als getrennte Felder.
 *
 * @return array{street: string, zip: string, city: string} leere Strings, falls $address leer
 *         ist oder das erwartete Format (Komma, danach eine 4-5-stellige PLZ) nicht erkannt wird
 *         -- dann landet der komplette Rest in 'city' (besser eine unvollständig strukturierte
 *         als eine falsch aufgeteilte Adresse).
 */
function splitEegAddress(?string $address): array
{
    $parts = array_map('trim', explode(',', (string)$address, 2));
    $street = $parts[0] ?? '';
    $rest = $parts[1] ?? '';
    if (preg_match('/^(\d{4,5})\s+(.*)$/', $rest, $m)) {
        return ['street' => $street, 'zip' => $m[1], 'city' => trim($m[2])];
    }
    return ['street' => $street, 'zip' => '', 'city' => $rest];
}

/**
 * Erzeugt eine SEPA-Basislastschrift (SDD CORE) als pain.008-XML — die Datei, die der Obmann in
 * sein Online-Banking hochlädt. Version umschaltbar: '08' = pain.008.001.08 (neu, Standard),
 * '02' = pain.008.001.02 (alt). Reine Funktion (kein DB/HTTP) -> automatisiert testbar.
 *
 * WICHTIG (Compliance): Das Zielformat muss zu dem passen, was die konkrete Bank akzeptiert.
 * Vor dem Echtbetrieb die erzeugte Datei mit dem Prüf-/Testtool der Bank validieren.
 *
 * @param array $creditor ['name','iban','bic'(optional),'creditor_id','street'(optional),
 *                        'zip'(optional),'city'(optional)] -- street/zip/city bauen den
 *                        strukturierten <PstlAdr>-Block (siehe sepaPostalAddressXml()), fehlen
 *                        sie, bleibt der Block einfach weg.
 * @param array $txns Liste von ['end_to_end_id','amount'(float>0),'mandate_ref','mandate_date'(Y-m-d),
 *                    'debtor_name','debtor_iban','debtor_bic'(optional),'remittance',
 *                    'debtor_street'(optional),'debtor_zip'(optional),'debtor_city'(optional)]
 * @param string $collectionDate Fälligkeit/Einzugsdatum (Y-m-d)
 * @param string $version '08' oder '02'
 * @param string $seqType SEPA-Sequenztyp: FRST | RCUR | OOFF | FNAL
 * @param string $msgId eindeutige Nachrichten-ID (Default: generiert)
 * @throws InvalidArgumentException wenn ein Mandat ein Unterschriftsdatum (mandate_date) in der
 *         Zukunft hat -- ein SEPA-Mandat kann nicht vor seiner eigenen Unterschrift gültig
 *         werden, das ist immer ein Dateneingabe-Fehler und würde von der Bank ohnehin
 *         zurückgewiesen. Geprüft hier (statt nur aufrufseitig), weil diese Funktion die einzige
 *         Stelle ist, die JEDER Aufrufer zwangsläufig durchläuft.
 */
function sepaPain008Xml(
    array $creditor, array $txns, string $collectionDate,
    string $version = '08', string $seqType = 'RCUR', string $msgId = ''
): string {
    $today = date('Y-m-d');
    foreach ($txns as $t) {
        $mandateDate = (string)($t['mandate_date'] ?? '');
        if ($mandateDate !== '' && $mandateDate > $today) {
            throw new InvalidArgumentException(
                'Mandatsunterschrift von ' . ($t['debtor_name'] ?? '?') . ' liegt in der Zukunft ('
                . $mandateDate . ') -- bitte das Unterschriftsdatum prüfen, bevor die SEPA-Datei '
                . 'erzeugt wird.'
            );
        }
    }

    $version = $version === '02' ? '02' : '08';
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    // Id-Felder (max. 35 Zeichen) vs. Namen (70) vs. Verwendungszweck (140) -- siehe
    // sepaSanitize()-Docblock für die genauen SEPA-Zeichensatz-/Längenregeln.
    $sId   = fn($s) => sepaSanitize((string)$s, 35, true);
    $sName = fn($s) => sepaSanitize((string)$s, 70);
    $sRmt  = fn($s) => sepaSanitize((string)$s, 140);
    $money = fn($v) => number_format((float)$v, 2, '.', '');
    $ns = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.' . $version;
    // BIC-Element heißt in .02 <BIC>, in der 2019er-Fassung .08 <BICFI>.
    $bicTag = $version === '02' ? 'BIC' : 'BICFI';
    $agent = function (?string $bic) use ($x, $bicTag): string {
        $bic = trim((string)$bic);
        return $bic !== ''
            ? "<FinInstnId><{$bicTag}>" . $x($bic) . "</{$bicTag}></FinInstnId>"
            : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
    };

    // Max. 33 statt 35 Zeichen, damit die abgeleitete PmtInfId ("<MsgId>-1") selbst nach Anhängen
    // von "-1" noch innerhalb der 35-Zeichen-Grenze bleibt.
    $msgId  = sepaSanitize($msgId !== '' ? $msgId : ('SFA-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6)), 33, true);
    $creDt  = date('Y-m-d\TH:i:s');
    $nbTx   = count($txns);
    $ctrl   = $money(array_sum(array_map(fn($t) => (float)$t['amount'], $txns)));
    // Strukturierte Postadresse (siehe sepaPostalAddressXml()) -- Gläubiger-Adresse ist für
    // InitgPty UND Cdtr identisch, einmal berechnen statt zweimal.
    $creditorAddr = sepaPostalAddressXml($creditor['street'] ?? '', $creditor['zip'] ?? '', $creditor['city'] ?? '');

    $txXml = '';
    foreach ($txns as $t) {
        $debtorAddr = sepaPostalAddressXml($t['debtor_street'] ?? '', $t['debtor_zip'] ?? '', $t['debtor_city'] ?? '');
        $txXml .=
            '<DrctDbtTxInf>'
          // EndToEndId ist laut ISO-20022-Schema (pain.008) auf 35 Zeichen begrenzt -- die
          // Rechnungsnummer (seit 06.08.2026 inkl. Marktpartner-ID + Nach-/Vorname, siehe
          // Billing::generateDrafts()) kann bei längeren Namen darüber liegen; sepaSanitize()
          // kürzt UND entfernt dabei zugleich nicht erlaubte Zeichen (z.B. den Unterstrich vor
          // der Marktpartner-ID, Vorfall 18.10.2026) -- ohne beides würde die Bank die ganze
          // SEPA-Datei ablehnen.
          .   '<PmtId><EndToEndId>' . $x($sId($t['end_to_end_id'] ?? 'NOTPROVIDED')) . '</EndToEndId></PmtId>'
          .   '<InstdAmt Ccy="EUR">' . $money($t['amount']) . '</InstdAmt>'
          .   '<DrctDbtTx><MndtRltdInf>'
          .     '<MndtId>' . $x($sId($t['mandate_ref'])) . '</MndtId>'
          .     '<DtOfSgntr>' . $x($t['mandate_date']) . '</DtOfSgntr>'
          .   '</MndtRltdInf></DrctDbtTx>'
          .   '<DbtrAgt>' . $agent($t['debtor_bic'] ?? '') . '</DbtrAgt>'
          .   '<Dbtr><Nm>' . $x($sName($t['debtor_name'])) . '</Nm>' . $debtorAddr . '</Dbtr>'
          .   '<DbtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $t['debtor_iban'])) . '</IBAN></Id></DbtrAcct>'
          .   '<RmtInf><Ustrd>' . $x($sRmt($t['remittance'] ?? '')) . '</Ustrd></RmtInf>'
          . '</DrctDbtTxInf>';
    }

    $xml =
        '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Document xmlns="' . $ns . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<CstmrDrctDbtInitn>'
      .   '<GrpHdr>'
      .     '<MsgId>' . $x($msgId) . '</MsgId>'
      .     '<CreDtTm>' . $creDt . '</CreDtTm>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<InitgPty><Nm>' . $x($sName($creditor['name'])) . '</Nm>' . $creditorAddr . '</InitgPty>'
      .   '</GrpHdr>'
      .   '<PmtInf>'
      .     '<PmtInfId>' . $x($msgId . '-1') . '</PmtInfId>'
      .     '<PmtMtd>DD</PmtMtd>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm>'
      .       '<SeqTp>' . $x($seqType) . '</SeqTp></PmtTpInf>'
      .     '<ReqdColltnDt>' . $x($collectionDate) . '</ReqdColltnDt>'
      .     '<Cdtr><Nm>' . $x($sName($creditor['name'])) . '</Nm>' . $creditorAddr . '</Cdtr>'
      .     '<CdtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $creditor['iban'])) . '</IBAN></Id></CdtrAcct>'
      .     '<CdtrAgt>' . $agent($creditor['bic'] ?? '') . '</CdtrAgt>'
      .     '<ChrgBr>SLEV</ChrgBr>'
      .     '<CdtrSchmeId><Id><PrvtId><Othr><Id>' . $x($sId($creditor['creditor_id'])) . '</Id>'
      .       '<SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>'
      .     $txXml
      .   '</PmtInf>'
      . '</CstmrDrctDbtInitn></Document>';

    return $xml;
}

/**
 * Erzeugt eine SEPA-Sammelüberweisung (Credit Transfer) als pain.001.001.03-XML -- die Datei,
 * die der Obmann in sein Online-Banking hochlädt, um alle offenen Gutschriften auf einmal statt
 * einzeln von Hand zu überweisen (siehe /portal/billing/gutschriften/sepa-xml). pain.001.001.03
 * ist bewusst die einzige unterstützte Version (anders als bei der Lastschrift keine '02'/'08'-
 * Auswahl) -- sie ist die von öst. Banken (u.a. Sparkasse/George Business) am breitesten
 * akzeptierte SEPA-Credit-Transfer-Version. Reine Funktion (kein DB/HTTP) -> automatisiert testbar.
 *
 * WICHTIG (Compliance): wie bei sepaPain008Xml() -- vor dem ersten echten Einreichen die Datei
 * mit dem Prüf-/Testtool der eigenen Bank validieren.
 *
 * @param array $payer ['name','iban','bic'(optional),'street'(optional),'zip'(optional),
 *                     'city'(optional)] -- das Konto, von dem überwiesen wird (EEG). street/zip/
 *                     city bauen den strukturierten <PstlAdr>-Block (siehe sepaPostalAddressXml()).
 * @param array $txns Liste von ['end_to_end_id','amount'(float>0),'creditor_name','creditor_iban',
 *                    'creditor_bic'(optional),'remittance','creditor_street'(optional),
 *                    'creditor_zip'(optional),'creditor_city'(optional)]
 * @param string $executionDate gewünschtes Ausführungsdatum (Y-m-d)
 * @param string $msgId eindeutige Nachrichten-ID (Default: generiert)
 */
function sepaPain001Xml(array $payer, array $txns, string $executionDate, string $msgId = ''): string
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $sId   = fn($s) => sepaSanitize((string)$s, 35, true);
    $sName = fn($s) => sepaSanitize((string)$s, 70);
    $sRmt  = fn($s) => sepaSanitize((string)$s, 140);
    $money = fn($v) => number_format((float)$v, 2, '.', '');
    $ns = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';
    $agent = function (?string $bic) use ($x): string {
        $bic = trim((string)$bic);
        return $bic !== ''
            ? '<FinInstnId><BIC>' . $x($bic) . '</BIC></FinInstnId>'
            : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
    };

    // Max. 33 statt 35 Zeichen, damit die abgeleitete PmtInfId ("<MsgId>-1") selbst nach Anhängen
    // von "-1" noch innerhalb der 35-Zeichen-Grenze bleibt.
    $msgId = sepaSanitize($msgId !== '' ? $msgId : ('SFA-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6)), 33, true);
    $creDt = date('Y-m-d\TH:i:s');
    $nbTx  = count($txns);
    $ctrl  = $money(array_sum(array_map(fn($t) => (float)$t['amount'], $txns)));
    // Strukturierte Postadresse (siehe sepaPostalAddressXml()) -- Zahler-Adresse ist für InitgPty
    // UND Dbtr identisch, einmal berechnen statt zweimal.
    $payerAddr = sepaPostalAddressXml($payer['street'] ?? '', $payer['zip'] ?? '', $payer['city'] ?? '');

    $txXml = '';
    foreach ($txns as $t) {
        $creditorAddr = sepaPostalAddressXml($t['creditor_street'] ?? '', $t['creditor_zip'] ?? '', $t['creditor_city'] ?? '');
        $txXml .=
            '<CdtTrfTxInf>'
          // EndToEndId auf 35 Zeichen begrenzt, gleiche Schema-Grenze wie bei pain.008 -- siehe
          // sepaSanitize()-Docblock (Vorfall 18.10.2026: Unterstrich in der Rechnungsnummer
          // wurde von George/Sparkasse abgelehnt).
          .   '<PmtId><EndToEndId>' . $x($sId($t['end_to_end_id'] ?? 'NOTPROVIDED')) . '</EndToEndId></PmtId>'
          .   '<Amt><InstdAmt Ccy="EUR">' . $money($t['amount']) . '</InstdAmt></Amt>'
          .   '<CdtrAgt>' . $agent($t['creditor_bic'] ?? '') . '</CdtrAgt>'
          .   '<Cdtr><Nm>' . $x($sName($t['creditor_name'])) . '</Nm>' . $creditorAddr . '</Cdtr>'
          .   '<CdtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $t['creditor_iban'])) . '</IBAN></Id></CdtrAcct>'
          .   '<RmtInf><Ustrd>' . $x($sRmt($t['remittance'] ?? '')) . '</Ustrd></RmtInf>'
          . '</CdtTrfTxInf>';
    }

    return
        '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Document xmlns="' . $ns . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<CstmrCdtTrfInitn>'
      .   '<GrpHdr>'
      .     '<MsgId>' . $x($msgId) . '</MsgId>'
      .     '<CreDtTm>' . $creDt . '</CreDtTm>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<InitgPty><Nm>' . $x($sName($payer['name'])) . '</Nm>' . $payerAddr . '</InitgPty>'
      .   '</GrpHdr>'
      .   '<PmtInf>'
      .     '<PmtInfId>' . $x($msgId . '-1') . '</PmtInfId>'
      .     '<PmtMtd>TRF</PmtMtd>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf>'
      .     '<ReqdExctnDt>' . $x($executionDate) . '</ReqdExctnDt>'
      .     '<Dbtr><Nm>' . $x($sName($payer['name'])) . '</Nm>' . $payerAddr . '</Dbtr>'
      .     '<DbtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $payer['iban'])) . '</IBAN></Id></DbtrAcct>'
      .     '<DbtrAgt>' . $agent($payer['bic'] ?? '') . '</DbtrAgt>'
      .     '<ChrgBr>SLEV</ChrgBr>'
      .     $txXml
      .   '</PmtInf>'
      . '</CstmrCdtTrfInitn></Document>';
}

/**
 * Datum des Ostersonntags für ein gregorianisches Jahr nach dem Gauß/Meeus-Algorithmus
 * (Meeus/Jones/Butcher-Variante) -- bewusst selbst berechnet statt über eine externe API oder
 * PHP's eigene easter_date() (die von der optionalen "calendar"-Erweiterung abhängt, welche im
 * webapp-Image bisher nicht installiert ist -- dieselbe Klasse von Problem wie zuvor bei
 * `zip`/`iconv`, siehe sepaSanitize()). Alle übrigen beweglichen Feiertage/TARGET2-Schließtage
 * (Karfreitag, Ostermontag, Christi Himmelfahrt, Pfingstmontag, Fronleichnam) ergeben sich als
 * fester Tagesabstand zu diesem Datum. Gegen PHPs easter_date() für 2020-2035 verifiziert
 * (identisch in jedem Jahr).
 */
function easterSunday(int $year): string
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Grund, warum ein Datum KEIN Bankarbeitstag ist (für Tooltip/Anzeige im
 * Ausführungsdatum-Modal, siehe /portal/billing/:id/sepa-xml), oder null, wenn es einer ist.
 * Deckt Wochenenden, österreichische gesetzliche Feiertage und TARGET2-Schließtage ab.
 * Karfreitag ist in Österreich KEIN gesetzlicher Feiertag (anders als z.B. in Deutschland), aber
 * ein TARGET2-Schließtag -- SEPA-Zahlungen laufen an diesem Tag trotzdem nicht. Bewusst
 * länderspezifisch benannt (nur AT) -- bei Bedarf um einen $country-Parameter erweiterbar,
 * aktuell nicht nötig (die Plattform kennt nur österreichische Energiegemeinschaften).
 */
function bankingDayBlockReasonAT(string $date): ?string
{
    $d = new DateTimeImmutable($date);
    $dow = (int)$d->format('N'); // 1=Montag .. 7=Sonntag
    if ($dow === 6) return 'Samstag';
    if ($dow === 7) return 'Sonntag';

    $fixedHolidays = [
        '01-01' => 'Neujahr',
        '01-06' => 'Heilige Drei Könige',
        '05-01' => 'Staatsfeiertag',
        '08-15' => 'Mariä Himmelfahrt',
        '10-26' => 'Nationalfeiertag',
        '11-01' => 'Allerheiligen',
        '12-08' => 'Mariä Empfängnis',
        '12-25' => 'Christtag',
        '12-26' => 'Stefanitag',
    ];
    $md = $d->format('m-d');
    if (isset($fixedHolidays[$md])) return $fixedHolidays[$md];

    $easter = new DateTimeImmutable(easterSunday((int)$d->format('Y')));
    $movableHolidays = [
        $easter->modify('-2 days')->format('Y-m-d')  => 'Karfreitag',
        $easter->modify('+1 day')->format('Y-m-d')   => 'Ostermontag',
        $easter->modify('+39 days')->format('Y-m-d') => 'Christi Himmelfahrt',
        $easter->modify('+50 days')->format('Y-m-d') => 'Pfingstmontag',
        $easter->modify('+60 days')->format('Y-m-d') => 'Fronleichnam',
    ];
    return $movableHolidays[$d->format('Y-m-d')] ?? null;
}

/** true, wenn an diesem Tag SEPA-Zahlungen tatsächlich ausgeführt werden (siehe bankingDayBlockReasonAT()). */
function isBankingDay(string $date): bool
{
    return bankingDayBlockReasonAT($date) === null;
}

/** Nächster Bankarbeitstag NACH (exklusive) $date. */
function nextBankingDay(string $date): string
{
    $d = new DateTimeImmutable($date);
    do {
        $d = $d->modify('+1 day');
    } while (!isBankingDay($d->format('Y-m-d')));
    return $d->format('Y-m-d');
}

/** Geht von $date aus genau $n Bankarbeitstage weiter (jeder Schritt überspringt Wochenenden/
 *  Feiertage) -- z.B. für "mindestens 2 Bankarbeitstage nach heute" bei der
 *  Ausführungsdatum-Auswahl, falls das reguläre früheste Datum bereits in der Vergangenheit liegt. */
function addBankingDays(string $date, int $n): string
{
    $d = $date;
    for ($i = 0; $i < $n; $i++) {
        $d = nextBankingDay($d);
    }
    return $d;
}

// SEPA-Fristen als benannte Konstanten statt Magic Numbers -- an einer Stelle änderbar, falls
// sich die Vorgaben der Bank/des Kreditinstituts je ändern sollten.
// SEPA-Core-Lastschrift: Pre-Notification (Vorabankündigung) muss mindestens 14 Kalendertage vor
// dem Einzug erfolgen.
const SEPA_PRENOTIFICATION_DAYS = 14;
// Die Gutschrift-Überweisung (pain.001) braucht KEINE Vorabankündigungsfrist -- anders als die
// Lastschrift (die den Zahler vor dem Einzug von seinem eigenen Konto schützen soll) zahlt die
// EEG hier selbst aus, kann das also ab dem gewünschten Bankarbeitstag sofort tun (Patrick,
// 18.10.2026: "ich kann es ja gleich ab heute überweisen ... brauche keine zwei Wochen zu
// warten"). Statt eines Kalenderfensters mit einzelnen gesperrten Tagen (wie bei der Lastschrift)
// zeigt das Auswahl-Modal hier direkt diese Anzahl tatsächlich wählbarer Bankarbeitstage ab heute.
const SEPA_TRANSFER_TILE_COUNT = 20;

/**
 * Berechnet den 8-Tage-Anzeigezeitraum für die Ausführungsdatum-Auswahl im SEPA-Lastschrift-
 * Download-Modal (pain.008, SEPA_PRENOTIFICATION_DAYS) -- siehe Aufgabe 18.10.2026. Für die
 * Gutschrift-Überweisung (pain.001, keine Vorabankündigungsfrist nötig) siehe stattdessen
 * bankingDayTiles() weiter unten.
 *
 * Normalfall: frühestes Datum = $invoiceDate + $minDays, spätestes = frühestes + 7 Tage (8 Tage
 * insgesamt). Liegt das früheste Datum bereits in der Vergangenheit (der Abrechnungslauf wurde
 * erst spät heruntergeladen), beginnt der Zeitraum stattdessen beim nächsten gültigen
 * Bankarbeitstag, der mindestens 2 Bankarbeitstage nach $today liegt (SEPA-Core: Einreichung
 * mindestens 1 Bankarbeitstag vor Ausführung) -- das Ende bleibt weiterhin +7 Tage ab diesem
 * tatsächlichen Beginn.
 *
 * @param string $invoiceDate Rechnungsdatum/Freigabedatum des Abrechnungslaufs (Y-m-d)
 * @param int $minDays SEPA_PRENOTIFICATION_DAYS
 * @param string $today "heute" (Y-m-d) -- als Parameter statt date('Y-m-d') direkt, damit die
 *        Funktion ohne Systemzeit-Abhängigkeit automatisiert testbar ist.
 * @return array{range_start: string, range_end: string, earliest: string} range_start/range_end
 *         sind die 8 im Modal angezeigten Kalendertage (inklusive beider Enden); earliest ist
 *         das erste davon tatsächlich WÄHLBARE (Bankarbeits-)Datum, für die Vorauswahl im Modal.
 */
function sepaExecutionDateRange(string $invoiceDate, int $minDays, string $today): array
{
    $earliestRegular = date('Y-m-d', strtotime($invoiceDate . " +{$minDays} days"));
    if ($earliestRegular < $today) {
        $rangeStart = addBankingDays($today, 2);
    } else {
        $rangeStart = $earliestRegular;
    }
    $rangeEnd = date('Y-m-d', strtotime($rangeStart . ' +7 days'));
    $earliest = isBankingDay($rangeStart) ? $rangeStart : nextBankingDay($rangeStart);
    if ($earliest > $rangeEnd) { $earliest = $rangeEnd; } // defensive Absicherung, in der Praxis nie nötig
    return ['range_start' => $rangeStart, 'range_end' => $rangeEnd, 'earliest' => $earliest];
}

/**
 * Baut die 8 Tageskacheln für das Ausführungsdatum-Auswahl-Modal (siehe sepaExecutionDateRange())
 * -- je Tag Datum, deutsche Wochentagskürzel, ob gesperrt und falls ja, aus welchem Grund (für
 * Tooltip/Beschriftung einer ausgegrauten Kachel).
 *
 * $lastUsed (das beim letzten Download dieses Laufs gewählte Datum, siehe
 * billing_runs.sepa_collection_date_used/sepa_transfer_date_used) wird vorausgewählt, FALLS es
 * noch innerhalb des aktuell berechneten Zeitraums und weiterhin ein gültiger Bankarbeitstag ist
 * -- sonst (Zeitraum hat sich verschoben, z.B. weil seit dem letzten Download viel Zeit verging)
 * fällt die Vorauswahl auf das erste gültige Datum zurück.
 */
function sepaExecutionDateTiles(string $invoiceDate, int $minDays, string $today, ?string $lastUsed = null): array
{
    static $weekdayNames = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    $range = sepaExecutionDateRange($invoiceDate, $minDays, $today);
    $tiles = [];
    $cursor = new DateTimeImmutable($range['range_start']);
    $end = new DateTimeImmutable($range['range_end']);
    while ($cursor <= $end) {
        $dateStr = $cursor->format('Y-m-d');
        $reason = bankingDayBlockReasonAT($dateStr);
        $weekday = $weekdayNames[(int)$cursor->format('N')];
        $tiles[] = [
            'date'    => $dateStr,
            'label'   => $weekday . ' ' . $cursor->format('d.m.Y'),
            'blocked' => $reason !== null,
            'reason'  => $reason,
        ];
        $cursor = $cursor->modify('+1 day');
    }
    $selected = ($lastUsed !== null
        && $lastUsed >= $range['range_start'] && $lastUsed <= $range['range_end']
        && isBankingDay($lastUsed))
        ? $lastUsed
        : $range['earliest'];
    return ['tiles' => $tiles, 'selected' => $selected, 'invoice_date' => $invoiceDate, 'min_days' => $minDays];
}

/**
 * Baut $count Tageskacheln für das Ausführungsdatum-Auswahl-Modal der Gutschrift-Überweisung
 * (pain.001) -- anders als sepaExecutionDateTiles() (Kalenderfenster mit einzelnen ausgegrauten
 * gesperrten Tagen) sind hier ALLE Kacheln tatsächlich wählbare Bankarbeitstage: Wochenenden und
 * Feiertage werden beim Weiterzählen einfach übersprungen statt als gesperrte Kachel mitgezählt
 * (Patrick, 18.10.2026: "mach bei Überweisen ohne die Samstage und Sonntage insgesamt 20
 * mögliche Auswahlen"). Beginnt ab (einschließlich) $fromDate -- bei der Überweisung ist das
 * "heute", da hier keine Vorabankündigungsfrist wie bei der Lastschrift gilt.
 *
 * @param string $fromDate erster möglicher Tag (Y-m-d), üblicherweise "heute"
 * @param int $count Anzahl der Kacheln (SEPA_TRANSFER_TILE_COUNT)
 * @param ?string $lastUsed beim letzten Download gewähltes Datum, siehe sepaExecutionDateTiles()
 */
function bankingDayTiles(string $fromDate, int $count, ?string $lastUsed = null): array
{
    static $weekdayNames = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    $cursor = isBankingDay($fromDate) ? $fromDate : nextBankingDay($fromDate);
    $tiles = [];
    for ($i = 0; $i < $count; $i++) {
        $d = new DateTimeImmutable($cursor);
        $tiles[] = [
            'date'    => $cursor,
            'label'   => $weekdayNames[(int)$d->format('N')] . ' ' . $d->format('d.m.Y'),
            'blocked' => false,
            'reason'  => null,
        ];
        if ($i < $count - 1) { $cursor = nextBankingDay($cursor); }
    }
    $validDates = array_column($tiles, 'date');
    $selected = ($lastUsed !== null && in_array($lastUsed, $validDates, true)) ? $lastUsed : $tiles[0]['date'];
    return ['tiles' => $tiles, 'selected' => $selected, 'first' => $tiles[0]['date'], 'last' => $tiles[count($tiles) - 1]['date']];
}

/**
 * Serverseitige Prüfung eines vom Nutzer im Ausführungsdatum-Modal gewählten Datums -- niemals
 * nur der Frontend-Auswahl vertrauen (Patrick, 18.10.2026: "Backend-Validierung (nicht nur im
 * Frontend)"). Gibt null zurück, wenn das Datum gültig ist, sonst eine fertige, auf Deutsch
 * formulierte Fehlermeldung für eine HTTP-422-Antwort.
 */
function validateSepaExecutionDate(string $date, string $invoiceDate, int $minDays, string $today): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Ungültiges Datumsformat.';
    }
    $range = sepaExecutionDateRange($invoiceDate, $minDays, $today);
    if ($date < $range['range_start'] || $date > $range['range_end']) {
        return 'Das gewählte Datum liegt außerhalb des zulässigen Zeitraums ('
            . date('d.m.Y', strtotime($range['range_start'])) . ' – ' . date('d.m.Y', strtotime($range['range_end'])) . ').';
    }
    if (!isBankingDay($date)) {
        return 'Das gewählte Datum (' . date('d.m.Y', strtotime($date)) . ') ist kein Bankarbeitstag ('
            . (bankingDayBlockReasonAT($date) ?? 'gesperrt') . ').';
    }
    return null;
}

/**
 * Serverseitige Prüfung des gewählten Ausführungsdatums für die Gutschrift-Überweisung
 * (pain.001, siehe bankingDayTiles()) -- Pendant zu validateSepaExecutionDate() für die
 * Lastschrift, aber ohne Vorabankündigungsfrist: gültig ist jeder Bankarbeitstag ab (einschließlich)
 * $today innerhalb der SEPA_TRANSFER_TILE_COUNT im Modal angezeigten Tage.
 */
function validateTransferExecutionDate(string $date, string $today): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Ungültiges Datumsformat.';
    }
    $t = bankingDayTiles($today, SEPA_TRANSFER_TILE_COUNT);
    if ($date < $t['first'] || $date > $t['last']) {
        return 'Das gewählte Datum liegt außerhalb des zulässigen Zeitraums ('
            . date('d.m.Y', strtotime($t['first'])) . ' – ' . date('d.m.Y', strtotime($t['last'])) . ').';
    }
    if (!isBankingDay($date)) {
        return 'Das gewählte Datum (' . date('d.m.Y', strtotime($date)) . ') ist kein Bankarbeitstag ('
            . (bankingDayBlockReasonAT($date) ?? 'gesperrt') . ').';
    }
    return null;
}

/**
 * Entfernt übrig gebliebene {{platzhalter}} aus einem Text. Sicherheitsnetz für den Mailversand:
 * verwendet eine Vorlage einen Platzhalter, den die auslösende Stelle nicht befüllt, soll beim
 * Empfänger lieber eine Lücke stehen als roher Vorlagen-Code („{{anrede}} {{nachname}},").
 * Ein evtl. vorangehendes Leerzeichen wird mitgenommen, damit keine doppelten Leerzeichen bleiben.
 */
function stripUnresolvedPlaceholders(string $s): string
{
    return trim(preg_replace('/\s*\{\{\s*\w+\s*\}\}/', '', $s));
}

// ─── TOTP (RFC 6238) für die optionale Zwei-Faktor-Authentifizierung ───────────────
// Bewusst abhängigkeitsfrei (nur hash_hmac/random_bytes), damit keine Composer-Pakete nötig
// sind und die Logik testbar bleibt. Kompatibel mit Apple Passwörter, Google Authenticator etc.

/** Base32-Dekodierung (RFC 4648, ohne Padding) für den geteilten TOTP-Schlüssel. */
function base32Decode(string $b32): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $bits .= str_pad(decbin(strpos($map, $b32[$i])), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    }
    return $out;
}

/** Base32-Kodierung (RFC 4648, ohne Padding) -- für die Anzeige neu erzeugter Secrets. */
function base32Encode(string $bin): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $map[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/** Erzeugt den 6-stelligen TOTP-Code für einen bestimmten Zeitpunkt (Default: 30s-Fenster). */
function totpCodeAt(string $secretBase32, int $timestamp, int $period = 30, int $digits = 6): string
{
    $counter = intdiv($timestamp, $period);
    $binCounter = pack('N*', 0) . pack('N*', $counter);   // 8-Byte-Big-Endian-Zähler
    $hash = hash_hmac('sha1', $binCounter, base32Decode($secretBase32), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          |  (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen eingegebenen TOTP-Code gegen das Secret, mit ±$window Zeitfenstern Toleranz
 * (Standard 1 = ±30s, gleicht kleine Uhr-Abweichungen aus). Zeitkonstanter Vergleich.
 */
function totpVerify(string $secretBase32, string $code, ?int $timestamp = null, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $timestamp = $timestamp ?? time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCodeAt($secretBase32, $timestamp + $i * 30), $code)) return true;
    }
    return false;
}

/** Neues, zufälliges Base32-Secret (Standard 20 Byte = 160 Bit, wie in RFC 6238 empfohlen). */
function totpGenerateSecret(int $bytes = 20): string
{
    return base32Encode(random_bytes($bytes));
}

/** otpauth://-URI zum Einrichten (QR-Code/Setup-Schlüssel in Apple Passwörter & Co.). */
function totpProvisioningUri(string $secretBase32, string $account, string $issuer): string
{
    $label = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label . '?secret=' . $secretBase32
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

// ─── CSRF-Schutz (OWASP-Audit 13.08.2026) ──────────────────────────────────────────
// Bisher schützte nur SameSite=Lax auf dem Session-Cookie vor CSRF -- das verhindert zwar
// Cross-Site-POSTs von komplett fremden Domains im Normalfall, aber nicht z.B. Top-Level-
// Navigation per <form> von einer verlinkten Seite oder ältere/exotische Browser ohne
// SameSite-Unterstützung. Ein Token pro Session (nicht pro Request -- sonst brechen mehrere
// offene Tabs/der Zurück-Button) wird zentral in Router::dispatch() für JEDEN POST geprüft;
// eingefügt wird er nicht in jede der ~70 Formular-Views einzeln, sondern per kleinem Skript
// am Ende von layouts/base.php + layouts/portal.php (jede Seite läuft durch genau eines der
// beiden), das automatisch ein verstecktes Feld in jedes <form method="post"> einfügt.

/** Liefert das Session-CSRF-Token, erzeugt bei Bedarf ein neues (einmal pro Session). */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Prüft ein eingesandtes Token zeitkonstant gegen das Session-Token. */
function csrfValid(?string $submitted): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Prüft ein Passwort per k-Anonymität gegen die HaveIBeenPwned-Datenbank bereits geleakter
 * Passwörter (OWASP-Audit 13.08.2026 -- bisher nur eine starre Mindestlänge von 8 Zeichen,
 * kein Schutz vor häufig wiederverwendeten/bereits kompromittierten Passwörtern). Nur die
 * ersten 5 Zeichen des SHA-1-Hashes werden übertragen ("Range"-API) -- das Passwort selbst
 * bzw. sein voller Hash verlassen den Server nie, siehe
 * https://haveibeenpwned.com/API/v3#PwnedPasswords.
 *
 * FAIL-OPEN (bewusst "weich"): Ist die API nicht erreichbar (Timeout, DNS, Netzwerk-Ausfall),
 * gilt das Passwort als NICHT geleakt -- ein Passwort-Wechsel/-Reset darf niemals an einer
 * nicht erreichbaren externen API scheitern (Patrick: "durchgehend funktionierende
 * Plattform"). Gleiches file_get_contents()+stream_context_create()-Muster wie
 * Mailer::getAccessToken() für ausgehende HTTP-Aufrufe, kein zusätzliches curl nötig.
 */
function isPasswordBreached(string $password): bool
{
    $sha1 = strtoupper(sha1($password));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "User-Agent: eeg-platform-password-check\r\n",
        'timeout'       => 3,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents('https://api.pwnedpasswords.com/range/' . $prefix, false, $ctx);
    $status = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
    if ($body === false || $status !== 200) {
        return false; // fail open
    }
    foreach (explode("\r\n", trim($body)) as $line) {
        [$lineSuffix] = explode(':', $line, 2);
        if (hash_equals($lineSuffix, $suffix)) {
            return true;
        }
    }
    return false;
}

/**
 * Normalisiert einen Wert für den Audit-Vergleich in einen gut lesbaren String: null/'' -> '',
 * bool -> 'ja'/'nein', DB-Bool-Strings 't'/'f' ebenso, Zahlen als String, sonst getrimmt.
 */
function auditNormalizeValue($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'ja' : 'nein';
    $s = trim((string)$v);
    if ($s === 't' || $s === 'true')  return 'ja';
    if ($s === 'f' || $s === 'false') return 'nein';
    return $s;
}

/**
 * Vergleicht zwei Datensätze (vorher/nachher) feldweise und liefert je geändertem Feld
 * ['label' => Anzeigename, 'von' => alt, 'auf' => neu]. Nur Felder aus $labels werden geprüft
 * (so bleiben interne/sensible Spalten außen vor); zusätzlich schützt $ignore einzelne Keys.
 * Rückgabe ist nach Feld-Key indiziert und enthält nur tatsächliche Änderungen.
 */
function auditDiff(array $before, array $after, array $labels, array $ignore = []): array
{
    $changes = [];
    foreach ($labels as $key => $label) {
        if (in_array($key, $ignore, true)) continue;
        if (!array_key_exists($key, $after)) continue;
        $von = auditNormalizeValue($before[$key] ?? null);
        $auf = auditNormalizeValue($after[$key]);
        if ($von !== $auf) {
            $changes[$key] = ['label' => $label, 'von' => $von, 'auf' => $auf];
        }
    }
    return $changes;
}

/**
 * Formatiert einen Änderungssatz (aus auditDiff) als lesbaren Satz:
 * „Name: „Alt" → „Neu"; IBAN: „—" → „AT..."". Leere Werte werden als „—" dargestellt.
 */
function auditChangesText(array $changes): string
{
    $parts = [];
    foreach ($changes as $c) {
        $von = $c['von'] === '' ? '—' : $c['von'];
        $auf = $c['auf'] === '' ? '—' : $c['auf'];
        $parts[] = $c['label'] . ': „' . $von . '" → „' . $auf . '"';
    }
    return implode('; ', $parts);
}

/**
 * Bezeichnung einer Mahnstufe: 1 = Zahlungserinnerung, 2 = 1. Mahnung, 3+ = 2./letzte Mahnung.
 * Stufe 0 (noch nicht gemahnt) ergibt einen leeren String.
 */
function mahnstufeText(int $stufe): string
{
    return match (true) {
        $stufe <= 0 => '',
        $stufe === 1 => 'Zahlungserinnerung',
        $stufe === 2 => '1. Mahnung',
        default => '2. Mahnung (letzte Aufforderung)',
    };
}

/**
 * Baut die formelle E-Mail-Anrede eines Mitglieds: ['anrede' => 'Sehr geehrter Herr',
 * 'nachname' => '<Titel> <Nachname>']. Getrennt vom Geschlecht (salutation), das die Person
 * selbst angibt -- email_anrede_mode überschreibt nur die Anrede:
 *   auto    -> aus salutation ableiten (Herr/Frau; sonst neutral "Guten Tag")
 *   herr    -> "Sehr geehrter Herr"
 *   frau    -> "Sehr geehrte Frau"
 *   familie -> "Sehr geehrte Familie"
 * Der Nachname bleibt immer der (Titel +) Nachname des Vertragspartners. Firmenmitglieder ohne
 * Personennamen bekommen "Sehr geehrte Damen und Herren" ohne Nachname.
 */
function mailSalutation(array $m): array
{
    // Nur der VORGESTELLTE Titel gehört in eine Anredezeile ("Sehr geehrter Herr Dr. Müller,")
    // -- ein nachgestellter akademischer Grad (BA, MSc, ...) wird in einer direkten Anrede nicht
    // mitgenannt, siehe formatMemberName() für die vollständige Namensausgabe mit beiden Titeln.
    $titel = trim((string)($m['titel_vor'] ?? ''));
    $last  = trim((string)($m['last_name'] ?? ''));
    if ($last === '' && trim((string)($m['company_name'] ?? '')) !== '') {
        return ['anrede' => 'Sehr geehrte Damen und Herren', 'nachname' => ''];
    }
    $mode = $m['email_anrede_mode'] ?? 'auto';
    if ($mode === 'auto') {
        $sal = trim((string)($m['salutation'] ?? ''));
        $mode = $sal === 'Herr' ? 'herr' : ($sal === 'Frau' ? 'frau' : 'neutral');
    }
    $anrede = match ($mode) {
        'herr'    => 'Sehr geehrter Herr',
        'frau'    => 'Sehr geehrte Frau',
        'familie' => 'Sehr geehrte Familie',
        default   => 'Guten Tag',
    };
    return ['anrede' => $anrede, 'nachname' => trim($titel . ' ' . $last)];
}

/**
 * In Österreich übliche VORGESTELLTE akademische Titel/Berufsbezeichnungen fürs Dropdown im
 * Mitglied-Formular bzw. der Online-Beitrittserklärung -- siehe titelNachOptionen() für
 * nachgestellte Grade und formatMemberName() für die kombinierte Ausgabe. Nicht erschöpfend,
 * "anderer" (Freitext) im Formular fängt alles ab, was hier fehlt.
 */
function titelVorOptionen(): array
{
    return [
        'Ing.', 'DI', 'Dipl.-Ing.', 'Dipl.-Ing. (FH)', 'Mag.', 'Mag. (FH)', 'MMag.',
        'Dr.', 'DDr.', 'Dr. med. univ.', 'Prof.', 'Univ.-Prof.', 'Mag. pharm.', 'Mag. iur.',
    ];
}

/** In Österreich übliche NACHGESTELLTE akademische Grade fürs Dropdown -- siehe titelVorOptionen(). */
function titelNachOptionen(): array
{
    return ['BA', 'BSc', 'BEd', 'Bakk.', 'MA', 'MSc', 'MEd', 'MBA', 'MAS', 'LL.B.', 'LL.M.', 'PhD'];
}

/**
 * Formatiert den vollständigen Namen eines Mitglieds inkl. vor-/nachgestelltem akademischen
 * Titel: "{titel_vor} {Vorname} {Nachname}, {titel_nach}" -- fehlende Teile fallen einfach weg,
 * ohne doppelte Leerzeichen oder ein überflüssiges Komma. Einheitliche Stelle für Rechnungs-PDF,
 * E-Mails und Mitgliederlisten (Patrick, 18.10.2026: "BA Kerstin Huber" statt korrekt
 * "Kerstin Huber, BA", weil der nachgestellte Titel bisher immer vorangestellt wurde).
 *
 * NICHT für den SEPA-Kontoinhaber-/Debtor-Namen in der pain.008/pain.001-XML verwenden --
 * Banksysteme erwarten einen einfachen Namen ohne Kommas/Zusätze; sepaCollectionData()/
 * sepaCreditTransferData() (index.php) bauen den Namen dort bewusst separat, nur mit titel_vor.
 */
function formatMemberName(array $m): string
{
    $titelVor  = trim((string)($m['titel_vor'] ?? ''));
    $titelNach = trim((string)($m['titel_nach'] ?? ''));
    $first = trim((string)($m['first_name'] ?? ''));
    $last  = trim((string)($m['last_name'] ?? ''));
    $name = trim(($titelVor !== '' ? $titelVor . ' ' : '') . trim($first . ' ' . $last));
    return $titelNach !== '' ? $name . ', ' . $titelNach : $name;
}

/**
 * Löst ein Titel-Dropdown-Feld (titelVorOptionen()/titelNachOptionen() + "keiner"/"anderer") aus
 * den rohen Formulardaten auf: $input[$field] enthält entweder "", einen der Options-Werte oder
 * das Sentinel "__other__" -- im letzten Fall zählt stattdessen das Freitextfeld
 * $input[$field . '_other']. Nur für die beiden HTML-Formulare (beitreten_formular.php,
 * member_form.php) gedacht -- die JSON-API (App) schickt titel_vor/titel_nach weiterhin direkt
 * als einfachen String, ohne dieses Dropdown-Sentinel.
 */
function resolveTitelField(array $input, string $field): ?string
{
    $selected = trim((string)($input[$field] ?? ''));
    if ($selected === '__other__') {
        $other = trim((string)($input[$field . '_other'] ?? ''));
        return $other !== '' ? $other : null;
    }
    return $selected !== '' ? $selected : null;
}

/**
 * Anzahl Kalendermonate zwischen zwei Daten (inklusive beider Enden) -- Vergleichsbasis für
 * invoice_items.months (siehe Billing::mitgliedsbeitragAnteilig()), um auf der Rechnung zu
 * entscheiden, ob die Mitgliedsbeitrag-Zeile "(anteilig, X Monate)" braucht oder den vollen
 * Abrechnungszeitraum abdeckt.
 */
function countCalendarMonths(string $periodFrom, string $periodTo): int
{
    $from = new DateTime($periodFrom);
    $to = new DateTime($periodTo);
    return ((int)$to->format('Y') - (int)$from->format('Y')) * 12 + ((int)$to->format('n') - (int)$from->format('n')) + 1;
}

/** "(anteilig, X Monate)" bei aliquotem Zeitraum, sonst leer -- siehe countCalendarMonths(). */
function mitgliedsbeitragLabelSuffix(int $months, int $totalMonths): string
{
    if ($totalMonths <= 0 || $months >= $totalMonths) return '';
    return ' (anteilig, ' . $months . ' ' . ($months === 1 ? 'Monat' : 'Monate') . ')';
}

/**
 * Umsatzsteuer-Aufteilung für einen Netto-Betrag. Die Tarife der Plattform sind grundsätzlich
 * netto hinterlegt. Bei 'kleinunternehmer' (§ 6 Abs 1 Z 27 UStG) fällt keine USt an, netto =
 * brutto. Bei 'standard' wird der USt-Satz (Default 20 %) auf den Netto-Betrag aufgeschlagen.
 * 'brutto' ist der tatsächlich zu zahlende bzw. einzuziehende Betrag (auch für SEPA/Vorabinfo).
 * Funktioniert vorzeichenrichtig: bei negativem Netto (Guthaben/Einspeisung) ist auch die USt
 * negativ, brutto = netto * (1 + Satz).
 * Rückgabe: ['model','rate','netto','ust','brutto'] (Beträge auf 2 Nachkommastellen gerundet).
 */
function taxBreakdown(float $netto, ?string $model, $rate): array
{
    $model = $model === 'standard' ? 'standard' : 'kleinunternehmer';
    $ratePct = $model === 'standard'
        ? (float)str_replace(',', '.', (string)($rate ?? 20))
        : 0.0;
    $netto = round($netto, 2);
    $ust   = round($netto * $ratePct / 100, 2);
    return [
        'model'  => $model,
        'rate'   => $ratePct,
        'netto'  => $netto,
        'ust'    => $ust,
        'brutto' => round($netto + $ust, 2),
    ];
}

/**
 * Formatiert einen Eurobetrag für die Rechnung -- negative Beträge bekommen statt eines
 * einfachen Bindestrichs ein LaTeX-Unicode-Minuszeichen mit schmalem Leerzeichen ("$-$\,",
 * gerendert als "− 30,25") für ein saubereres Schriftbild. EINHEITLICH für Summenzeile UND
 * Positionszeilen verwendet (Patrick, 18.10.2026: stand vorher in der Summenzeile mit einfachem
 * Bindestrich, uneinheitlich zu den Positionen). Erwartet den bereits SIGNIERTEN Betrag (negativ
 * = Gutschrift/Rabatt).
 */
function rechnungBetragUnicode(float $amount): string
{
    return ($amount < 0 ? '$-$\\,' : '') . number_format(abs($amount), 2, ',', '.');
}

/**
 * Liefert einen Dateinamen MIT Endung für Content-Disposition-Downloads über die App --
 * member_files.name ist eine frei getippte ANZEIGE-Bezeichnung (z.B. "Beitrittserklärung",
 * ohne Endung), während member_files.pfad der tatsächlich gespeicherte Pfad MIT der echten
 * Endung ist. Im Browser stört eine fehlende Endung kaum (der Browser/das Betriebssystem rät
 * anhand des Content-Type), aber die iOS-App kann eine ohne Endung heruntergeladene Datei nicht
 * öffnen (Patrick, 19.08.2026: "kann sie nicht öffnen"). Hängt die echte Endung an, falls die
 * Anzeige-Bezeichnung nicht schon zufällig selbst mit ihr endet.
 */
function filenameWithExtension(string $displayName, string $storedPath): string
{
    $ext = pathinfo($storedPath, PATHINFO_EXTENSION);
    if ($ext === '') return $displayName;
    if (strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) === strtolower($ext)) return $displayName;
    return $displayName . '.' . $ext;
}
