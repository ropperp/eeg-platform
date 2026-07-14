<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

foreach (['DB', 'Auth', 'Router', 'Billing'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}

Auth::start();

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
 * Stellen = 33 Zeichen gesamt.
 */
function validateZaehlpunkt(string $zp): bool
{
    return (bool)preg_match('/^AT[A-Z0-9]{31}$/', strtoupper(trim($zp)));
}

/**
 * Extrahiert den Ort aus einer frei eingegebenen Adresse "Straße Nr., PLZ Ort"
 * (Community-Adresse ist ein einzelnes Freitextfeld ohne getrennte Ort-Spalte).
 * Nimmt das letzte Komma-Segment und entfernt eine vorangestellte PLZ.
 */
function extractOrtFromAddress(?string $address): string
{
    $parts = explode(',', $address ?? '');
    $last = trim(end($parts));
    return trim(preg_replace('/^\d{3,6}\s*/', '', $last));
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
 * Baut ein 33-Kästchen-Raster (wie am Papier-Beitrittsformular) und trägt die
 * Zeichen einer Zählpunktnummer einzeln ein. Ohne Wert bleibt das Raster leer.
 */
function zpGridTikz(?string $zp): string
{
    $chars = array_slice(str_split(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($zp ?? '')))), 0, 33);
    $nodes = '';
    foreach ($chars as $i => $c) {
        $nodes .= '\\node at (' . sprintf('%.2f', 0.24 + $i * 0.5) . ',0.29){\\small ' . $c . '};' . "\n";
    }
    return '\\begin{tikzpicture}[baseline=-3pt]' . "\n"
        . '\\foreach \\i in {0,...,32}{\\draw[boxgray] (\\i*0.5,0) rectangle ++(0.48,0.58);}' . "\n"
        . $nodes
        . '\\end{tikzpicture}';
}

/**
 * Liefert die RAW_-Variable fürs Unterschriftsbild "Für die EEG" sowie das
 * zugehörige Bild-Asset für den aktuell eingeloggten User (i.d.R. der
 * Obmann/die Obfrau, der/die den Vertrag gerade erzeugt). Ohne hinterlegte
 * Unterschrift bleibt die Zeile leer (nur die Unterschriftslinie).
 */
function eegSignatureAsset(): array
{
    $user = DB::fetchOne('SELECT signature_image FROM users WHERE id = ?', [Auth::userId()]);
    if (empty($user['signature_image'])) {
        return ['var' => '', 'assets' => []];
    }
    return [
        'var'    => '\\includegraphics[height=1.4cm]{unterschrift_eeg.png}',
        'assets' => ['unterschrift_eeg.png' => $user['signature_image']],
    ];
}

/**
 * Ruft den latex-service auf und streamt das PDF direkt an den Browser.
 * Gibt true zurück wenn das PDF erfolgreich gesendet wurde, sonst false.
 */
function streamLatexPdf(string $template, array $vars, string $filename, array $assets = []): bool
{
    $url     = (getenv('LATEX_SERVICE_URL') ?: 'http://latex-service:3210') . '/generate';
    $apiKey  = getenv('LATEX_API_KEY') ?: 'dev-key';
    $payload = json_encode(['template' => $template, 'vars' => $vars, 'assets' => $assets]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Api-Key: {$apiKey}\r\n",
        'content' => $payload,
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);

    $body = file_get_contents($url, false, $ctx);
    $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 500')[1];

    if ($code !== 200 || !$body) {
        http_response_code(500);
        $detail = '';
        if ($body) {
            $json = json_decode($body, true);
            $detail = isset($json['error']) ? ': ' . htmlspecialchars($json['error']) : '';
        }
        echo "<pre>PDF-Generierung fehlgeschlagen (HTTP {$code}){$detail}. Bitte latex-service prüfen.</pre>";
        return false;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    return true;
}

/**
 * Legt ein Mitglied inkl. Login-User und Rolle an. Wird sowohl von der manuellen
 * Mitglieder-Anlage (/portal/members) als auch von der Freigabe einer Online-
 * Beitrittserklärung (/portal/applications/:id/approve) verwendet, damit KdNr-
 * Vergabe (Lücken-Auffüllung) und Mandatsreferenz-Logik nicht doppelt gepflegt werden.
 * Erwartet in $f Schlüssel wie die Spalten der members-Tabelle (salutation, titel, …).
 * Gibt ['member_id', 'user_id', 'kundennummer', 'temp_password' (oder null)] zurück.
 */
function createMemberRecord(string $communityId, array $f): array
{
    $email = strtolower(trim($f['email']));
    $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    $tempPw = null;
    if (!$user) {
        $tempPw = bin2hex(random_bytes(8));
        $hash = password_hash($tempPw, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute(
            'INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)',
            [$email, $hash, trim($f['first_name']), trim($f['last_name'])]
        );
        $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    }

    // KdNr: kleinste freie Nummer je Community ab 10001 vergeben (Lücken von gelöschten
    // Mitgliedern auffüllen). Start bei 10001 statt 1, damit genug Buffer für Wachstum
    // bleibt und Kundennummern nicht mit anderen kurzen Referenznummern verwechselt werden.
    $kundennummer = (int)DB::fetchOne(
        "SELECT MIN(candidate) AS next FROM generate_series(
            10001, (SELECT COALESCE(MAX(kundennummer), 10000) + 1 FROM members WHERE community_id = ?)
         ) AS candidate
         WHERE candidate NOT IN (
            SELECT kundennummer FROM members WHERE community_id = ? AND kundennummer IS NOT NULL
         )",
        [$communityId, $communityId]
    )['next'];
    $iban = trim($f['member_iban'] ?? '');
    $mandatsreferenz = $iban !== '' ? 'S00000F' . date('Y') . 'A' . $kundennummer : null;

    DB::execute(
        'INSERT INTO members (
            community_id, user_id, salutation, titel, first_name, last_name, company_name,
            address, zip, city, email, phone, invoice_uid, member_iban, member_bic,
            kontoinhaber, konto_adresse,
            member_since, member_until, kundennummer, mandatsreferenz, beitrittsdatum,
            geburtsdatum, stromlieferant, speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            $user['id'],
            $f['salutation'] ?? null,
            trim($f['titel'] ?? '') ?: null,
            trim($f['first_name']),
            trim($f['last_name']),
            trim($f['company_name'] ?? '') ?: null,
            trim($f['address'] ?? ''),
            trim($f['zip'] ?? ''),
            trim($f['city'] ?? ''),
            $email,
            trim($f['phone'] ?? '') ?: null,
            trim($f['invoice_uid'] ?? '') ?: null,
            $iban ?: null,
            trim($f['member_bic'] ?? '') ?: null,
            trim($f['kontoinhaber'] ?? '') ?: null,
            trim($f['konto_adresse'] ?? '') ?: null,
            $f['member_since'] ?: date('Y-m-d'),
            ($f['member_until'] ?? '') ?: '2099-12-31',
            $kundennummer,
            $mandatsreferenz,
            $f['member_since'] ?: date('Y-m-d'),
            ($f['geburtsdatum'] ?? '') ?: null,
            trim($f['stromlieferant'] ?? '') ?: null,
            ($f['speicher_status'] ?? '') ?: null,
            ($f['speicher_kwh'] ?? '') !== '' && ($f['speicher_kwh'] ?? null) !== null ? (float)$f['speicher_kwh'] : null,
            !empty($f['andere_eeg']) ? 'true' : 'false',
            trim($f['andere_eeg_name'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
        ]
    );
    $member = DB::fetchOne('SELECT id FROM members WHERE community_id = ? AND kundennummer = ?', [$communityId, $kundennummer]);

    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $user['id'], 'member']
    );

    return [
        'member_id'     => $member['id'],
        'user_id'       => $user['id'],
        'kundennummer'  => $kundennummer,
        'temp_password' => $tempPw,
    ];
}

