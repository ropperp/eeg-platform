<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

foreach (['DB', 'Auth', 'Router', 'Billing'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}

Auth::start();

/**
 * Ruft den latex-service auf und streamt das PDF direkt an den Browser.
 * @param string $template  Template-Name ohne .tex
 * @param array  $vars      Platzhalter-Werte (werden im Service escaped)
 * @param string $filename  Dateiname für Content-Disposition
 */
function streamLatexPdf(string $template, array $vars, string $filename): void
{
    $url     = (getenv('LATEX_SERVICE_URL') ?: 'http://latex-service:3210') . '/generate';
    $apiKey  = getenv('LATEX_API_KEY') ?: 'dev-key';
    $payload = json_encode(['template' => $template, 'vars' => $vars]);

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
        $detail = is_string($body) ? htmlspecialchars(substr($body, 0, 300)) : 'latex-service nicht erreichbar';
        echo "<pre>PDF-Generierung fehlgeschlagen (HTTP $code):\n$detail</pre>";
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
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
        if ($it['type'] === 'bezug')       $bezugItem       = $it;
        if ($it['type'] === 'einspeisung') $einspeisungItem = $it;
        if ($it['type'] === 'beitrag')     $beitragItem     = $it;
    }

    $steuerHinweis = 'Gem\\"{a}\\ss{} \\S{} 6 Abs.\\,1 Z 27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';

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
        'BEZUG_KWH'             => $bezugItem ? number_format($bezugItem['kwh'], 2, ',', '.') : '0,00',
        'BEZUG_TARIF'           => $bezugItem ? number_format($bezugItem['rate_ct'], 4, ',', '.') : '0,0000',
        'BEZUG_BETRAG'          => $bezugItem ? number_format($bezugItem['amount_eur'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_KWH'       => $einspeisungItem ? number_format($einspeisungItem['kwh'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_TARIF'     => $einspeisungItem ? number_format($einspeisungItem['rate_ct'], 4, ',', '.') : '0,0000',
        'EINSPEISUNG_BETRAG'    => $einspeisungItem ? number_format($einspeisungItem['amount_eur'], 2, ',', '.') : '0,00',
        'MITGLIEDSBEITRAG'      => $beitragItem ? number_format($beitragItem['amount_eur'], 2, ',', '.') : '0,00',
        'SUMME_NETTO'           => number_format($invoice['amount_brutto'], 2, ',', '.'),
        'SUMME_BRUTTO'          => number_format($invoice['amount_brutto'], 2, ',', '.'),
        'STEUER_HINWEIS'        => $steuerHinweis,
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
                COALESCE(SUM(i.amount_brutto) FILTER (WHERE i.paid_at IS NULL), 0) AS open_amount
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         LEFT JOIN invoices i ON i.member_id = m.id AND i.paid_at IS NULL
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.last_name, m.first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/member_list.php';
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

    // User-Account anlegen falls E-Mail neu
    $email = strtolower(trim($_POST['email']));
    $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    if (!$user) {
        $tempPw = bin2hex(random_bytes(8));
        $hash = password_hash($tempPw, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute(
            'INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)',
            [$email, $hash, trim($_POST['first_name']), trim($_POST['last_name'])]
        );
        $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    }

    // Mitglied anlegen
    DB::execute(
        'INSERT INTO members (community_id, user_id, salutation, first_name, last_name, company_name, address, zip, city, email, phone, invoice_uid, member_iban, member_bic, member_since, member_until)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            $user['id'],
            $_POST['salutation'] ?? null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['company_name'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            $email,
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['invoice_uid'] ?? '') ?: null,
            trim($_POST['member_iban'] ?? '') ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            $_POST['member_since'] ?: date('Y-m-d'),
            $_POST['member_until'] ?: '2099-12-31',
        ]
    );

    // Rolle in user_roles eintragen
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $user['id'], 'member']
    );

    // Temp-Passwort anzeigen falls neuer User
    if (isset($tempPw)) {
        $successTempPw = $tempPw;
        $successEmail  = $email;
        $members = DB::fetchAll(
            'SELECT m.*, COUNT(mp.id) AS metering_point_count FROM members m
             LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
             WHERE m.community_id = ? GROUP BY m.id ORDER BY m.last_name, m.first_name',
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
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); return; }

    DB::execute(
        'UPDATE members SET salutation=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, member_since=?, member_until=? WHERE id=?',
        [
            $_POST['salutation'] ?? null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['company_name'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['invoice_uid'] ?? '') ?: null,
            trim($_POST['member_iban'] ?? '') ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            $_POST['member_since'] ?: date('Y-m-d'),
            $_POST['member_until'] ?: '2099-12-31',
            $params['id'],
        ]
    );
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
    require ROOT . '/src/views/pages/member_detail.php';
});

