<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// Autoload
foreach (['DB', 'Auth', 'Router', 'Billing'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}

Auth::start();

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
    $community = DB::fetchOne('SELECT id FROM communities WHERE slug = $1 AND active = true', [$slug]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'Nicht gefunden']); return; }

    DB::setCommunity($community['id']);

    // Aggregierte ESP-Daten letzte Stunde (nur Summen, kein Personenbezug)
    $agg = DB::fetchOne(
        "SELECT
            COALESCE(SUM(power_bezug_w), 0)        AS total_bezug_w,
            COALESCE(SUM(power_einspeisung_w), 0)  AS total_einspeisung_w,
            COUNT(DISTINCT metering_point_id)       AS active_meters
         FROM esp_measurements
         WHERE community_id = $1 AND time >= now() - INTERVAL '2 minutes'",
        [$community['id']]
    );

    // Tagesgesamterzeugung
    $today = DB::fetchOne(
        "SELECT COALESCE(SUM(energy_einspeisung_wh), 0) AS today_wh
         FROM esp_measurements
         WHERE community_id = $1 AND time >= CURRENT_DATE
         ORDER BY time DESC LIMIT 1",
        [$community['id']]
    );

    // Zeitreihe letzte 2 Stunden (5-Min-Aggregate)
    $series = DB::fetchAll(
        "SELECT
            time_bucket('5 minutes', time) AS bucket,
            SUM(power_bezug_w)             AS bezug_w,
            SUM(power_einspeisung_w)       AS einspeisung_w
         FROM esp_measurements
         WHERE community_id = $1 AND time >= now() - INTERVAL '2 hours'
         GROUP BY bucket ORDER BY bucket",
        [$community['id']]
    );

    $bezug = (int)($agg['total_bezug_w'] ?? 0);
    $einsp = (int)($agg['total_einspeisung_w'] ?? 0);
    $autarkie = $bezug > 0 ? min(100, round($einsp / $bezug * 100)) : 0;

    echo json_encode([
        'bezug_w'      => $bezug,
        'einspeisung_w'=> $einsp,
        'autarkie_pct' => $autarkie,
        'today_kwh'    => round(($today['today_wh'] ?? 0) / 1000, 2),
        'active_meters'=> (int)($agg['active_meters'] ?? 0),
        'series'       => $series,
    ]);
});

// Community-Suche für Autovervollständigung
$router->get('/api/communities/search', function () {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $results = DB::fetchAll(
        'SELECT name, slug FROM communities WHERE active = true AND name ILIKE $1 LIMIT 10', [$q]
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
    if ($token) {
        // Mail senden (SMTP-Konfiguration noch offen)
        $resetUrl = 'https://' . getenv('DOMAIN') . '/portal/reset-password?token=' . $token;
        // TODO: Mail via SMTP
    }
    $success = 'Falls die E-Mail existiert, wurde ein Reset-Link versendet.';
    require ROOT . '/src/views/pages/forgot_password.php';
});

// ─── Portal: Dashboard (Mitglied + Verwalter) ───────────
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

// ─── Portal: Mitglied ───────────────────────────────────
$router->get('/portal/invoices', function () {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $userId = Auth::userId();
    $member = DB::fetchOne('SELECT id FROM members WHERE user_id = $1 AND community_id = $2', [$userId, $communityId]);
    if (!$member) { http_response_code(404); echo 'Mitglied nicht gefunden'; return; }
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = $1 ORDER BY i.created_at DESC',
        [$member['id']]
    );
    require ROOT . '/src/views/pages/invoices.php';
});

$router->get('/portal/invoices/:id/pdf', function ($params) {
    Auth::requireLogin();
    $invoice = DB::fetchOne('SELECT * FROM invoices WHERE id = $1', [$params['id']]);
    if (!$invoice || !$invoice['pdf_path'] || !file_exists($invoice['pdf_path'])) {
        http_response_code(404); echo 'PDF nicht gefunden'; return;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $invoice['rechnungsnummer'] . '.pdf"');
    readfile($invoice['pdf_path']);
});

// ─── Portal: Verwalter ──────────────────────────────────
$router->get('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        'SELECT m.*, COUNT(mp.id) AS metering_point_count
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         WHERE m.community_id = $1
         GROUP BY m.id ORDER BY m.last_name, m.first_name',
        [$communityId]
    );
    require ROOT . '/src/views/pages/member_list.php';
});

$router->get('/portal/billing', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $runs = DB::fetchAll(
        'SELECT * FROM billing_runs WHERE community_id = $1 ORDER BY quartal DESC', [$communityId]
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
        require ROOT . '/src/views/pages/billing.php';
    }
    exit;
});

$router->get('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/eda_upload.php';
});

$router->post('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');

    if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload fehlgeschlagen';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $communityId   = Auth::activeCommunityId();
    $communitySlug = Auth::activeCommunitySlug();
    $tmpPath = $_FILES['xlsx']['tmp_name'];
    $origName = basename($_FILES['xlsx']['name']);

    // Sicherheitscheck: nur XLSX
    if (!str_ends_with(strtolower($origName), '.xlsx')) {
        $error = 'Nur XLSX-Dateien erlaubt';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $savePath = '/var/www/html/storage/uploads/' . uniqid() . '_' . $origName;
    move_uploaded_file($tmpPath, $savePath);

    // Parser aufrufen
    $cmd = sprintf(
        'python3 /app/eda-parser/parser.py --file %s --community %s --user-id %s 2>&1',
        escapeshellarg($savePath),
        escapeshellarg($communitySlug),
        escapeshellarg(Auth::userId())
    );
    $output = shell_exec($cmd);
    $result = json_decode($output, true);

    require ROOT . '/src/views/pages/eda_upload.php';
});

$router->get('/portal/settings', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = $1', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = $1 ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $tax       = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = $1 ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    require ROOT . '/src/views/pages/settings.php';
});

// ─── Admin-Bereich ──────────────────────────────────────
$router->get('/admin', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT * FROM communities ORDER BY name');
    $userCount   = DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];
    require ROOT . '/src/views/pages/admin.php';
});

$router->post('/admin/communities', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $name = trim($_POST['name'] ?? '');
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    DB::execute(
        'INSERT INTO communities (name, slug, marktpartner_id, address) VALUES ($1, $2, $3, $4)',
        [$name, $slug, $_POST['marktpartner_id'] ?? null, $_POST['address'] ?? null]
    );
    header('Location: /admin');
    exit;
});

$router->dispatch();