/**
 * Schreibt einen Eintrag ins Admin-Aktivitätslog (Abrechnung, Mitglieder, EDA-Import,
 * Fehlermeldungen, Änderungen an Mitglied/EEG). Absichtlich fehlertolerant: ein Logging-
 * Fehler darf die eigentliche Aktion nie verhindern.
 */
function logAudit(?string $communityId, string $aktion, ?string $entityTyp, ?string $entityId, string $beschreibung, bool $istFehler = false): void
{
    try {
        DB::execute(
            'INSERT INTO audit_log (community_id, user_id, aktion, entity_typ, entity_id, beschreibung, ist_fehler)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$communityId, Auth::userId(), $aktion, $entityTyp, $entityId, $beschreibung, $istFehler ? 'true' : 'false']
        );
    } catch (Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
    }
}

$router = new Router();

// ─── Gesundheitscheck ───────────────────────────────────
$router->get('/health', function () {
    echo 'OK';
});

// ─── Landingpage ────────────────────────────────────────
$router->get('/', function () {
    require ROOT . '/src/views/pages/home.php';
});

// ─── Informieren und Beitreten: Auswahl der Energiegemeinschaft ─────────
$router->get('/beitreten', function () {
    $communities = DB::fetchAll('SELECT * FROM communities WHERE active = true ORDER BY name');
    // Communities mit bereits veröffentlichten Beitritts-/Rechtsunterlagen (Statuten, AGB, …).
    // Für neu angelegte EEGs ohne eigene Unterlagen wird "Informationen folgen in Kürze" angezeigt,
    // statt fälschlich die Texte einer anderen Energiegemeinschaft darzustellen.
    $communitiesWithLegalPages = ['rc108175'];
    require ROOT . '/src/views/pages/beitreten_picker.php';
});

// ─── Rechtliches (rc108175 = Marktpartner-ID Strompool Feldkirchen Süd-West) ──
$router->get('/rc108175/beitreten', function () {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ?', ['rc108175']);
    require ROOT . '/src/views/pages/legal_beitreten.php';
});

$router->get('/rc108175/kontakt', function () {
    require ROOT . '/src/views/pages/legal_kontakt.php';
});

$router->get('/rc108175/impressum', function () {
    require ROOT . '/src/views/pages/legal_impressum.php';
});

$router->get('/rc108175/statuten', function () {
    require ROOT . '/src/views/pages/legal_statuten.php';
});

$router->get('/rc108175/datenschutz', function () {
    require ROOT . '/src/views/pages/legal_datenschutz.php';
});

$router->get('/rc108175/agb', function () {
    require ROOT . '/src/views/pages/legal_agb.php';
});

$router->get('/rc108175/preisliste', function () {
    require ROOT . '/src/views/pages/legal_preisliste.php';
});

// ─── Online-Beitrittserklärung ──────────────────────────
$router->get('/:communityid/beitreten/formular', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/beitreten_formular.php';
});

$router->post('/:communityid/beitreten/formular', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    $communityId = $community['id'];
    DB::setCommunity($communityId);

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $rf) {
        if (empty(trim($_POST[$rf] ?? ''))) {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
            require ROOT . '/src/views/pages/beitreten_formular.php';
            return;
        }
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($_POST[$cf])) {
            $error = 'Bitte alle sechs rechtlichen Zustimmungen bestätigen.';
            require ROOT . '/src/views/pages/beitreten_formular.php';
            return;
        }
    }

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    if ($iban !== '' && trim($_POST['kontoinhaber'] ?? '') === '') {
        $error = 'Bitte bei Bankverbindung den vollen Namen des Kontoinhabers/der Kontoinhaberin angeben.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }

    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        $error = 'Bitte unterschreiben Sie im Unterschriftsfeld, bevor Sie absenden.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    $sepaSignature = $_POST['sepa_signature_image'] ?? '';
    if ($iban !== '' && !str_starts_with($sepaSignature, 'data:image/png;base64,')) {
        $error = 'Bitte unterschreiben Sie zusätzlich das SEPA-Lastschriftmandat, da Sie eine IBAN angegeben haben.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }

    DB::execute(
        'INSERT INTO membership_applications (
            community_id, salutation, titel, first_name, last_name, geburtsdatum,
            address, zip, city, phone, email, stromlieferant,
            bezug_gewuenscht, bezug_zaehlpunkt, bezug_jahresverbrauch_kwh,
            einspeisung_gewuenscht, einspeisung_zaehlpunkt, einspeisung_kwp, einspeisung_geplante_kwh,
            speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            iban, bic, kontoinhaber, konto_adresse,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb,
            signature_image, signed_at, signer_ip, sepa_signature_image, sepa_signed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), ?, ?, ?)',
        [
            $communityId,
            $_POST['salutation'] ?? null,
            trim($_POST['titel'] ?? '') ?: null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            ($_POST['geburtsdatum'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            trim($_POST['phone'] ?? '') ?: null,
            strtolower(trim($_POST['email'])),
            trim($_POST['stromlieferant'] ?? '') ?: null,
            isset($_POST['bezug_gewuenscht']) ? 'true' : 'false',
            trim($_POST['bezug_zaehlpunkt'] ?? '') ?: null,
            ($_POST['bezug_jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['bezug_jahresverbrauch_kwh']) : null,
            isset($_POST['einspeisung_gewuenscht']) ? 'true' : 'false',
            trim($_POST['einspeisung_zaehlpunkt'] ?? '') ?: null,
            ($_POST['einspeisung_kwp'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_kwp']) : null,
            ($_POST['einspeisung_geplante_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_geplante_kwh']) : null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['speicher_kwh']) : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            $iban ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            trim($_POST['kontoinhaber'] ?? '') ?: null,
            trim($_POST['konto_adresse'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
            $signature,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $iban !== '' ? $sepaSignature : null,
            $iban !== '' ? date('Y-m-d H:i:s') : null,
        ]
    );
    $application = DB::fetchOne(
        'SELECT id FROM membership_applications WHERE community_id = ? AND email = ? ORDER BY created_at DESC LIMIT 1',
        [$communityId, strtolower(trim($_POST['email']))]
    );

    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            'beitrittserklaerung',
            'Neue Beitrittserklärung: ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']),
            'Online-Beitrittserklärung wurde übermittelt und wartet auf Freigabe.',
            'membership_application',
            $application['id'],
        ]
    );

    header('Location: /' . strtolower($community['marktpartner_id']) . '/beitreten/danke');
    exit;
});

$router->get('/:communityid/beitreten/danke', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/beitreten_danke.php';
});

// ─── Live-Dashboard ─────────────────────────────────────
$router->get('/live', function () {
    require ROOT . '/src/views/pages/live.php';
});

$router->get('/api/live/:slug', function ($params) {
    header('Content-Type: application/json');
    $slug = $params['slug'];
    $community = DB::fetchOne('SELECT id FROM communities WHERE slug = ? AND active = true', [$slug]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'Nicht gefunden']); return; }

    DB::setCommunity($community['id']);

    $agg = DB::fetchOne(
        "SELECT
            COALESCE(SUM(power_bezug_w), 0)        AS total_bezug_w,
            COALESCE(SUM(power_einspeisung_w), 0)  AS total_einspeisung_w,
            COUNT(DISTINCT metering_point_id)       AS active_meters
         FROM esp_measurements
         WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes'",
        [$community['id']]
    );

    $today = DB::fetchOne(
        "SELECT COALESCE(SUM(energy_einspeisung_wh), 0) AS today_wh
         FROM esp_measurements
         WHERE community_id = ? AND time >= CURRENT_DATE
         ORDER BY time DESC LIMIT 1",
        [$community['id']]
    );

    $series = DB::fetchAll(
        "SELECT
            time_bucket('5 minutes', time) AS bucket,
            SUM(power_bezug_w)             AS bezug_w,
            SUM(power_einspeisung_w)       AS einspeisung_w
         FROM esp_measurements
         WHERE community_id = ? AND time >= now() - INTERVAL '2 hours'
         GROUP BY bucket ORDER BY bucket",
        [$community['id']]
    );

    $bezug = (int)($agg['total_bezug_w'] ?? 0);
    $einsp = (int)($agg['total_einspeisung_w'] ?? 0);
    $autarkie = $bezug > 0 ? min(100, round($einsp / $bezug * 100)) : 0;

    echo json_encode([
        'bezug_w'       => $bezug,
        'einspeisung_w' => $einsp,
        'autarkie_pct'  => $autarkie,
        'today_kwh'     => round(($today['today_wh'] ?? 0) / 1000, 2),
        'active_meters' => (int)($agg['active_meters'] ?? 0),
        'series'        => $series,
    ]);
});