$router->post('/portal/members/:id/metering-points', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); return; }

    $znr = strtoupper(trim($_POST['zaehlpunkt_nr'] ?? ''));
    if (!$znr) { header('Location: /portal/members/' . $params['id'] . '?error=znr'); exit; }

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, registered_at)
         VALUES (?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $member['id'], $znr, $_POST['type'] ?? 'consumer', trim($_POST['meter_code'] ?? '') ?: null]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id/contract/bezug', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member  = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $mps     = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    DB::execute("UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END WHERE id = ?", [$params['id']]);

    // Zählpunkte-Tabellenzeilen aufbauen (LaTeX)
    $zpLines = empty($mps) ? "\\textit{Kein Bezugs-Z\\\"ahlpunkt registriert} & -- \\\\\n"
        : implode("\n", array_map(fn($mp) => $mp['zaehlpunkt_nr'] . ' & ' . ($mp['meter_code'] ?? '--') . ' \\\\', $mps));

    streamLatexPdf('bezugsvereinbarung', [
        'EEG_NAME'              => $community['name'],
        'EEG_ADRESSE'           => $community['address'] ?? '',
        'EEG_ZVR'               => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'   => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'              => $community['iban'] ?? '--',
        'EEG_ORT'               => explode(',', $community['address'] ?? '')[0],
        'MITGLIED_NAME'         => ($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name'],
        'MITGLIED_ADRESSE'      => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'  => $member['city'],
        'MITGLIED_UID_ZEILE'    => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'BEZUG_TARIF'           => $tariff ? number_format($tariff['bezug_ct_kwh'], 4, ',', '.') : '--',
        'MITGLIEDSBEITRAG'      => $tariff ? number_format($tariff['mitgliedsbeitrag_eur'], 2, ',', '.') : '--',
        'TARIF_GUELTIG_AB'      => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'ZAEHLPUNKTE_TABELLE'   => $zpLines,
        'ERSTELLT_AM'           => date('d.m.Y'),
    ], 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf');
});

$router->get('/portal/members/:id/contract/einspeisung', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member  = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $mps     = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    DB::execute("UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END WHERE id = ?", [$params['id']]);

    $i = 1;
    $zpLines = empty($mps) ? "1 & \\textit{Kein Einspeise-Z\\\"ahlpunkt registriert} & -- \\\\\n"
        : implode("\n", array_map(fn($mp) => ($i++) . ' & ' . $mp['zaehlpunkt_nr'] . ' & ' . ($mp['meter_code'] ?? '--') . ' \\\\', $mps));

    streamLatexPdf('einspeisevereinbarung', [
        'EEG_NAME'              => $community['name'],
        'EEG_ADRESSE'           => $community['address'] ?? '',
        'EEG_ZVR'               => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'   => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'              => $community['iban'] ?? '--',
        'EEG_ORT'               => explode(',', $community['address'] ?? '')[0],
        'MITGLIED_NAME'         => ($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name'],
        'MITGLIED_ADRESSE'      => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'  => $member['city'],
        'MITGLIED_UID_ZEILE'    => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEIT'         => $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '--',
        'MITGLIED_IBAN'         => $member['member_iban'] ?? '--',
        'MITGLIED_BIC'          => $member['member_bic'] ?? '--',
        'EINSPEISUNG_TARIF'     => $tariff ? number_format($tariff['einspeisung_ct_kwh'], 4, ',', '.') : '--',
        'TARIF_GUELTIG_AB'      => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'ZAEHLPUNKTE_TABELLE'   => $zpLines,
        'ERSTELLT_AM'           => date('d.m.Y'),
    ], 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf');
});

$router->post('/portal/members/:id/contract-status', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $type   = $_POST['type'] ?? ''; // bezug | einspeisung
    $status = $_POST['status'] ?? ''; // created | signed
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
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'UPDATE metering_points SET zaehlpunkt_nr=?, meter_code=?, type=? WHERE id=? AND community_id=?',
        [
            strtoupper(trim($_POST['zaehlpunkt_nr'] ?? '')),
            trim($_POST['meter_code'] ?? '') ?: null,
            $_POST['type'] ?? 'consumer',
            $params['mpid'],
            $communityId,
        ]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute('UPDATE metering_points SET active=false WHERE id=? AND community_id=?', [$params['mpid'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

// ─── Portal: Passwort ändern ────────────────────────────
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
    try {
        Billing::release($runId, Auth::userId());
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $communityId = Auth::activeCommunityId();
        DB::setCommunity($communityId);
        $runs = DB::fetchAll('SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]);
        require ROOT . '/src/views/pages/billing.php';
    }
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
    if ($result === null) {
        $error = 'Parser-Fehler: ' . htmlspecialchars(substr($output ?? 'Keine Ausgabe', 0, 500));
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
    require ROOT . '/src/views/pages/settings.php';
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
            isset($_POST['active']) ? true : false,
            $params['id'],
        ]
    );
    header('Location: /admin?success=1');
    exit;
});

$router->dispatch();