$router->get('/api/communities/search', function () {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $results = DB::fetchAll(
        'SELECT name, slug FROM communities WHERE active = true AND name ILIKE ? LIMIT 10', [$q]
    );
    echo json_encode($results);
});

// ─── Portal: Login ──────────────────────────────────────
$router->get('/portal/login', function () {
    if (Auth::check()) { header('Location: /portal/dashboard'); exit; }
    require ROOT . '/src/views/pages/login.php';
});

$router->post('/portal/login', function () {
    $email    = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if (Auth::login($email, $password)) {
        header('Location: /portal/dashboard');
    } else {
        $error = 'E-Mail oder Passwort falsch.';
        require ROOT . '/src/views/pages/login.php';
    }
    exit;
});

$router->get('/portal/logout', function () {
    Auth::logout();
    header('Location: /portal/login');
    exit;
});

$router->get('/portal/forgot-password', function () {
    require ROOT . '/src/views/pages/forgot_password.php';
});

$router->post('/portal/forgot-password', function () {
    $email = $_POST['email'] ?? '';
    $token = Auth::createResetToken($email);
    // TODO: Mail via SMTP
    $success = 'Falls die E-Mail existiert, wurde ein Reset-Link versendet.';
    require ROOT . '/src/views/pages/forgot_password.php';
});

// ─── Portal: Dashboard ──────────────────────────────────
$router->get('/portal/dashboard', function () {
    Auth::requireLogin();
    if (Auth::isManager()) {
        require ROOT . '/src/views/pages/manager_dashboard.php';
    } else {
        require ROOT . '/src/views/pages/member_dashboard.php';
    }
});

$router->post('/portal/switch-role', function () {
    Auth::requireLogin();
    $communityId = $_POST['community_id'] ?? '';
    $role        = $_POST['role'] ?? '';
    Auth::switchRole($communityId, $role);
    header('Location: /portal/dashboard');
    exit;
});

// ─── Portal: Rechnungen (Mitglied) ──────────────────────
$router->get('/portal/invoices', function () {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $userId = Auth::userId();
    $member = DB::fetchOne('SELECT id FROM members WHERE user_id = ? AND community_id = ?', [$userId, $communityId]);
    if (!$member) { http_response_code(404); echo 'Mitglied nicht gefunden'; return; }
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$member['id']]
    );
    require ROOT . '/src/views/pages/invoices.php';
});

$router->get('/portal/invoices/:id/pdf', function ($params) {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    if ($communityId) DB::setCommunity($communityId);

    $invoice = DB::fetchOne(
        'SELECT i.*, m.first_name, m.last_name, m.address, m.zip, m.city, m.invoice_uid,
                br.quartal, br.period_from, br.period_to,
                c.name AS eeg_name, c.address AS eeg_address, c.iban AS eeg_iban, c.bic AS eeg_bic,
                tc.bezug_ct_kwh, tc.einspeisung_ct_kwh, tc.mitgliedsbeitrag_eur
         FROM invoices i
         JOIN members m ON m.id = i.member_id
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN communities c ON c.id = br.community_id
         LEFT JOIN tariff_config tc ON tc.community_id = c.id AND tc.valid_from <= br.period_from
         WHERE i.id = ?
         ORDER BY tc.valid_from DESC',
        [$params['id']]
    );
    if (!$invoice) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }

    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$params['id']]);
    $bezugItem = null; $einspeisungItem = null; $beitragItem = null;
    foreach ($items as $it) {
        if ($it['type'] === 'bezug')           $bezugItem       = $it;
        if ($it['type'] === 'einspeisung')     $einspeisungItem = $it;
        if ($it['type'] === 'mitgliedsbeitrag') $beitragItem    = $it;
    }

    // RAW_: LaTeX-Befehle direkt übergeben — service.js darf diese NICHT escapen.
    // Zeile im Tabellenkontext (5 Spalten, braucht \\):
    $steuerZeile = '\\multicolumn{5}{l}{\\footnotesize\\color{midgray}Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung): keine Umsatzsteuer.} \\\\';
    // Paragraph am Seitenende:
    $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';

    streamLatexPdf('rechnung', [
        'EEG_NAME'              => $invoice['eeg_name'],
        'EEG_ADRESSE'           => $invoice['eeg_address'] ?? '',
        'EEG_UID'               => '',
        'MITGLIED_NAME'         => $invoice['first_name'] . ' ' . $invoice['last_name'],
        'MITGLIED_ADRESSE'      => $invoice['address'] . ', ' . $invoice['zip'] . ' ' . $invoice['city'],
        'MITGLIED_UID'          => $invoice['invoice_uid'] ?? '',
        'RECHNUNGSNUMMER'       => $invoice['rechnungsnummer'],
        'RECHNUNGSDATUM'        => date('d.m.Y', strtotime($invoice['created_at'])),
        'ABRECHNUNGSZEITRAUM'   => date('d.m.Y', strtotime($invoice['period_from'])) . ' -- ' . date('d.m.Y', strtotime($invoice['period_to'])),
        'BEZUG_KWH'             => $bezugItem ? number_format((float)$bezugItem['kwh'], 2, ',', '.') : '0,00',
        'BEZUG_TARIF'           => $bezugItem ? number_format((float)$bezugItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
        'BEZUG_BETRAG'          => $bezugItem ? number_format((float)$bezugItem['amount_eur'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_KWH'       => $einspeisungItem ? number_format((float)$einspeisungItem['kwh'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_TARIF'     => $einspeisungItem ? number_format((float)$einspeisungItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
        'EINSPEISUNG_BETRAG'    => $einspeisungItem ? number_format(abs((float)$einspeisungItem['amount_eur']), 2, ',', '.') : '0,00',
        'MITGLIEDSBEITRAG'      => $beitragItem ? number_format((float)$beitragItem['amount_eur'], 2, ',', '.') : '0,00',
        'SUMME_NETTO'           => number_format((float)$invoice['saldo_eur'], 2, ',', '.'),
        'SUMME_BRUTTO'          => number_format((float)$invoice['saldo_eur'], 2, ',', '.'),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'IBAN'                  => $invoice['eeg_iban'] ?? '--',
        'BIC'                   => $invoice['eeg_bic'] ?? '--',
        'ZAHLUNGSZIEL'          => date('d.m.Y', strtotime($invoice['created_at'] . ' +14 days')),
    ], $invoice['rechnungsnummer'] . '.pdf');
});

// ─── Portal: Mitgliederverwaltung ───────────────────────
$router->get('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        "SELECT m.*,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung,
                COALESCE(SUM(i.saldo_eur) FILTER (WHERE i.saldo_eur > 0 AND i.sent_at IS NULL), 0) AS open_amount
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         LEFT JOIN invoices i ON i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/member_list.php';
});

$router->get('/portal/files', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        "SELECT id, first_name, last_name, company_name, email, kundennummer
         FROM members WHERE community_id = ? ORDER BY kundennummer NULLS LAST, last_name, first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/files_search.php';
});

$router->get('/portal/files/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    $hasConsumer = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM metering_points WHERE member_id = ? AND type = 'consumer' AND active = true LIMIT 1",
        [$params['id']]
    );
    $hasProducer = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM metering_points WHERE member_id = ? AND type = 'producer' AND active = true LIMIT 1",
        [$params['id']]
    );

    require ROOT . '/src/views/pages/files_member.php';
});

$router->get('/portal/members/new', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim($_POST[$f] ?? ''))) {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($_POST[$cf])) {
            $error = 'Bitte alle sechs rechtlichen Zustimmungen bestätigen, bevor das Mitglied angelegt wird.';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }

    $email = strtolower(trim($_POST['email']));
    $result = createMemberRecord($communityId, array_merge($_POST, ['andere_eeg' => isset($_POST['andere_eeg'])]));
    logAudit($communityId, 'member.create', 'member', $result['member_id'],
        'Mitglied ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']) . ' angelegt (KdNr ' . $result['kundennummer'] . ')');

    // Temp-Passwort anzeigen falls neuer User
    if ($result['temp_password']) {
        $successTempPw = $result['temp_password'];
        $successEmail  = $email;
        $members = DB::fetchAll(
            "SELECT m.*,
                    COUNT(DISTINCT mp.id) AS metering_point_count,
                    bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                    bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung
             FROM members m
             LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
             WHERE m.community_id = ? GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
            [$communityId]
        );
        require ROOT . '/src/views/pages/member_list.php';
        exit;
    }

    header('Location: /portal/members?success=1');
    exit;
});

$router->get('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); return; }

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        $member = array_merge($member, $_POST);
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }

    // Mandatsreferenz erstmalig vergeben, sobald erstmals eine IBAN hinterlegt wird — danach unveränderlich
    $mandatsreferenz = $member['mandatsreferenz'];
    if ($iban !== '' && empty($mandatsreferenz)) {
        $mandatsreferenz = 'S00000F' . date('Y') . 'A' . $member['kundennummer'];
    }

    DB::execute(
        'UPDATE members SET salutation=?, titel=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, kontoinhaber=?, konto_adresse=?, mandatsreferenz=?,
         member_since=?, member_until=?,
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?
         WHERE id=?',
        [
            $_POST['salutation'] ?? null,
            trim($_POST['titel'] ?? '') ?: null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['company_name'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['invoice_uid'] ?? '') ?: null,
            $iban ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            trim($_POST['kontoinhaber'] ?? '') ?: null,
            trim($_POST['konto_adresse'] ?? '') ?: null,
            $mandatsreferenz,
            $_POST['member_since'] ?: date('Y-m-d'),
            ($_POST['member_until'] ?? '') ?: '2099-12-31',
            ($_POST['geburtsdatum'] ?? '') ?: null,
            trim($_POST['stromlieferant'] ?? '') ?: null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)$_POST['speicher_kwh'] : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            $params['id'],
        ]
    );
    logAudit($communityId, 'member.update', 'member', $params['id'],
        'Mitglied ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']) . ' bearbeitet');
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT id, first_name, last_name, kundennummer FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // Löscht kaskadierend Verträge, Dateien, Zählpunkte, Rechnungen (siehe migrate_20260715.sql).
    // KdNr wird dadurch wieder frei und beim nächsten neuen Mitglied automatisch wiederverwendet.
    DB::execute('DELETE FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    logAudit($communityId, 'member.delete', 'member', $params['id'],
        'Mitglied ' . $member['first_name'] . ' ' . $member['last_name'] . ' (KdNr ' . ($member['kundennummer'] ?? '—') . ') gelöscht');

    header('Location: /portal/members?success=1');
    exit;
});

// Manager dürfen (anders als der globale /admin/users-Bereich) NUR das Login-Konto
// von Mitgliedern der EIGENEN EEG entfernen. Da users.email plattformweit eindeutig ist,
// wird die users-Zeile nur gelöscht, wenn der Account sonst KEINE Rolle mehr hat — sonst
// würde man einer Person versehentlich den Zugriff auf eine andere EEG entziehen, in der
// sie z.B. ebenfalls Mitglied ist.
$router->post('/portal/members/:id/delete-login', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT id, user_id FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member || !$member['user_id']) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $userId = $member['user_id'];

    DB::execute('UPDATE members SET user_id = NULL WHERE id = ?', [$params['id']]);
    DB::execute('DELETE FROM user_roles WHERE user_id = ? AND community_id = ?', [$userId, $communityId]);

    $remainingRoles = DB::fetchOne('SELECT COUNT(*) AS cnt FROM user_roles WHERE user_id = ?', [$userId])['cnt'];
    if ((int)$remainingRoles === 0) {
        DB::execute('DELETE FROM users WHERE id = ?', [$userId]);
    }

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? ORDER BY registered_at DESC', [$params['id']]);
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    require ROOT . '/src/views/pages/member_detail.php';
});

$router->post('/portal/members/:id/files', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
        exit;
    }

    $displayName = trim($_POST['name'] ?? '') ?: basename($_FILES['file']['name']);
    $origExt = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $dir = '/var/www/html/storage/uploads/members/' . $params['id'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $storedName = bin2hex(random_bytes(16)) . ($origExt ? '.' . strtolower($origExt) : '');
    $destPath = $dir . '/' . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
        exit;
    }

    // Absichtlich try/catch statt einfach durchbrechen zu lassen: bei einem Schema-Problem
    // (unbekannte Alt-Spalte, siehe migrate_20260719.sql) landet man sonst in einem rohen
    // 500 ohne jeden Hinweis, was los ist. So bekommt der Manager wenigstens die konkrete
    // DB-Fehlermeldung angezeigt und kann sie weitergeben, statt dass wir blind raten müssen.
    try {
        DB::execute(
            'INSERT INTO member_files (community_id, member_id, name, pfad, mime, sha256, hochgeladen_von)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $communityId,
                $params['id'],
                $displayName,
                $destPath,
                $_FILES['file']['type'] ?: null,
                hash_file('sha256', $destPath),
                Auth::userId(),
            ]
        );
    } catch (\PDOException $e) {
        unlink($destPath);
        header('Location: /portal/members/' . $params['id'] . '?error=upload_db&detail=' . urlencode($e->getMessage()));
        exit;
    }

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id/files/:fileid/download', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ? AND community_id = ?',
        [$params['fileid'], $params['id'], $communityId]
    );
    if (!$file || !is_file($file['pfad'])) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($file['name']) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

$router->post('/portal/members/:id/metering-points', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); return; }

    $znr = strtoupper(trim($_POST['zaehlpunkt_nr'] ?? ''));
    if (!$znr) { header('Location: /portal/members/' . $params['id'] . '?error=znr'); exit; }

    $existing = DB::fetchOne(
        "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
         JOIN members m ON m.id = mp.member_id
         WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ?",
        [$communityId, $znr]
    );
    if ($existing) {
        header('Location: /portal/members/' . $params['id'] . '?error=znr_duplicate&znr_owner='
            . urlencode($existing['first_name'] . ' ' . $existing['last_name'] . ' (KdNr ' . ($existing['kundennummer'] ?? '—') . ')'));
        exit;
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, jahresverbrauch_kwh, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $member['id'], $znr, $_POST['type'] ?? 'consumer', trim($_POST['meter_code'] ?? '') ?: null, $jahresverbrauch, $engpassleistung, $geplanteEinsp]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id/contract/bezug', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // IDOR-Schutz: Manager darf nur die eigene EEG verwalten
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Bezugs-Zählpunkt registriert. Bitte zuerst einen Bezugs-Zählpunkt (Typ: Bezug) anlegen.'; return; }

    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    $genAt    = $member['contract_bezug_generated_at'] ?? null;
    $tariffAt = $tariff['valid_from'] ?? null;
    $status   = $member['contract_bezug_status'] ?? 'none';
    if ($status === 'signed' && $genAt && (!$tariffAt || $tariffAt <= $genAt)) {
        http_response_code(400);
        echo 'Vertrag wurde bereits unterschrieben. Generierung nur nach einer Tarifänderung möglich.';
        return;
    }

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);

    // Nur noch Zählpunktnummer (keine Zählernummer im Vertrag) als \item-Liste
    $zpLines = implode("\n", array_map(
        fn($mp) => '\\item ' . texEscape($mp['zaehlpunkt_nr']),
        $mps
    ));

    $signature = eegSignatureAsset();
    $ok = streamLatexPdf('bezugsvereinbarung', [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => ($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name'],
        'MITGLIED_ADRESSE'          => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'      => $member['city'],
        'MITGLIED_UID_ZEILE'        => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEPA_MANDATSREFERENZ' => $member['mandatsreferenz'] ?? '--',
        'MITGLIED_IBAN'             => $member['member_iban'] ?? '--',
        'BEZUG_TARIF'               => $tariff ? number_format((float)$tariff['bezug_ct_kwh'], 4, ',', '.') : '--',
        'MITGLIEDSBEITRAG'          => $tariff ? number_format((float)$tariff['mitgliedsbeitrag_eur'], 2, ',', '.') : '--',
        'TARIF_GUELTIG_AB'          => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'RAW_ZAEHLPUNKTE_LISTE'     => $zpLines,
        // Community-spezifisch hardcodiert wie "Kärnten Netz GmbH" -- bei mehreren EEGs mit
        // eigenen Subdomains müsste hier stattdessen eine echte Domain-Konfiguration greifen.
        'EEG_DASHBOARD_URL'         => 'https://portal.stromfueralle.at/portal/login',
        'RAW_EEG_UNTERSCHRIFT_BILD' => $signature['var'],
        'ERSTELLT_AM'               => date('d.m.Y'),
    ], 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets']);

    // DB-Update NUR nach erfolgreichem PDF
    if ($ok) {
        DB::execute(
            "UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END, contract_bezug_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

$router->get('/portal/members/:id/contract/einspeisung', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // IDOR-Schutz
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Einspeise-Zählpunkt registriert. Bitte zuerst einen Zählpunkt (Typ: Einspeisung) anlegen.'; return; }

    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    $genAt    = $member['contract_einspeisung_generated_at'] ?? null;
    $tariffAt = $tariff['valid_from'] ?? null;
    $status   = $member['contract_einspeisung_status'] ?? 'none';
    if ($status === 'signed' && $genAt && (!$tariffAt || $tariffAt <= $genAt)) {
        http_response_code(400);
        echo 'Vertrag wurde bereits unterschrieben. Generierung nur nach einer Tarifänderung möglich.';
        return;
    }

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);

    $zpLines = implode("\n", array_map(
        function ($mp) {
            $engpass = $mp['engpassleistung_kw'] ? number_format((float)$mp['engpassleistung_kw'], 2, ',', '.') . ' kWp' : '--';
            return '\\item Zählpunktnummer ' . texEscape($mp['zaehlpunkt_nr'])
                . ' --- Erzeugungsart: ' . texEscape($mp['erzeugungsart'] ?? 'Photovoltaik')
                . ', Engpassleistung: ' . $engpass;
        },
        $mps
    ));
    $anlagenBeschreibung = implode('; ', array_filter(array_map(
        function ($mp) {
            $teile = array_filter([
                $mp['anlagenadresse'] ?? null,
                $mp['gst_nr'] ? 'Gst.-Nr. ' . $mp['gst_nr'] : null,
                $mp['katastralgemeinde'] ? 'KG ' . $mp['katastralgemeinde'] : null,
            ]);
            return $teile ? implode(', ', $teile) : null;
        },
        $mps
    )));

    $signature = eegSignatureAsset();
    $ok = streamLatexPdf('einspeisevereinbarung', [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => ($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name'],
        'MITGLIED_ADRESSE'          => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'      => $member['city'],
        'MITGLIED_UID_ZEILE'        => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEIT'             => $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '--',
        'MITGLIED_IBAN'             => $member['member_iban'] ?? '--',
        'MITGLIED_BIC'              => $member['member_bic'] ?? '--',
        'EINSPEISUNG_TARIF'         => $tariff ? number_format((float)$tariff['einspeisung_ct_kwh'], 4, ',', '.') : '--',
        'TARIF_GUELTIG_AB'          => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'RAW_ZAEHLPUNKTE_LISTE'     => $zpLines,
        'ANLAGENBESCHREIBUNG'       => $anlagenBeschreibung ?: '--',
        'RAW_EEG_UNTERSCHRIFT_BILD' => $signature['var'],
        'ERSTELLT_AM'               => date('d.m.Y'),
    ], 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets']);

    if ($ok) {
        DB::execute(
            "UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END, contract_einspeisung_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

$router->post('/portal/members/:id/contract-status', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = DB::fetchOne('SELECT community_id FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $member['community_id'];
    DB::setCommunity($communityId);
    $type   = $_POST['type'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!in_array($type, ['bezug', 'einspeisung']) || !in_array($status, ['none', 'created', 'signed'])) {
        http_response_code(400); return;
    }
    $col = 'contract_' . $type . '_status';
    DB::execute("UPDATE members SET {$col} = ? WHERE id = ? AND community_id = ?", [$status, $params['id'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $mp = DB::fetchOne('SELECT community_id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $mp['community_id'];
    DB::setCommunity($communityId);

    $znr = strtoupper(trim($_POST['zaehlpunkt_nr'] ?? ''));
    $existing = DB::fetchOne(
        "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
         JOIN members m ON m.id = mp.member_id
         WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ? AND mp.id != ?",
        [$communityId, $znr, $params['mpid']]
    );
    if ($existing) {
        header('Location: /portal/members/' . $params['id'] . '?error=znr_duplicate&znr_owner='
            . urlencode($existing['first_name'] . ' ' . $existing['last_name'] . ' (KdNr ' . ($existing['kundennummer'] ?? '—') . ')'));
        exit;
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'UPDATE metering_points SET zaehlpunkt_nr=?, meter_code=?, type=?, jahresverbrauch_kwh=?, engpassleistung_kw=?, geplante_einspeisung_kwh=? WHERE id=? AND community_id=?',
        [
            $znr,
            trim($_POST['meter_code'] ?? '') ?: null,
            $_POST['type'] ?? 'consumer',
            $jahresverbrauch,
            $engpassleistung,
            $geplanteEinsp,
            $params['mpid'],
            $communityId,
        ]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $mp = DB::fetchOne('SELECT community_id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $mp['community_id'];
    DB::setCommunity($communityId);
    DB::execute('UPDATE metering_points SET active=false WHERE id=? AND community_id=?', [$params['mpid'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

// ─── Portal: Passwort ändern ────────────────────────────
$router->get('/portal/profile', function () {
    Auth::requireLogin();
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name FROM users WHERE id = ?', [Auth::userId()]);
    require ROOT . '/src/views/pages/profile.php';
});

$router->post('/portal/profile', function () {
    Auth::requireLogin();
    $email     = trim($_POST['email'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    if (!$email || !$firstName || !$lastName) {
        $error = 'Alle Felder sind Pflichtfelder.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ungültige E-Mail-Adresse.';
    } else {
        DB::execute('UPDATE users SET email=?, first_name=?, last_name=? WHERE id=?',
            [$email, $firstName, $lastName, Auth::userId()]);
        $_SESSION['user_email'] = $email;
        $success = 'Daten wurden gespeichert.';
    }
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name FROM users WHERE id = ?', [Auth::userId()]);
    require ROOT . '/src/views/pages/profile.php';
});

$router->get('/portal/password', function () {
    Auth::requireLogin();
    require ROOT . '/src/views/pages/password_change.php';
});

$router->post('/portal/password', function () {
    Auth::requireLogin();
    $userId = Auth::userId();
    $user = DB::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Aktuelles Passwort ist falsch.';
    } elseif (strlen($new) < 8) {
        $error = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($new !== $confirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute('UPDATE users SET password_hash=? WHERE id=?', [$hash, $userId]);
        $success = 'Passwort wurde erfolgreich geändert.';
    }
    require ROOT . '/src/views/pages/password_change.php';
    exit;
});

// ─── Portal: Abrechnung ─────────────────────────────────
$router->get('/portal/billing', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $runs = DB::fetchAll(
        'SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]
    );
    require ROOT . '/src/views/pages/billing.php';
});

$router->post('/portal/billing/release', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    try {
        Billing::release($runId, Auth::userId());
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Abrechnungslauf freigegeben');
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Freigabe fehlgeschlagen: ' . $e->getMessage(), true);
        DB::setCommunity($communityId);
        $runs = DB::fetchAll('SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]);
        require ROOT . '/src/views/pages/billing.php';
    }
    exit;
});

$router->post('/portal/billing/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isManager()) { http_response_code(403); echo 'Kein Zugriff.'; return; }
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT quartal FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    // Löscht kaskadierend die zugehörigen Rechnungen/Rechnungspositionen (siehe migrate_20260715.sql).
    DB::execute('DELETE FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    logAudit($communityId, 'billing.delete', 'billing_run', $params['id'], 'Abrechnungslauf ' . ($run['quartal'] ?? '?') . ' gelöscht');
    header('Location: /portal/billing?success=1');
    exit;
});

// ─── Portal: Internes Postfach (Benachrichtigungen) ─────
$router->get('/portal/postfach', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $notifications = DB::fetchAll(
        "SELECT * FROM notifications WHERE community_id = ? ORDER BY (status = 'offen') DESC, created_at DESC",
        [$communityId]
    );
    require ROOT . '/src/views/pages/postfach.php';
});

$router->post('/portal/postfach/:id/erledigt', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ? WHERE id = ? AND community_id = ?",
        [Auth::userId(), $params['id'], $communityId]
    );
    header('Location: /portal/postfach?success=1');
    exit;
});

// ─── Portal: Online-Beitrittserklärungen (Freigabe) ─────
$router->get('/portal/applications', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $applications = DB::fetchAll(
        'SELECT * FROM membership_applications WHERE community_id = ? ORDER BY (status = \'pending\') DESC, created_at DESC',
        [$communityId]
    );
    require ROOT . '/src/views/pages/applications_list.php';
});

$router->get('/portal/applications/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$application) { http_response_code(404); echo 'Nicht gefunden'; return; }
    require ROOT . '/src/views/pages/application_detail.php';
});

$router->get('/portal/applications/:id/formular', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $a = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$a) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);

    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1], true);
    $cb = fn(bool $checked) => $checked ? '\\cbon' : '\\cb';

    $eegNameEsc = texEscape($community['name']);
    $eegZvrEsc  = texEscape($community['zvr_number'] ?? '--');
    $eegOrtEsc  = texEscape(extractOrtFromAddress($community['address']));

    // Anrede-Checkboxen (Divers hat am Papierformular kein eigenes Kästchen,
    // wird stattdessen im Titel-Feld vermerkt)
    $salutation = $a['salutation'] ?? '';
    $titelDisplay = trim(($a['titel'] ?? '') . ($salutation === 'Divers' ? ' · Divers' : ''));

    $speicherStatus = $a['speicher_status'] ?? '';

    // SEPA-Block: exakt im Kasten-Layout des Papierformulars, mit echten Werten.
    // Unterschrift per 0x0-Box (wie in den Verträgen): schwebt über der Linie statt sie
    // nach unten zu schieben -- Box bleibt dadurch kompakt, ob mit oder ohne Bild.
    $sepaAssets = [];
    if (trim($a['iban'] ?? '') !== '') {
        $sepaSigBox = '';
        if (!empty($a['sepa_signature_image'])) {
            $sepaAssets['sepa_unterschrift.png'] = $a['sepa_signature_image'];
            $sepaSigBox = '\\makebox[0pt][l]{\\raisebox{0.15\\baselineskip}[0pt][0pt]{\\includegraphics[height=0.85cm]{sepa_unterschrift.png}}}';
        }
        $sepaSignedAt = $a['sepa_signed_at'] ? date('d.m.Y H:i', strtotime($a['sepa_signed_at'])) : '--';
        $sepaBlock =
            '\\begin{tcolorbox}[colback=egreenlight, colframe=egreen, boxrule=0.6pt, arc=2pt, left=7pt, right=7pt, top=3pt, bottom=3pt]' . "\n"
            . '\\small\\noindent' . "\n"
            . '\\begin{minipage}[t]{7.6cm}' . "\n"
            . '\\textbf{SEPA-Lastschrift-Mandat:}\\par\\vspace{4pt}' . "\n"
            . 'IBAN: ' . texEscape($a['iban']) . '\\\\[4pt]' . "\n"
            . 'BIC: ' . ($a['bic'] ? texEscape($a['bic']) : '--') . '\\\\[4pt]' . "\n"
            . 'Kontoinhaber:in: ' . texEscape($a['kontoinhaber'] ?: ($a['first_name'] . ' ' . $a['last_name'])) . '\\\\[4pt]' . "\n"
            . 'Adresse (falls abw.): ' . ($a['konto_adresse'] ? texEscape($a['konto_adresse']) : '--') . "\n"
            . '\\end{minipage}\\hfill' . "\n"
            . '\\begin{minipage}[t]{8.8cm}' . "\n"
            . '\\scriptsize Hiermit ermächtige ich die Erneuerbare-Energie-Gemeinschaft ' . $eegNameEsc . ', ZVR ' . $eegZvrEsc
            . ', Sitz ' . $eegOrtEsc . ', Creditor-ID: \\textbf{AT14EEG00000086499}, widerruflich, die von mir zu entrichtenden'
            . ' Zahlungen bei Fälligkeit zu Lasten meines Kontos mittels wiederkehrender SEPA-Lastschriften einzuziehen.'
            . ' Zugleich weise ich mein Kreditinstitut an, die von der ' . $eegNameEsc . ' auf mein Konto gezogenen'
            . ' SEPA-Lastschriften einzulösen. Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die'
            . ' Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten'
            . ' Bedingungen.' . "\n"
            . '\\end{minipage}\\par' . "\n"
            . '\\vspace{1cm}\\noindent' . "\n"
            . $sepaSigBox . '\\rule{6.5cm}{0.4pt}\\\\[1pt]' . "\n"
            . '{\\scriptsize Unterschrift (Kontoinhaber:in)}\\hfill{\\scriptsize Unterschrieben am ' . $sepaSignedAt . '}' . "\n"
            . '\\end{tcolorbox}';
    } else {
        $sepaBlock =
            '\\begin{tcolorbox}[colback=egreenlight, colframe=egreen, boxrule=0.6pt, arc=2pt, left=7pt, right=7pt, top=4pt, bottom=4pt]' . "\n"
            . '\\small Es wurde keine SEPA-Lastschrift vereinbart (keine IBAN angegeben).' . "\n"
            . '\\end{tcolorbox}';
    }

    // Rechtliche Zustimmungen: voller Wortlaut (nicht nur Kurzlabel) -- dieser Ausdruck ist
    // der nachvollziehbare Beleg dessen, was tatsächlich online unterschrieben wurde, und soll
    // deshalb für sich stehen können, unabhängig davon ob/wie die Website später geändert wird.
    $consentTexts = [
        'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft: Ich beantrage die Mitgliedschaft im Verein und nehme die Vereinsstatuten zur Kenntnis.',
        'zustimmung_vollmacht'           => 'Vollmacht: Ich bevollmächtige den Vorstand zur Zustimmungserklärung und Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber.',
        'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist: Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt.',
        'zustimmung_email_kommunikation' => 'E-Mail-Rechnung/-Korrespondenz: Ich stimme der Zustellung von Rechnungen und vereinsrelevanten Dokumenten per E-Mail zu.',
        'zustimmung_datenschutz'         => 'Datenschutz: Ich willige in die Verarbeitung meiner Stamm-, Erzeugungs- und Verbrauchsdaten gemäß Datenschutzerklärung ein.',
        'zustimmung_agb'                 => 'AGB \\& Tarif-/Preisblatt: Ich bestätige, die geltenden Konditionen laut Preisliste und AGB gelesen und akzeptiert zu haben.',
    ];
    $zustimmungenLines = implode("\n", array_map(
        fn($field, $text) => '\\item[' . $cb($isTrue($a[$field])) . ']  ' . $text,
        array_keys($consentTexts), $consentTexts
    ));

    $assets = ['unterschrift_beitritt.png' => $a['signature_image']] + $sepaAssets;

    streamLatexPdf('beitrittserklaerung_formular', [
        'EEG_NAME'                  => $community['name'],
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EINGEREICHT_AM'            => date('d.m.Y H:i', strtotime($a['created_at'])),
        'TITEL'                     => $titelDisplay ?: '--',
        'VORNAME'                   => $a['first_name'],
        'NACHNAME'                  => $a['last_name'],
        'ADRESSE'                   => $a['address'] . ', ' . $a['zip'] . ' ' . $a['city'],
        'TELEFON'                   => $a['phone'] ?: '--',
        'GEBURTSDATUM'              => $a['geburtsdatum'] ? date('d.m.Y', strtotime($a['geburtsdatum'])) : '--',
        'STROMLIEFERANT'            => $a['stromlieferant'] ?: '--',
        'EMAIL'                     => $a['email'],
        'BEZUG_JAHRESVERBRAUCH'     => $a['bezug_jahresverbrauch_kwh'] ? number_format((float)$a['bezug_jahresverbrauch_kwh'], 0, ',', '.') : '--',
        'EINSPEISUNG_KWP'           => $a['einspeisung_kwp'] ? number_format((float)$a['einspeisung_kwp'], 2, ',', '.') : '--',
        'EINSPEISUNG_GEPLANT'       => $a['einspeisung_geplante_kwh'] ? number_format((float)$a['einspeisung_geplante_kwh'], 0, ',', '.') : '--',
        'SPEICHER_KWH'              => $a['speicher_kwh'] ? number_format((float)$a['speicher_kwh'], 1, ',', '.') : '--',
        'ANDERE_EEG_NAME'           => $isTrue($a['andere_eeg']) ? ($a['andere_eeg_name'] ?: '--') : '--',
        'RAW_ANREDE_FRAU'           => $cb($salutation === 'Frau'),
        'RAW_ANREDE_HERR'           => $cb($salutation === 'Herr'),
        'RAW_BEZUG_CB'              => $cb($isTrue($a['bezug_gewuenscht'])),
        'RAW_EINSPEISUNG_CB'        => $cb($isTrue($a['einspeisung_gewuenscht'])),
        'RAW_SPEICHER_JA'           => $cb($speicherStatus === 'ja'),
        'RAW_SPEICHER_NEIN'         => $cb($speicherStatus === 'nein'),
        'RAW_SPEICHER_GEPLANT'      => $cb($speicherStatus === 'geplant'),
        'RAW_ANDERE_EEG_JA'         => $cb($isTrue($a['andere_eeg'])),
        'RAW_ANDERE_EEG_NEIN'       => $cb(!$isTrue($a['andere_eeg'])),
        'RAW_ZP_BEZUG_GRID'         => zpGridTikz($isTrue($a['bezug_gewuenscht']) ? $a['bezug_zaehlpunkt'] : null),
        'RAW_ZP_EINSPEISUNG_GRID'   => zpGridTikz($isTrue($a['einspeisung_gewuenscht']) ? $a['einspeisung_zaehlpunkt'] : null),
        'RAW_SEPA_BLOCK'            => $sepaBlock,
        'RAW_ZUSTIMMUNGEN_LISTE'    => $zustimmungenLines,
        'RAW_UNTERSCHRIFT_BILD'     => '\\includegraphics[height=1.3cm]{unterschrift_beitritt.png}',
        'UNTERSCHRIEBEN_DATUM'      => $a['signed_at'] ? date('d.m.Y', strtotime($a['signed_at'])) : '--',
        'UNTERSCHRIEBEN_AM'         => $a['signed_at'] ? date('d.m.Y H:i', strtotime($a['signed_at'])) : '--',
        'SIGNER_IP'                 => $a['signer_ip'] ?: '--',
    ], 'Beitrittserklaerung_' . $a['last_name'] . '.pdf', $assets);
});

$router->post('/portal/applications/:id/approve', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne(
        "SELECT * FROM membership_applications WHERE id = ? AND community_id = ? AND status = 'pending'",
        [$params['id'], $communityId]
    );
    if (!$application) { http_response_code(404); echo 'Nicht gefunden oder bereits bearbeitet'; return; }

    $result = createMemberRecord($communityId, [
        'salutation' => $application['salutation'], 'titel' => $application['titel'],
        'first_name' => $application['first_name'], 'last_name' => $application['last_name'],
        'email' => $application['email'], 'phone' => $application['phone'],
        'address' => $application['address'], 'zip' => $application['zip'], 'city' => $application['city'],
        'geburtsdatum' => $application['geburtsdatum'], 'stromlieferant' => $application['stromlieferant'],
        'speicher_status' => $application['speicher_status'], 'speicher_kwh' => $application['speicher_kwh'],
        'andere_eeg' => in_array($application['andere_eeg'], [true, 't', '1', 1], true), 'andere_eeg_name' => $application['andere_eeg_name'],
        'member_iban' => $application['iban'], 'member_bic' => $application['bic'],
        'kontoinhaber' => $application['kontoinhaber'], 'konto_adresse' => $application['konto_adresse'],
        'member_since' => date('Y-m-d'),
    ]);

    // Vom Antragsteller angegebene Zählpunkte übernehmen, damit sie nicht händisch
    // nachgetragen werden müssen. Zählernummer (Ausleseeinheit) bleibt bewusst leer --
    // die kennt man erst nach der Vor-Ort-Installation.
    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1], true);
    if ($isTrue($application['bezug_gewuenscht']) && trim($application['bezug_zaehlpunkt'] ?? '') !== '') {
        DB::execute(
            'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, jahresverbrauch_kwh, registered_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_DATE)
             ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
            [$communityId, $result['member_id'], strtoupper(trim($application['bezug_zaehlpunkt'])), 'consumer', $application['bezug_jahresverbrauch_kwh'] ?: null]
        );
    }
    if ($isTrue($application['einspeisung_gewuenscht']) && trim($application['einspeisung_zaehlpunkt'] ?? '') !== '') {
        DB::execute(
            'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE)
             ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
            [$communityId, $result['member_id'], strtoupper(trim($application['einspeisung_zaehlpunkt'])), 'producer', $application['einspeisung_kwp'] ?: null, $application['einspeisung_geplante_kwh'] ?: null]
        );
    }

    DB::execute(
        "UPDATE membership_applications SET status = 'approved', member_id = ?, bearbeitet_von = ?, bearbeitet_am = now() WHERE id = ?",
        [$result['member_id'], Auth::userId(), $application['id']]
    );
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ?
         WHERE community_id = ? AND referenz_typ = 'membership_application' AND referenz_id = ?",
        [Auth::userId(), $communityId, $application['id']]
    );
    logAudit($communityId, 'application.approve', 'member', $result['member_id'],
        'Online-Beitrittserklärung von ' . $application['first_name'] . ' ' . $application['last_name'] . ' freigegeben (KdNr ' . $result['kundennummer'] . ')');

    header('Location: /portal/members/' . $result['member_id'] . '?success=1');
    exit;
});

$router->post('/portal/applications/:id/reject', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne(
        "SELECT id, first_name, last_name FROM membership_applications WHERE id = ? AND community_id = ? AND status = 'pending'",
        [$params['id'], $communityId]
    );
    if (!$application) { http_response_code(404); echo 'Nicht gefunden oder bereits bearbeitet'; return; }

    DB::execute(
        "UPDATE membership_applications SET status = 'rejected', ablehnungsgrund = ?, bearbeitet_von = ?, bearbeitet_am = now() WHERE id = ?",
        [trim($_POST['ablehnungsgrund'] ?? '') ?: null, Auth::userId(), $application['id']]
    );
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ?
         WHERE community_id = ? AND referenz_typ = 'membership_application' AND referenz_id = ?",
        [Auth::userId(), $communityId, $application['id']]
    );
    logAudit($communityId, 'application.reject', 'membership_application', $application['id'],
        'Online-Beitrittserklärung von ' . $application['first_name'] . ' ' . $application['last_name'] . ' abgelehnt');

    header('Location: /portal/applications?success=1');
    exit;
});

// ─── Portal: EDA-Import ─────────────────────────────────
$router->get('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/eda_upload.php';
});

$router->post('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');

    if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload fehlgeschlagen (Fehlercode: ' . ($_FILES['xlsx']['error'] ?? '?') . ')';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $origName = basename($_FILES['xlsx']['name']);
    if (!str_ends_with(strtolower($origName), '.xlsx')) {
        $error = 'Nur XLSX-Dateien erlaubt.';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $savePath = '/var/www/html/storage/uploads/' . uniqid() . '_' . $origName;
    move_uploaded_file($_FILES['xlsx']['tmp_name'], $savePath);

    $communitySlug = Auth::activeCommunitySlug();
    $userId = Auth::userId();

    $cmd = sprintf(
        'python3 /var/www/html/eda-parser/parser.py --file %s --community %s --user-id %s 2>&1',
        escapeshellarg($savePath),
        escapeshellarg($communitySlug),
        escapeshellarg($userId)
    );
    $output = shell_exec($cmd);
    $result = json_decode($output, true);
    $communityId = Auth::activeCommunityId();
    if ($result === null) {
        $error = 'Parser-Fehler: ' . htmlspecialchars(substr($output ?? 'Keine Ausgabe', 0, 500));
        logAudit($communityId, 'eda.import', null, null, 'EDA-Import fehlgeschlagen: ' . substr($output ?? 'Keine Ausgabe', 0, 500), true);
    } else {
        logAudit($communityId, 'eda.import', null, null,
            'EDA-Import: ' . ($result['records'] ?? '?') . ' Datensätze importiert' . (!empty($result['warnings']) ? ', ' . count($result['warnings']) . ' Warnung(en)' : ''));
    }

    require ROOT . '/src/views/pages/eda_upload.php';
});

// ─── Portal: Einstellungen ──────────────────────────────
$router->get('/portal/settings', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $tax       = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $myUser    = DB::fetchOne('SELECT first_name, last_name, signature_image FROM users WHERE id = ?', [Auth::userId()]);
    require ROOT . '/src/views/pages/settings.php';
});

$router->post('/portal/settings/signature', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/settings?error=upload');
        exit;
    }
    DB::execute('UPDATE users SET signature_image = ? WHERE id = ?', [$signature, Auth::userId()]);
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/signature/delete', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    DB::execute('UPDATE users SET signature_image = NULL WHERE id = ?', [Auth::userId()]);
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/community', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'UPDATE communities SET name=?, address=?, iban=?, bic=?, zvr_number=?, marktpartner_id=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            $communityId,
        ]
    );
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tariff', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'INSERT INTO tariff_config (community_id, valid_from, bezug_ct_kwh, einspeisung_ct_kwh, mitgliedsbeitrag_eur)
         VALUES (?, ?, ?, ?, ?)',
        [
            $communityId,
            $_POST['valid_from'] ?? date('Y-m-d'),
            (float)str_replace(',', '.', $_POST['bezug_ct_kwh'] ?? '0'),
            (float)str_replace(',', '.', $_POST['einspeisung_ct_kwh'] ?? '0'),
            (float)str_replace(',', '.', $_POST['mitgliedsbeitrag_eur'] ?? '0'),
        ]
    );
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tax', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $taxModel = $_POST['tax_model'] ?? '';
    if (!in_array($taxModel, ['kleinunternehmer', 'standard'], true)) {
        http_response_code(400);
        echo 'Ungültiges Steuermodell.';
        return;
    }
    $taxRate = $taxModel === 'standard'
        ? (float)str_replace(',', '.', $_POST['tax_rate_percent'] ?? '20')
        : null;

    DB::execute(
        'INSERT INTO tax_config (community_id, valid_from, tax_model, tax_rate_percent, uid_number)
         VALUES (?, ?, ?, ?, ?)',
        [
            $communityId,
            $_POST['valid_from'] ?? date('Y-m-d'),
            $taxModel,
            $taxRate,
            trim($_POST['uid_number'] ?? '') ?: null,
        ]
    );
    header('Location: /portal/settings?success=1');
    exit;
});

// ─── Admin-Bereich ──────────────────────────────────────
$router->get('/admin', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT * FROM communities ORDER BY name');
    $userCount   = DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];
    $rawUsers    = DB::fetchAll('SELECT id, email, first_name, last_name, active FROM users ORDER BY last_name, first_name');
    $allRoles    = DB::fetchAll('SELECT ur.user_id, ur.role, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }
    $users = array_map(fn($u) => array_merge($u, ['roles' => $roleMap[$u['id']] ?? []]), $rawUsers);
    require ROOT . '/src/views/pages/admin.php';
});

$router->post('/admin/communities', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $name = trim($_POST['name'] ?? '');
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    DB::execute(
        'INSERT INTO communities (name, slug, marktpartner_id, address) VALUES (?, ?, ?, ?)',
        [$name, $slug, $_POST['marktpartner_id'] ?? null, $_POST['address'] ?? null]
    );
    header('Location: /admin');
    exit;
});

$router->get('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); return; }
    $members = DB::fetchAll('SELECT COUNT(*) AS cnt FROM members WHERE community_id = ?', [$params['id']]);
    require ROOT . '/src/views/pages/admin_community.php';
});

$router->get('/admin/users/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user        = DB::fetchOne('SELECT id, email, first_name, last_name, active FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }
    $roles       = DB::fetchAll('SELECT ur.*, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    require ROOT . '/src/views/pages/admin_user.php';
});

$router->post('/admin/users/:id/roles', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $communityId = $_POST['community_id'] ?? null;
    $role = $_POST['role'] ?? '';
    if (!in_array($role, ['platform_admin', 'manager', 'member'])) { http_response_code(400); return; }
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role]
    );
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/roles/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    DB::execute('DELETE FROM user_roles WHERE id = ?', [$_POST['role_id']]);
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    if ($params['id'] === Auth::userId()) { http_response_code(400); echo 'Der eigene Account kann nicht gelöscht werden.'; return; }
    $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }

    // Löscht kaskadierend Rollenzuweisungen (user_roles); verknüpfte Mitglieder bleiben erhalten,
    // verlieren nur die Login-Verknüpfung (siehe migrate_20260715.sql).
    DB::execute('DELETE FROM users WHERE id = ?', [$params['id']]);
    header('Location: /admin?success=1');
    exit;
});

$router->post('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            isset($_POST['active']) ? 'true' : 'false',
            $params['id'],
        ]
    );
    logAudit($params['id'], 'community.update', 'community', $params['id'], 'EEG "' . trim($_POST['name'] ?? '') . '" bearbeitet');
    header('Location: /admin?success=1');
    exit;
});

// ─── Admin: Aktivitätslog ────────────────────────────────
$router->get('/admin/log', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $filterCommunity = $_GET['community_id'] ?? '';
    $params = [];
    $where = '1=1';
    if ($filterCommunity !== '') {
        $where .= ' AND al.community_id = ?';
        $params[] = $filterCommunity;
    }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where
         ORDER BY al.created_at DESC LIMIT 500",
        $params
    );
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    require ROOT . '/src/views/pages/admin_log.php';
});

$router->dispatch();
