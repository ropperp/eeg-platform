<?php

declare(strict_types=1);

/**
 * Portal-Routen (/portal/...): Login, Mitglieder-Self-Service und EEG-Verwaltung.
 *
 * Wird von public/index.php eingebunden (gleicher globaler Gültigkeitsbereich: $router, ROOT,
 * Hilfsfunktionen). Reihenfolge der Dateien dort beachten: die erste passende Route gewinnt.
 *
 * @var Router $router
 */

use App\Support\Services;

// ─── Portal: Login ──────────────────────────────────────
$router->get('/portal/login', function () {
    if (Auth::check()) { header('Location: /portal/dashboard'); exit; }
    // Anmeldung über Keycloak (AUTH_PROVIDER=keycloak, docs/adr/0007-keycloak.md). Ist Keycloak nicht
    // erreichbar, bleibt es bei der bisherigen Login-Seite mit Hinweis.
    Services::keycloakAuth()->login()?->send();
    if (Services::keycloakAuth()->showOutageNotice()) {
        $notice = 'Der Anmeldedienst ist gerade nicht erreichbar. Sie können sich hier wie gewohnt anmelden.';
    }
    require ROOT . '/src/views/pages/' . (platformDesign() === 'glas' ? 'login_glass.php' : 'login.php');
});

// Bisherige Anmeldung (Formular, 2FA, Passkey): bei AUTH_PROVIDER=keycloak nur, solange Keycloak nicht
// erreichbar ist. Sonst ginge sie an Brute-Force-Schutz und Google-Verknüpfung von Keycloak vorbei.
function requireLocalLogin(): void
{
    if (!Services::keycloakAuth()->localLoginAllowed()) {
        header('Location: /portal/login');
        exit;
    }
}

$router->get('/portal/auth/callback', fn() => Services::keycloakAuth()->callback($_GET)->send());
$router->get('/portal/auth/google', fn() => Services::keycloakAuth()->action('google')->send());
$router->get('/portal/auth/passkey', fn() => Services::keycloakAuth()->action('passkey')->send());
$router->post('/portal/auth/google/unlink', fn() => Services::keycloakAuth()->unlinkGoogle()->send());
$router->post('/portal/auth/passkey/delete', fn() => Services::keycloakAuth()->deletePasskey($_POST)->send());

$router->post('/portal/login', function () {
    requireLocalLogin();
    $email    = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    // Vor dem eigentlichen Passwort-Check gegen Brute-Force sperren (OWASP-Audit 13.08.2026) --
    // dieselbe generische Fehlermeldung wie bei falschem Passwort, damit die Sperre selbst
    // keine Rückschlüsse zulässt (z.B. ob die E-Mail überhaupt existiert).
    if (RateLimiter::isLoginBlocked($email)) {
        $error = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        require ROOT . '/src/views/pages/' . (platformDesign() === 'glas' ? 'login_glass.php' : 'login.php');
        exit;
    }
    $user = Auth::checkPassword($email, $password);
    if (!$user) {
        RateLimiter::registerLoginFailure($email);
        $error = 'E-Mail oder Passwort falsch.';
        require ROOT . '/src/views/pages/' . (platformDesign() === 'glas' ? 'login_glass.php' : 'login.php');
        exit;
    }
    RateLimiter::resetLoginAttempts($email);
    // Zweiter Faktor aktiv? Dann Session NOCH NICHT aufbauen, sondern Code abfragen.
    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        $_SESSION['2fa_pending_user'] = $user['id'];
        header('Location: /portal/login/2fa');
        exit;
    }
    Auth::establishSession($user['id']);
    header('Location: /portal/dashboard');
    exit;
});

$router->get('/portal/login/2fa', function () {
    if (empty($_SESSION['2fa_pending_user'])) { header('Location: /portal/login'); exit; }
    require ROOT . '/src/views/pages/login_2fa.php';
});

$router->post('/portal/login/2fa', function () {
    requireLocalLogin();
    $uid = $_SESSION['2fa_pending_user'] ?? null;
    if (!$uid) { header('Location: /portal/login'); exit; }
    // Brute-Force-Schutz für den 6-stelligen Code (OWASP-Audit 13.08.2026) -- ohne Sperre wären
    // die 1 Mio. Kombinationen bei automatisierten Anfragen realistisch durchprobierbar, sobald
    // ein Passwort einmal geleakt ist.
    if (RateLimiter::isTotpBlocked($uid)) {
        $error = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        require ROOT . '/src/views/pages/login_2fa.php';
        exit;
    }
    $u = DB::fetchOne('SELECT totp_secret FROM users WHERE id = ? AND active = true', [$uid]);
    if ($u && !empty($u['totp_secret']) && totpVerify(totpSecretFromStorage($u['totp_secret']), $_POST['code'] ?? '')) {
        RateLimiter::resetTotpAttempts($uid);
        unset($_SESSION['2fa_pending_user']);
        Auth::establishSession($uid);
        header('Location: /portal/dashboard');
        exit;
    }
    RateLimiter::registerTotpFailure($uid);
    $error = 'Code ungültig oder abgelaufen. Bitte den aktuellen 6-stelligen Code eingeben.';
    require ROOT . '/src/views/pages/login_2fa.php';
    exit;
});

// ─── Passkey-Anmeldung (WebAuthn, 17.11.2026) ───────────────────────────────────────
// Ablauf Anmeldung: Browser holt eine Challenge (…/options), das Gerät signiert sie nach
// Fingerabdruck/Gesicht/PIN, der Server prüft die Signatur gegen den gespeicherten öffentlichen
// Schlüssel (…/verify). Ein Passkey mit Benutzerprüfung ersetzt Passwort UND 2FA -- deshalb
// entfällt hier die TOTP-Abfrage. Die Challenge liegt in der Session und gilt genau einmal.
function passkeyJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function passkeyRpId(): string
{
    return WebAuthn::rpIdForHost((string)($_SERVER['HTTP_HOST'] ?? ''), (string)(getenv('DOMAIN') ?: ''));
}

/** Legt eine neue Einmal-Challenge in der Session ab und liefert sie (Base64URL). */
function passkeyNewChallenge(string $kind): string
{
    $c = WebAuthn::b64uEncode(random_bytes(32));
    $_SESSION['passkey_challenge'] = ['c' => $c, 'k' => $kind, 't' => time()];
    return $c;
}

/** Holt die Challenge und entwertet sie sofort (Einmalverwendung); null wenn fehlt/abgelaufen/falsche Art. */
function passkeyTakeChallenge(string $kind): ?string
{
    $ch = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if (!is_array($ch) || ($ch['k'] ?? '') !== $kind || time() - (int)($ch['t'] ?? 0) > 300) {
        return null;
    }
    return (string)$ch['c'];
}

/** Die vom Browser als JSON-Feld "data" geschickte Antwort lesen. */
function passkeyPostedData(): array
{
    $d = json_decode((string)($_POST['data'] ?? ''), true);
    return is_array($d) ? $d : [];
}

$router->post('/portal/login/passkey/options', function () {
    requireLocalLogin();
    if (!RateLimiter::allow('passkey_opts', clientIp(), 60, 600)) {
        passkeyJson(['error' => 'Zu viele Versuche. Bitte später erneut versuchen.'], 429);
    }
    passkeyJson([
        'challenge'        => passkeyNewChallenge('get'),
        'rpId'             => passkeyRpId(),
        'timeout'          => 120000,
        'userVerification' => 'required',
        // Leer = "erkennbare" Passkeys: das Gerät zeigt selbst, welche Konten es für diese Seite kennt.
    ]);
});

$router->post('/portal/login/passkey/verify', function () {
    requireLocalLogin();
    $fail = fn(): never => passkeyJson(['error' => 'Passkey konnte nicht bestätigt werden. Bitte mit Passwort anmelden.'], 401);
    if (!RateLimiter::allow('passkey_login', clientIp(), 20, 900)) {
        passkeyJson(['error' => 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.'], 429);
    }
    $challenge = passkeyTakeChallenge('get');
    $d = passkeyPostedData();
    $r = $d['response'] ?? null;
    if ($challenge === null || !is_array($r) || !is_string($d['id'] ?? null)) $fail();
    try {
        $row = DB::fetchOne(
            'SELECT p.id, p.user_id, p.public_key_pem, p.sign_count
             FROM user_passkeys p JOIN users u ON u.id = p.user_id
             WHERE p.credential_id = ? AND u.active = true',
            [$d['id']]
        );
        if (!$row) $fail();
        $userHandle = isset($r['userHandle']) && $r['userHandle'] !== '' ? WebAuthn::b64uDecode((string)$r['userHandle']) : null;
        if ($userHandle !== null && $userHandle !== (string)$row['user_id']) $fail();
        $count = WebAuthn::verifyAssertion(
            WebAuthn::b64uDecode((string)($r['clientDataJSON'] ?? '')),
            WebAuthn::b64uDecode((string)($r['authenticatorData'] ?? '')),
            WebAuthn::b64uDecode((string)($r['signature'] ?? '')),
            $row['public_key_pem'], (int)$row['sign_count'], $challenge, passkeyRpId()
        );
    } catch (\RuntimeException $e) {
        error_log('[passkey-login] ' . $e->getMessage());
        $fail();
    }
    DB::execute('UPDATE user_passkeys SET sign_count = ?, last_used_at = now() WHERE id = ?', [$count, $row['id']]);
    unset($_SESSION['2fa_pending_user']);
    Auth::establishSession($row['user_id']);
    passkeyJson(['ok' => true, 'redirect' => '/portal/dashboard']);
});

$router->post('/portal/profile/passkeys/options', function () {
    Auth::requireLogin();
    $own = DB::fetchAll('SELECT credential_id FROM user_passkeys WHERE user_id = ?', [Auth::userId()]);
    if (count($own) >= 10) {
        passkeyJson(['error' => 'Es sind höchstens 10 Passkeys je Konto möglich.'], 422);
    }
    $u = DB::fetchOne('SELECT id, email, first_name, last_name FROM users WHERE id = ?', [Auth::userId()]);
    passkeyJson([
        'challenge' => passkeyNewChallenge('create'),
        'rp'        => ['id' => passkeyRpId(), 'name' => 'Strom für alle'],
        'user'      => [
            'id'          => WebAuthn::b64uEncode((string)$u['id']),
            'name'        => $u['email'],
            'displayName' => trim($u['first_name'] . ' ' . $u['last_name']),
        ],
        'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'attestation'            => 'none',
        'timeout'                => 120000,
        'excludeCredentials'     => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']], $own),
    ]);
});

$router->post('/portal/profile/passkeys/verify', function () {
    Auth::requireLogin();
    $challenge = passkeyTakeChallenge('create');
    $d = passkeyPostedData();
    $r = $d['response'] ?? null;
    if ($challenge === null || !is_array($r)) {
        passkeyJson(['error' => 'Die Anfrage ist abgelaufen. Bitte erneut versuchen.'], 400);
    }
    try {
        $res = WebAuthn::verifyRegistration(
            WebAuthn::b64uDecode((string)($r['clientDataJSON'] ?? '')),
            WebAuthn::b64uDecode((string)($r['attestationObject'] ?? '')),
            $challenge, passkeyRpId()
        );
    } catch (\RuntimeException $e) {
        error_log('[passkey-register] ' . $e->getMessage());
        passkeyJson(['error' => 'Der Passkey konnte nicht gespeichert werden.'], 422);
    }
    $label = trim(strip_tags((string)($d['label'] ?? '')));
    $label = mb_substr($label !== '' ? $label : 'Passkey', 0, 60);
    try {
        DB::execute(
            'INSERT INTO user_passkeys (user_id, credential_id, public_key_pem, sign_count, label) VALUES (?, ?, ?, ?, ?)',
            [Auth::userId(), WebAuthn::b64uEncode($res['credentialId']), $res['publicKeyPem'], $res['signCount'], $label]
        );
    } catch (\Throwable $e) {
        passkeyJson(['error' => 'Dieser Passkey ist bereits gespeichert.'], 409);
    }
    passkeyJson(['ok' => true]);
});

$router->post('/portal/profile/passkeys/delete', function () {
    Auth::requireLogin();
    DB::execute('DELETE FROM user_passkeys WHERE id = ? AND user_id = ?', [$_POST['id'] ?? '', Auth::userId()]);
    header('Location: /portal/profile?success=' . urlencode('Passkey wurde entfernt.'));
    exit;
});

$router->get('/portal/logout', fn() => Services::keycloakAuth()->logout()->send());

/**
 * Bestätigt das Pre-Launch-Hinweis-Popup (nur Mitglieder-Ansicht, siehe layouts/portal.php) --
 * setzt die Session-Flag und springt zur Seite zurück, auf der das Popup aufgerufen wurde
 * (Patrick, 30.07.2026: soll nicht jedes Mal auf das Dashboard umleiten). return_to wird gegen
 * einen /portal/-Präfix geprüft, damit daraus kein Open-Redirect auf fremde Ziele werden kann.
 */
$router->post('/portal/ack-prelaunch', function () {
    Auth::requireLogin();
    $_SESSION['prelaunch_ack'] = true;
    $returnTo = $_POST['return_to'] ?? '/portal/dashboard';
    if (!str_starts_with($returnTo, '/portal/')) { $returnTo = '/portal/dashboard'; }
    header('Location: ' . $returnTo);
    exit;
});

/**
 * "Später"-Button der Gutschriften-Login-Erinnerung (siehe layouts/portal.php) -- unterdrückt
 * den Hinweis nur für den Rest DIESER Session, nicht dauerhaft: die Flag wird bei jedem Login
 * zurückgesetzt (Auth::establishSession()), damit offene Gutschriften nicht in Vergessenheit
 * geraten (Patrick, 26.09.2026). Gleiches Open-Redirect-Schutzmuster wie /portal/ack-prelaunch.
 */
$router->post('/portal/gutschriften-erinnerung/spaeter', function () {
    Auth::requireLogin();
    $_SESSION['gutschriften_reminder_dismissed'] = true;
    $returnTo = $_POST['return_to'] ?? '/portal/dashboard';
    if (!str_starts_with($returnTo, '/portal/')) { $returnTo = '/portal/dashboard'; }
    header('Location: ' . $returnTo);
    exit;
});

$router->get('/portal/forgot-password', function () {
    require ROOT . '/src/views/pages/forgot_password.php';
});

$router->post('/portal/forgot-password', function () {
    $email = strtolower(trim($_POST['email'] ?? ''));
    // Bewusst immer dieselbe Meldung, unabhängig davon ob die E-Mail existiert, das Limit erreicht
    // ist oder der Mailversand geklappt hat -- sonst ließe sich über die Fehlermeldung erraten,
    // welche Adressen als Login registriert sind.
    $success = 'Falls die E-Mail existiert, wurde ein Reset-Link versendet.';
    require ROOT . '/src/views/pages/forgot_password.php';

    // Antwort SOFORT zum Browser schicken, die eigentliche Arbeit (Token anlegen, Mail senden)
    // danach: so antwortet die Seite für bekannte und unbekannte Adressen gleich schnell (kein
    // Zeit-Seitenkanal zum Erraten von Konten) und der Nutzer wartet nicht auf den Mailserver.
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

    // Begrenzung: höchstens 3 Mails pro Adresse und 10 pro IP-Adresse und Stunde (jeder Aufruf
    // zählt, auch für nicht existierende Adressen -- gleiches Verhalten nach außen).
    if (!RateLimiter::allow('forgot:email', $email, 3, 3600) || !RateLimiter::allow('forgot:ip', clientIp(), 10, 3600)) {
        return;
    }
    $token = Auth::createResetToken($email);
    if ($token) {
        try {
            $user = DB::fetchOne('SELECT first_name FROM users WHERE email = ?', [$email]);
            $link = htmlspecialchars(passwordResetLink($token));
            $mail = renderMailTemplate('password_reset', array_merge([
                'vorname'     => htmlspecialchars($user['first_name'] ?? ''),
                'link'        => $link,
                'gueltigkeit' => 'Stunde',
            ], salutationVarsForEmail($email)),
                'Passwort zurücksetzen – Strom für alle',
                '<p>Liebes Mitglied,</p>'
                . '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>'
                . '<p><a href="{{link}}">{{link}}</a></p>'
                . '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
            );
            Mailer::send($email, $mail['subject'], $mail['body']);
        } catch (\Throwable $e) {
            error_log('[forgot_password_mail] ' . $e->getMessage());
        }
    }
});

$router->get('/portal/reset-password', function () {
    $token = $_GET['token'] ?? '';
    $valid = $token !== '' && (bool)DB::fetchOne(
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > now()',
        [hashResetToken($token)]
    );
    require ROOT . '/src/views/pages/reset_password.php';
});

$router->post('/portal/reset-password', function () {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $valid = $token !== '' && (bool)DB::fetchOne(
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > now()',
        [hashResetToken($token)]
    );
    if (!$valid) {
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if (strlen($password) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen haben.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if ($password !== $password2) {
        $error = 'Die beiden Passwörter stimmen nicht überein.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if (isPasswordBreached($password)) {
        $error = 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    Auth::resetPassword($token, $password);
    header('Location: /portal/login?success=password_reset');
    exit;
});

// ─── Portal: Dashboard ──────────────────────────────────
/**
 * Gespeicherte Anordnung der Startseiten-Kästen des angemeldeten Benutzers ($key = admin |
 * manager | member), oder null (= Standard). Demo-Logins: nur aus der Sitzung. Fehlt die Tabelle
 * (Migration noch nicht eingespielt), gilt still die Standard-Reihenfolge.
 */
function dashboardLayout(string $key): ?array
{
    if (Auth::isDemo()) {
        return $_SESSION['dashboard_layouts'][$key] ?? null;
    }
    $userId = Auth::userId();
    if (Auth::isImpersonating()) {
        // Kundenansicht: die Startseite so zeigen, wie das Mitglied sie sich angeordnet hat
        $imp = Auth::impersonation();
        $m = DB::fetchOne('SELECT user_id FROM members WHERE id = ?', [$imp['member_id']]);
        $userId = $m['user_id'] ?? null;
        if (!$userId) return null;
    }
    try {
        $row = DB::fetchOne('SELECT layout FROM user_dashboard_layouts WHERE user_id = ? AND dashboard_key = ?', [$userId, $key]);
    } catch (\Throwable $e) {
        return null;
    }
    return $row ? (json_decode((string)$row['layout'], true) ?: null) : null;
}

/** Anordnung der Startseiten-Kästen speichern bzw. zurücksetzen (Fetch aus dashboard-widgets.js). */
$router->post('/portal/dashboard-layout', function () {
    Auth::requireLogin();
    header('Content-Type: application/json; charset=UTF-8');
    $key = (string)($_POST['dashboard'] ?? '');
    if (!in_array($key, dashboardKeys(), true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Startseite.']);
        return;
    }
    $reset = ($_POST['reset'] ?? '') === '1';
    // Laptop und Handy getrennt (14.11.2026) -- jedes Gerät speichert nur seine eigene Anordnung.
    $device = ($_POST['device'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
    $layout = $reset ? null : dashboardSanitizeLayout(json_decode((string)($_POST['layout'] ?? ''), true) ?: []);
    if (Auth::isDemo()) {
        $merged = dashboardStoreDevice($_SESSION['dashboard_layouts'][$key] ?? null, $device, $layout);
        if ($merged === null) { unset($_SESSION['dashboard_layouts'][$key]); } else { $_SESSION['dashboard_layouts'][$key] = $merged; }
        echo json_encode(['status' => 'ok', 'saved' => 'session']);
        return;
    }
    try {
        $old = DB::fetchOne('SELECT layout FROM user_dashboard_layouts WHERE user_id = ? AND dashboard_key = ?', [Auth::userId(), $key]);
        $merged = dashboardStoreDevice($old ? (json_decode((string)$old['layout'], true) ?: null) : null, $device, $layout);
        if ($merged === null) {
            DB::execute('DELETE FROM user_dashboard_layouts WHERE user_id = ? AND dashboard_key = ?', [Auth::userId(), $key]);
        } else {
            DB::execute(
                'INSERT INTO user_dashboard_layouts (user_id, dashboard_key, layout) VALUES (?, ?, ?::jsonb)
                 ON CONFLICT (user_id, dashboard_key) DO UPDATE SET layout = EXCLUDED.layout, updated_at = now()',
                [Auth::userId(), $key, json_encode($merged)]
            );
        }
    } catch (\Throwable $e) {
        error_log('[dashboard-layout] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Konnte nicht gespeichert werden.']);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

$router->get('/portal/dashboard', function () {
    Auth::requireLogin();
    // Rolle "Admin" zeigt immer die Plattform-Übersicht -- auch wenn die Admin-Rolle (wie bei
    // älteren Accounts) an eine EEG gebunden ist. Vorher landete man dann im Obmann-Dashboard
    // dieser EEG (Patrick, 14.11.2026: "dann wird mir das EEG-Strom-Pool-Dashboard angezeigt").
    // Das Obmann-Dashboard gibt es über die Obmann-Rolle der jeweiligen EEG.
    if (Auth::isPlatformAdmin()) {
        header('Location: /admin');
        exit;
    }
    if (Auth::isManager()) {
        // manager_dashboard.php braucht eine konkrete aktive EEG (DB::setCommunity() dort ganz
        // oben) -- ein platform_admin ohne community-gebundene Rolle (community_id NULL, siehe
        // Auth::isPlatformAdmin()) hat aber keine, das würde sonst mit einem Fatal Error
        // abstürzen (Patrick, 06.09.2026, per Screenshot). In dem Fall auf die
        // EEG-unabhängige Plattform-Übersicht ausweichen.
        if (!Auth::activeCommunityId()) {
            header('Location: /admin');
            exit;
        }
        require ROOT . '/src/views/pages/manager_dashboard.php';
    } else {
        require ROOT . '/src/views/pages/member_dashboard.php';
    }
});

/**
 * Leichtgewichtiger JSON-Endpunkt fürs Mitglieder-Dashboard (Patrick, 30.07.2026): wird per
 * Fetch alle 5s abgefragt, damit nur die "Aktuelle Leistung"-Kachel aktualisiert wird -- kein
 * voller Seiten-Reload für Werte, von denen man weiß, dass sie sich laufend ändern.
 */
$router->get('/portal/api/current-power', function () {
    Auth::requireLogin();
    header('Content-Type: application/json');
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { echo json_encode(['net_w' => null]); return; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { echo json_encode(['net_w' => null]); return; }
    $mpIds = array_column(
        DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ? AND active = true', [$memberId]),
        'id'
    );
    echo json_encode(['net_w' => memberCurrentNetPowerW($communityId, $mpIds)]);
});

/**
 * Liefert dieselbe Zahl wie die "Energiefluss (Live)"-Grafik (communityLivePower(), siehe
 * partials/energy_flow.php), per Fetch alle 5s abgefragt statt bei jedem Update die ganze
 * Seite neu zu laden. Bewusst für JEDEN eingeloggten Portal-Nutzer offen (keine
 * Auth::requireRole()-Einschränkung mehr) -- seit 13.08.2026 auch im Kundenportal eingebunden,
 * und die zurückgegebenen Werte sind ohnehin nur ein Community-weiter Summenwert ohne
 * Rückschluss auf einzelne Mitglieder.
 */
$router->get('/portal/api/live-power', function () {
    Auth::requireLogin();
    header('Content-Type: application/json');
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { echo json_encode(['bezug_w' => 0, 'einsp_w' => 0, 'active_meters' => 0, 'total_meters' => 0]); return; }
    DB::setCommunity($communityId);
    echo json_encode(communityLivePower($communityId));
});

$router->post('/portal/switch-role', function () {
    Auth::requireLogin();
    $communityId = $_POST['community_id'] ?? '';
    $role        = $_POST['role'] ?? '';
    $memberId    = !empty($_POST['member_id']) ? (string)$_POST['member_id'] : null;
    Auth::switchRole($communityId, $role, $memberId);
    header('Location: /portal/dashboard');
    exit;
});

// ─── Portal: Rechnungen (Mitglied) ──────────────────────
$router->get('/portal/invoices', function () {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { http_response_code(404); echo 'Mitglied nicht gefunden'; return; }
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$memberId]
    );
    require ROOT . '/src/views/pages/invoices.php';
});

/**
 * Lädt eine Rechnung mit allen Feldern, die sowohl für den Autorisierungs-Check als auch für
 * den PDF-Aufbau gebraucht werden. Gibt null zurück, wenn die Rechnung nicht existiert.
 * Geteilt zwischen /portal/invoices/:id/pdf (Browser-Session) und /api/v1/invoices/:id/pdf
 * (App-Bearer-Token, seit 30.08.2026) -- beide Routen prüfen die Berechtigung SELBST (je nach
 * Identitätsquelle unterschiedlich), diese Funktion liefert nur die Rohdaten.
 */
function loadInvoiceForPdf(string $invoiceId): ?array
{
    return DB::fetchOne(
        'SELECT i.*, m.first_name, m.last_name, m.address, m.zip, m.city, m.invoice_uid,
                m.salutation, m.titel_vor, m.titel_nach, m.company_name, m.invoice_name,
                m.member_since,
                m.kundennummer, m.mandatsreferenz, m.member_iban,
                m.community_id AS member_community_id, m.user_id AS member_user_id,
                br.quartal, br.period_from, br.period_to, br.status AS run_status, br.released_at,
                c.name AS eeg_name, c.address AS eeg_address, c.iban AS eeg_iban, c.bic AS eeg_bic,
                c.zvr_number AS eeg_zvr, c.contact_phone AS eeg_contact_phone,
                c.contact_email AS eeg_contact_email, c.bank_name AS eeg_bank_name,
                c.account_holder AS eeg_account_holder, c.creditor_id AS eeg_creditor_id,
                c.sepa_prenotification_days,
                tc.bezug_ct_kwh, tc.einspeisung_ct_kwh, tc.mitgliedsbeitrag_eur,
                tx.uid_number AS eeg_uid_number, tx.tax_model AS eeg_tax_model,
                tx.tax_rate_percent AS eeg_tax_rate
         FROM invoices i
         JOIN members m ON m.id = i.member_id
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN communities c ON c.id = br.community_id
         LEFT JOIN tariff_config tc ON tc.community_id = c.id AND tc.valid_from <= br.period_from
         LEFT JOIN LATERAL (
             SELECT uid_number, tax_model, tax_rate_percent FROM tax_config
             WHERE community_id = c.id AND valid_from <= br.period_from
             ORDER BY valid_from DESC LIMIT 1
         ) tx ON true
         WHERE i.id = ?
         ORDER BY tc.valid_from DESC',
        [$invoiceId]
    );
}

/**
 * Baut die LaTeX-Vars für eine per loadInvoiceForPdf() geladene Rechnung -- ausgelagert aus
 * renderInvoicePdf(), damit sowohl das Live-Rendering (Entwurf, noch nicht freigegeben) als
 * auch das einmalige Einfrieren bei der Freigabe (siehe freezeInvoicePdf()) dieselbe Logik
 * verwenden. @return array{0: array, 1: string, 2: array} [vars, filename, assets]
 */
function invoicePdfPayload(array $invoice): array
{
    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$invoice['id']]);
    // Bezug/Einspeisung können mehrere Positionen haben (eine pro Zählpunkt) -- als Listen
    // sammeln, damit die Rechnung pro Zählpunkt eine eigene Zeile zeigen kann.
    $bezugItems = []; $einspeisungItems = []; $beitragItem = null; $extraItems = [];
    foreach ($items as $it) {
        if ($it['type'] === 'bezug')            $bezugItems[]       = $it;
        if ($it['type'] === 'einspeisung')      $einspeisungItems[] = $it;
        if ($it['type'] === 'mitgliedsbeitrag') $beitragItem        = $it;
        if ($it['type'] === 'manuell')          $extraItems[]       = $it;
    }
    // Aggregat-Summen für die Fallback-Einzeiler (falls die RAW-Liste leer ist).
    $bezugKwh    = array_sum(array_map(fn($i) => (float)$i['kwh'], $bezugItems));
    $bezugBetrag = array_sum(array_map(fn($i) => (float)$i['amount_eur'], $bezugItems));
    $einspKwh    = array_sum(array_map(fn($i) => (float)$i['kwh'], $einspeisungItems));
    $einspBetrag = array_sum(array_map(fn($i) => (float)$i['amount_eur'], $einspeisungItems));
    $bezugTarif  = $bezugItems[0]['rate_ct_kwh'] ?? null;
    $einspTarif  = $einspeisungItems[0]['rate_ct_kwh'] ?? null;
    // Zählpunkt-Fallback (nur ein Zählpunkt): direkt als zweite Zeile im Einzeiler anzeigen.
    $bezugZp     = count($bezugItems) === 1 ? trim((string)($bezugItems[0]['zaehlpunkt_nr'] ?? '')) : '';
    $einspZp     = count($einspeisungItems) === 1 ? trim((string)($einspeisungItems[0]['zaehlpunkt_nr'] ?? '')) : '';

    // Steuer: Tarife sind netto. Kleinunternehmer -> keine USt (netto = brutto). Standard ->
    // USt-Satz auf den Netto-Saldo aufschlagen; brutto ist der real zu zahlende/einzuziehende
    // Betrag. taxBreakdown() zentralisiert die Rechnung (getestet, auch von SEPA/Vorabinfo genutzt).
    $tax = taxBreakdown((float)$invoice['saldo_eur'], $invoice['eeg_tax_model'] ?? null, $invoice['eeg_tax_rate'] ?? null);
    // RAW_: LaTeX-Befehle direkt übergeben — service.js darf diese NICHT escapen.
    // 4-Spalten-Tabelle (Position / Menge / Tarif / Betrag), Steuerzeile spannt alle 4 Spalten:
    if ($tax['model'] === 'standard') {
        $ustFmt = number_format($tax['ust'], 2, ',', '.');
        $nettoFmt = number_format($tax['netto'], 2, ',', '.');
        $satzFmt = rtrim(rtrim(number_format($tax['rate'], 2, ',', '.'), '0'), ',');
        $steuerZeile = '\\multicolumn{3}{r}{\\footnotesize Netto} & \\footnotesize EUR ' . $nettoFmt . ' \\\\'
            . '\\multicolumn{3}{r}{\\footnotesize zzgl.\\,' . $satzFmt . '\\,\\% USt} & \\footnotesize EUR ' . $ustFmt . ' \\\\';
        $steuerText  = 'Im Rechnungsbetrag sind ' . $satzFmt . '\\,\\% Umsatzsteuer (EUR ' . $ustFmt . ') enthalten.';
    } else {
        // Kein eigener Tabellenzeile-Hinweis mehr -- stand sonst doppelt auf der Rechnung, einmal
        // hier UND im grauen Kasten weiter unten (steuerText, identischer Inhalt). Patrick,
        // 18.10.2026: nur noch der graue Kasten bleibt.
        $steuerZeile = '';
        $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';
    }
    // "Summe netto" ist bei Kleinunternehmern irreführend (keine USt, also kein Netto/Brutto-
    // Unterschied) -- dort nur noch "Summe"; beim Standardmodell bleibt "netto" sinnvoll, weil
    // direkt darunter die USt aufgeschlagen wird (Patrick, 18.10.2026).
    $summeTabelleLabel = $tax['model'] === 'standard' ? 'Summe netto' : 'Summe';

    // Anzeigename: abweichender Rechnungsname > Firma > formatMemberName() (Titel vor-/nachgestellt)
    $anzeigeName = ($invoice['invoice_name'] ?? '')
        ?: (($invoice['company_name'] ?? '') ?: formatMemberName($invoice));

    // Mitgliedsbeitrag-Label mit "(anteilig, X Monate)", wenn der Zeitraum nicht voll abgedeckt
    // ist (invoice_items.months < Kalendermonate des Abrechnungszeitraums) -- kein Zusatz bei
    // vollem Zeitraum (Patrick, 18.10.2026). Dieselbe Logik greift bei einer händisch
    // eingetragenen Zusatzposition, deren Label erkennbar den Mitgliedsbeitrag betrifft (z.B.
    // eine Mitgliedsbeitragsbefreiung) -- eine echte "ist das ein Beitrags-Erlass"-Markierung
    // gibt es bei freien Zusatzpositionen nicht, daher dieser Label-Abgleich als pragmatische
    // Lösung statt eines neuen, ungenutzten Felds.
    $totalMonths = countCalendarMonths($invoice['period_from'], $invoice['period_to']);
    $beitragMonths = $beitragItem ? (int)($beitragItem['months'] ?? $totalMonths) : $totalMonths;
    $beitragSuffix = mitgliedsbeitragLabelSuffix($beitragMonths, $totalMonths);
    $beitragLabel = 'Mitgliedsbeitrag' . $beitragSuffix;
    if ($beitragSuffix !== '') {
        foreach ($extraItems as &$extraItemRef) {
            if (stripos((string)$extraItemRef['label'], 'mitgliedsbeitrag') !== false
                && stripos((string)$extraItemRef['label'], 'anteilig') === false) {
                $extraItemRef['label'] .= $beitragSuffix;
            }
        }
        unset($extraItemRef);
    }

    // EEG-Adresse für den Footer aufteilen ("Straße 1, 9560 Ort" -> zwei Zeilen)
    $eegAdrTeile = array_map('trim', explode(',', $invoice['eeg_address'] ?? '', 2));

    // Zahlungstext (RAW = wird nicht escaped, texEscape() für dynamische Teile!)
    // Der real zu zahlende/einzuziehende Betrag ist der Brutto-Betrag (bei Kleinunternehmer
    // identisch mit netto, bei Standard inkl. USt).
    $saldoVal  = (float)$tax['brutto'];
    $betragFmt = number_format(abs($saldoVal), 2, ',', '.');
    // Rechnungsdatum und Fälligkeit hängen an der FREIGABE, nicht am Moment der Entwurfs-
    // berechnung (Billing::generateDrafts()) -- sonst stehen auf dem PDF Termine, die beim
    // tatsächlichen Versand (oft Wochen später, z.B. während der L3-Wartezeit) schon vorbei
    // sind, und die SEPA-Vorabinfo (sendSepaPrenotifications(), rechnet ebenfalls mit
    // released_at + sepa_prenotification_days) würde ein anderes Datum nennen als die
    // Rechnung selbst. Vor der Freigabe (kein released_at) ist es noch ein Entwurf -- zeigt
    // "Entwurf" statt eines Datums, das sich bis zur echten Freigabe noch ändert.
    $releasedAt = $invoice['released_at'] ?? null;
    $rechnungsdatum = $releasedAt ? date('d.m.Y', strtotime($releasedAt)) : 'Entwurf';
    $prenotifDays = (int)($invoice['sepa_prenotification_days'] ?? 14);
    // Fälligkeit kann auf ein Wochenende/einen Bankfeiertag fallen (z.B. Q3-2026: 01.11. =
    // Allerheiligen UND Sonntag) -- in diesem Fall auf den nächsten Bankarbeitstag verschieben
    // (isBankingDay()/nextBankingDay(), siehe SEPA-Ausführungsdatum-Auswahl). Betrifft nur noch
    // nicht eingefrorene Rechnungen: ein bereits eingefrorenes PDF (pdf_frozen_path) läuft nie
    // durch diese Funktion, siehe invoicePdfBytes().
    $faelligRoh = $releasedAt ? date('Y-m-d', strtotime($releasedAt . ' +' . $prenotifDays . ' days')) : null;
    $faellig   = $faelligRoh
        ? date('d.m.Y', strtotime(isBankingDay($faelligRoh) ? $faelligRoh : nextBankingDay($faelligRoh)))
        : 'Entwurf';
    $ibanEnd   = !empty($invoice['member_iban'])
        ? substr(preg_replace('/\s+/', '', $invoice['member_iban']), -4) : null;

    $summeLabel = '';
    if ($saldoVal < 0) {
        // "Gutschrift" statt "Guthaben" (Patrick, 26.09.2026, nach der ersten echten Abrechnung:
        // "weil Guthaben Sie ja keines haben, weil wir es ja überweisen, und dann ist es kein
        // Guthaben mehr, sondern eine Gutschrift") -- ein Guthaben bliebe bei uns liegen, das
        // Mitglied bekommt den Betrag aber tatsächlich überwiesen.
        $zahlungText = 'Ihre Gutschrift von \\textbf{EUR ' . $betragFmt . '} wird auf Ihr bei uns hinterlegtes Konto'
            . ($ibanEnd ? ' (IBAN mit der Endung \\textbf{' . $ibanEnd . '})' : '')
            . ' überwiesen. Sie müssen nichts weiter veranlassen.';
        $summeLabel = 'Ihre Gutschrift';
    } elseif (!empty($invoice['mandatsreferenz'])) {
        $zahlungText = 'Der Rechnungsbetrag von \\textbf{EUR ' . $betragFmt . '} wird gemäß SEPA-Lastschriftmandat'
            . ' (Mandatsreferenz \\textbf{' . texEscape($invoice['mandatsreferenz']) . '}'
            . (!empty($invoice['eeg_creditor_id']) ? ', Gläubiger-ID \\textbf{' . texEscape($invoice['eeg_creditor_id']) . '}' : '')
            . ') am \\textbf{' . $faellig . '}'
            . ($ibanEnd ? ' von Ihrem Konto mit der Endung \\textbf{' . $ibanEnd . '}' : ' von Ihrem Konto')
            . ' eingezogen. Sie müssen nichts weiter veranlassen.'
            . ' Diese Rechnung gilt zugleich als Vorabankündigung (Pre-Notification)'
            . ' im Sinne des SEPA-Lastschriftverfahrens.';
    } else {
        $zahlungText = ''; // Vorlage zeigt dann automatisch die Überweisungsbitte
    }

    $vars = [
        'EEG_NAME'              => $invoice['eeg_name'],
        'EEG_ADRESSE'           => $invoice['eeg_address'] ?? '',
        'EEG_STRASSE'           => $eegAdrTeile[0] ?? '',
        'EEG_PLZ_ORT'           => $eegAdrTeile[1] ?? '',
        'EEG_UID'               => $invoice['eeg_uid_number'] ?? '',
        'EEG_ZVR'               => $invoice['eeg_zvr'] ?? '',
        'EEG_OBMANN_TELEFON'    => $invoice['eeg_contact_phone'] ?? '',
        'EEG_KONTAKT_EMAIL'     => $invoice['eeg_contact_email'] ?? '',
        'EEG_BANKNAME'          => $invoice['eeg_bank_name'] ?? '',
        'EEG_KONTOINHABER'      => $invoice['eeg_account_holder'] ?? '',
        'MITGLIED_ANREDE'       => $invoice['salutation'] ?? '',
        'MITGLIED_NAME'         => $anzeigeName,
        'MITGLIED_ADRESSE'      => $invoice['address'] . ', ' . $invoice['zip'] . ' ' . $invoice['city'],
        'MITGLIED_STRASSE'      => $invoice['address'],
        'MITGLIED_PLZ_ORT'      => trim($invoice['zip'] . ' ' . $invoice['city']),
        'MITGLIED_UID'          => $invoice['invoice_uid'] ?? '',
        'KUNDENNUMMER'          => $invoice['kundennummer'] !== null ? (string)$invoice['kundennummer'] : '',
        'MITGLIED_SEPA_MANDATSREFERENZ' => $invoice['mandatsreferenz'] ?? '',
        'RECHNUNGSNUMMER'       => $invoice['rechnungsnummer'],
        'RECHNUNGSDATUM'        => $rechnungsdatum,
        'ABRECHNUNGSZEITRAUM'   => date('d.m.Y', strtotime($invoice['period_from'])) . ' -- ' . date('d.m.Y', strtotime($invoice['period_to'])),
        'BEZUG_KWH'             => number_format($bezugKwh, 2, ',', '.'),
        'BEZUG_TARIF'           => $bezugTarif !== null ? number_format((float)$bezugTarif, 4, ',', '.') : '0,0000',
        'BEZUG_BETRAG'          => number_format($bezugBetrag, 2, ',', '.'),
        'BEZUG_ZAEHLPUNKT'      => $bezugZp,
        'EINSPEISUNG_KWH'       => number_format($einspKwh, 2, ',', '.'),
        'EINSPEISUNG_TARIF'     => $einspTarif !== null ? number_format((float)$einspTarif, 4, ',', '.') : '0,0000',
        'EINSPEISUNG_BETRAG'    => number_format(abs($einspBetrag), 2, ',', '.'),
        'EINSPEISUNG_ZAEHLPUNKT' => $einspZp,
        'RAW_BEZUG_POSITIONEN_LISTE'       => rechnungPositionenLatex($bezugItems, 'Energiebezug aus der Gemeinschaft', false),
        'RAW_EINSPEISUNG_POSITIONEN_LISTE' => rechnungPositionenLatex($einspeisungItems, 'Einspeisevergütung (Gutschrift)', true),
        'MITGLIEDSBEITRAG_LABEL' => $beitragLabel,
        'MITGLIEDSBEITRAG'      => $beitragItem ? number_format((float)$beitragItem['amount_eur'], 2, ',', '.') : '0,00',
        'SUMME_TABELLE_LABEL'   => $summeTabelleLabel,
        // RAW_SUMME_NETTO/RAW_SUMME_BRUTTO sind die eigentlich aktuellen Platzhalter (enthalten
        // echtes LaTeX-Minuszeichen). SUMME_NETTO/SUMME_BRUTTO (plain, ohne RAW_) zusätzlich als
        // ABSICHERUNG für eine veraltete, eigene rechnung.tex im persistenten Volume (siehe
        // /admin/templates) -- eine solche alte Vorlage kennt die neuen RAW_-Platzhalter nicht
        // und würde sonst OHNE jeden Betrag ausgeliefert (leere Summe/Gutschrift auf einer
        // echten Rechnung, Patrick 18.10.2026), statt wie vor diesem Fix nur mit dem alten,
        // einfachen Bindestrich-Minus. Sobald die Vorlage aktualisiert ist, werden diese beiden
        // Werte von der Vorlage schlicht nicht mehr referenziert.
        'SUMME_NETTO'           => number_format($tax['netto'], 2, ',', '.'),
        'RAW_SUMME_NETTO'       => rechnungBetragUnicode($tax['netto']),
        // Im grünen Summenbalken IMMER positiv anzeigen, auch bei einer Gutschrift (negativer
        // Saldo) -- Patrick, 26.09.2026: "steht dann auch 'Ihr Guthaben von €3,90'" ... "nicht
        // als Minusbetrag, sondern dann als positiven Betrag". "Summe netto" in der Tabelle
        // darüber zeigt bewusst weiterhin das Minus (RAW_SUMME_NETTO, unverändert) -- nur der
        // Balken mit \summelabel ("Ihre Gutschrift") soll den Betrag positiv ausweisen.
        'SUMME_BRUTTO'          => number_format(abs($tax['brutto']), 2, ',', '.'),
        'RAW_SUMME_BRUTTO'      => rechnungBetragUnicode(abs($tax['brutto'])),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'RAW_ZUSATZPOSITIONEN_LISTE' => rechnungExtraItemsLatex($extraItems),
        'RAW_ZAHLUNG_TEXT'      => $zahlungText,
        'RAW_SUMME_LABEL'       => $summeLabel,
        // § 69 Abs 3 ElWG: nur bei aktiven Lieferantenpflichten, sonst leer (Vorlage zeigt dann nichts).
        'RAW_LIEFERANTENBLOCK'  => communitySupplierDuties($invoice['member_community_id'] ?? null)
            ? invoiceSupplierBlockLatex($invoice, $bezugItems, $einspeisungItems) : '',
        'IBAN'                  => $invoice['eeg_iban'] ?? '--',
        'BIC'                   => $invoice['eeg_bic'] ?? '--',
        'ZAHLUNGSZIEL'          => $faellig,
    ];
    return [$vars, $invoice['rechnungsnummer'] . '.pdf', communityLogoAsset($invoice['member_community_id'])];
}

/**
 * Rendert eine per loadInvoiceForPdf() geladene Rechnung als PDF-Response. Eine bereits
 * EINGEFRORENE Rechnung (freezeInvoicePdf() wurde bei der Freigabe aufgerufen, pdf_frozen_path
 * gesetzt) liefert IMMER die damals abgelegte Datei aus, nie eine frisch gerenderte -- sonst
 * würde eine spätere Änderung an Stammdaten/Logo/Vorlage rückwirkend eine bereits versendete,
 * ggf. längst bezahlte Rechnung verändern. Nur ein noch nicht freigegebener Entwurf wird
 * weiterhin bei jedem Abruf live aus den aktuellen invoice_items gerendert.
 */
function renderInvoicePdf(array $invoice): void
{
    $error = null;
    $body = invoicePdfBytes($invoice, $error);
    if ($body === null) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars($error) . '</pre>';
        return;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($invoice['rechnungsnummer'] . '.pdf') . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
}

/**
 * Liefert die PDF-Bytes einer Rechnung (eingefroren oder live gerendert), ohne jede Ausgabe --
 * ausgelagert aus renderInvoicePdf(), damit auch der ZIP-Sammeldownload
 * (/portal/billing/invoices.zip) dieselbe Logik je Rechnung einzeln aufrufen kann, statt direkt
 * an den Browser zu streamen.
 */
function invoicePdfBytes(array $invoice, ?string &$errorOut = null): ?string
{
    if (!empty($invoice['pdf_frozen_path']) && is_file($invoice['pdf_frozen_path'])) {
        return file_get_contents($invoice['pdf_frozen_path']);
    }
    [$vars, , $assets] = invoicePdfPayload($invoice);
    return generateLatexPdf('rechnung', $vars, $assets, $errorOut);
}

/**
 * Rendert eine Rechnung EINMALIG und legt das PDF unveränderlich ab (storage/pdfs/invoices/,
 * Teil des persistenten webapp-storage-Volumes) -- aufgerufen von Billing::finalize() für
 * jede Rechnung des gerade freigegebenen Laufs. SHA-256-Hash in pdf_sha256 dient als Nachweis,
 * dass die ausgelieferte Datei seither unverändert ist. Scheitert das Rendern (latex-service
 * nicht erreichbar o.ä.), wird NICHT die Freigabe selbst blockiert -- stattdessen bleibt
 * pdf_frozen_path leer und renderInvoicePdf() liefert bis zu einem erneuten, erfolgreichen
 * Einfrieren weiterhin live gerendert aus (Fehler wird geloggt, damit er nicht unbemerkt bleibt).
 */
function freezeInvoicePdf(string $invoiceId): void
{
    $invoice = loadInvoiceForPdf($invoiceId);
    if (!$invoice) return;
    [$vars, , $assets] = invoicePdfPayload($invoice);
    $error = null;
    $body = generateLatexPdf('rechnung', $vars, $assets, $error);
    if ($body === null) {
        error_log('[freezeInvoicePdf] Rechnung ' . $invoice['rechnungsnummer'] . ' konnte nicht eingefroren werden: ' . $error);
        return;
    }
    $dir = '/var/www/html/storage/pdfs/invoices';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $path = $dir . '/' . $invoice['rechnungsnummer'] . '.pdf';
    file_put_contents($path, $body);
    DB::execute('UPDATE invoices SET pdf_frozen_path = ?, pdf_sha256 = ? WHERE id = ?',
        [$path, hash('sha256', $body), $invoiceId]);
}

$router->get('/portal/invoices/:id/pdf', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    if ($communityId) DB::setCommunity($communityId);

    $invoice = loadInvoiceForPdf($params['id']);
    if (!$invoice) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }

    // IDOR-Schutz: nur das Mitglied selbst (Rechnung gehört zu seinem User-Login) oder ein
    // Manager/Platform-Admin der jeweiligen Community darf die PDF abrufen -- ohne diese
    // Prüfung konnte jeder eingeloggte Nutzer mit bekannter/erratener Invoice-UUID fremde
    // Rechnungen abrufen.
    $isOwnInvoice = $invoice['member_user_id'] !== null && $invoice['member_user_id'] === Auth::userId();
    $isManagerOfCommunity = Auth::isManager() && Auth::activeCommunityId() === $invoice['member_community_id'];
    if (!Auth::isPlatformAdmin() && !$isOwnInvoice && !$isManagerOfCommunity) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    renderInvoicePdf($invoice);
});


// ─── Portal: Mitglied-Selbstbedienung (eigene Verträge/Dateien) ─────────
// Analog zu den Manager-Routen unter /portal/members/:id/contract/*, aber ohne Manager-Rolle
// und ohne :id -- löst den Mitgliedsdatensatz direkt aus der eingeloggten Session auf
// (currentMemberFull()). Anders als die Manager-Ansicht wird der Status/Zeitstempel beim
// bloßen Ansehen NICHT verändert -- das bleibt allein den Manager-Aktionen vorbehalten.
$router->get('/portal/my/contract/bezug', function () {
    Auth::requireLogin();
    denyDemoFileDownload();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    if (!contractsEnabled($member['community_id'])) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], 'consumer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Bezugs-Zählpunkt registriert.'; return; }

    $tariff = contractTariff($member['community_id'], $member['contract_bezug_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_bezug_customer_signature'] ?? null, $member['community_id']);
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
    streamLatexPdf(contractTemplateName('bezug', $member), $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
});

$router->get('/portal/my/contract/einspeisung', function () {
    Auth::requireLogin();
    denyDemoFileDownload();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    if (!contractsEnabled($member['community_id'])) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], 'producer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Einspeise-Zählpunkt registriert.'; return; }

    $tariff = contractTariff($member['community_id'], $member['contract_einspeisung_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_einspeisung_customer_signature'] ?? null, $member['community_id']);
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
    streamLatexPdf(contractTemplateName('einspeisung', $member), $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
});

/** Menschenlesbare Bezeichnung des Vertragstyps für Benachrichtigungen/Meldungen. */
function contractTypeLabel(string $type): string
{
    return $type === 'einspeisung' ? 'Einspeisevereinbarung' : 'Bezugsvereinbarung';
}

/**
 * Digitale Unterschrift durch das Mitglied: Statt (wie bisher) den Vertrag nur per Post
 * unterschrieben zurückzuschicken, unterschreibt das Mitglied hier im Portal per Maus/Finger.
 * Nur möglich solange der Vertrag "created" (= versendet, aber noch nicht unterschrieben) ist.
 */
$router->get('/portal/my/contract/:type/sign', function ($params) {
    Auth::requireLogin();
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status === 'signed') {
        header('Location: /portal/my/documents?info=' . urlencode(contractTypeLabel($type) . ' wurde bereits unterschrieben.'));
        exit;
    }
    if ($status !== 'created') {
        header('Location: /portal/my/documents?error=' . urlencode(contractTypeLabel($type) . ' wurde noch nicht zur Unterschrift versendet.'));
        exit;
    }

    require ROOT . '/src/views/pages/contract_sign.php';
});

$router->post('/portal/my/contract/:type/sign', function ($params) {
    Auth::requireLogin();
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status !== 'created') {
        header('Location: /portal/my/documents?error=' . urlencode('Vertrag kann in diesem Status nicht unterschrieben werden.'));
        exit;
    }
    if (empty($_POST['zustimmung'])) {
        header('Location: /portal/my/contract/' . $type . '/sign?error=' . urlencode('Bitte bestätigen Sie die Zustimmung, bevor Sie unterschreiben.'));
        exit;
    }
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/my/contract/' . $type . '/sign?error=' . urlencode('Bitte unterschreiben Sie im Unterschriftsfeld, bevor Sie absenden.'));
        exit;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);
    DB::execute(
        "UPDATE members SET
            contract_{$type}_status = 'signed',
            contract_{$type}_template = '" . CONTRACT_TEMPLATE_VERSION . "',
            contract_{$type}_customer_signature = ?,
            contract_{$type}_signed_at = now(),
            contract_{$type}_signer_ip = ?
         WHERE id = ?",
        [$signature, clientIp(), $member['id']]
    );
    // Interne Benachrichtigung an die Manager der EEG (analog zur Beitrittserklärung-
    // Benachrichtigung oben) -- damit die Rückmeldung "Vertrag wurde unterschrieben" ankommt,
    // ohne dass der Manager aktiv nachfragen muss.
    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            'vertrag_unterschrieben',
            'Vertrag digital unterschrieben: ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . contractTypeLabel($type) . ')',
            'Die/der Netzbenutzer:in hat die ' . contractTypeLabel($type) . ' im Portal digital unterschrieben. '
            . 'Der Vertrag ist ab sofort gültig und wird automatisch sicher archiviert.',
            'member',
            $member['id'],
        ]
    );
    header('Location: /portal/my/documents?success=' . urlencode(contractTypeLabel($type) . ' wurde erfolgreich unterschrieben und ist jetzt gültig.'));
    exit;
});

/**
 * Übersichtsseite: eigene Verträge (Links zu den beiden Routen oben, je nach vorhandenen
 * Zählpunkten), eigene hochgeladene Dateien (deckt auch vom Manager hochgeladene
 * Beitrittserklärungen/Ausweis-Scans ab) und -- falls online beigetreten -- das
 * Beitrittsformular.
 */
/**
 * Viertelstündliche Werte für EINEN Tag, summiert über eine Menge von Zählpunkten eines
 * Mitglieds -- gemeinsame Grundlage für zwei spiegelbildliche Diagramme:
 *  - $energyDirection='CONSUMPTION' (Default): Verbrauch vs. gemeinschaftliche Eigendeckung,
 *    für Bezugs-/Prosumer-Zählpunkte -- /portal/my/verbrauch, GET /api/v1/consumption/interval.
 *    kwh_gemeinschaft ist hier der Anteil von kwh_messung, der aus der EEG gedeckt wurde (siehe
 *    Spaltenkommentar in database/migrate_20260904.sql) -- die Differenz kam aus dem
 *    öffentlichen Netz. Patrick, 03.09.2026: "wie viel sie viertelstündlich verbrauchen und wie
 *    viel davon energiegemeinschaftlich genutzt wird [...] damit wissen die Mitglieder auch, wie
 *    sie ihren Verbrauch optimieren können".
 *  - $energyDirection='GENERATION': eigene Einspeisung, für Einspeise-/Prosumer-Zählpunkte --
 *    /portal/my/einspeisung, GET /api/v1/production/interval. Patrick, 06.09.2026: "warum haben
 *    die Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in einem Diagramm
 *    einzusehen?". WICHTIG: bei GENERATION ist kwh_messung die GESAMTE gemeinschaftliche
 *    Erzeugung (community-weit, NICHT mitgliedsspezifisch) -- die fürs eigene Diagramm relevante
 *    Größe ist deshalb kwh_gemeinschaft ("Erzeugung lt. Messung entsprechend dem
 *    Teilnahmefaktor und EC-ID", siehe migrate_20260904.sql) = die eigene, individuell
 *    zugerechnete Einspeisung. Aufrufer für GENERATION verwenden deshalb bewusst nur
 *    'gemeinschaft_w' aus der Rückgabe, nicht 'verbrauch_w'.
 *
 * Rückgabe in DURCHSCHNITTS-WATT je Viertelstunde (kWh * 4000), nicht kWh, damit die Werte
 * direkt mit der Live-Leistungsanzeige (current-power, ebenfalls in W) vergleichbar sind.
 */
function memberIntervalDayData(string $communityId, array $meteringPointIds, string $date, string $energyDirection = 'CONSUMPTION'): array
{
    if (!$meteringPointIds) {
        return [
            'intervals' => [], 'total_messung_kwh' => 0.0, 'total_gemeinschaft_kwh' => 0.0,
            'total_erzeugung_gesamt_kwh' => 0.0, 'has_data' => false, 'has_erzeugung_gesamt' => false,
        ];
    }
    DB::setCommunity($communityId);
    $placeholders = implode(',', array_fill(0, count($meteringPointIds), '?'));
    $rows = DB::fetchAll(
        "SELECT time, SUM(kwh_messung) AS kwh_messung, SUM(kwh_gemeinschaft) AS kwh_gemeinschaft,
                SUM(kwh_erzeugung_gesamt) AS kwh_erzeugung_gesamt,
                COUNT(kwh_erzeugung_gesamt) AS n_erzeugung_gesamt
         FROM eda_interval_data
         WHERE community_id = ? AND metering_point_id IN ($placeholders)
           AND energy_direction = ? AND time >= ?::date AND time < ?::date + INTERVAL '1 day'
         GROUP BY time ORDER BY time",
        array_merge([$communityId], $meteringPointIds, [$energyDirection, $date, $date])
    );
    $byTime = [];
    $hasErzeugungGesamt = false;
    foreach ($rows as $r) {
        if ((int)$r['n_erzeugung_gesamt'] > 0) { $hasErzeugungGesamt = true; }
        $byTime[date('H:i', strtotime($r['time']))] = [
            'messung_w'          => round((float)$r['kwh_messung'] * 4000),
            'gemeinschaft_w'     => round((float)$r['kwh_gemeinschaft'] * 4000),
            'erzeugung_gesamt_w' => (int)$r['n_erzeugung_gesamt'] > 0 ? round((float)$r['kwh_erzeugung_gesamt'] * 4000) : null,
        ];
    }
    $intervals = [];
    $totalMessung = 0.0;
    $totalGemeinschaft = 0.0;
    $totalErzeugungGesamt = 0.0;
    for ($m = 0; $m < 24 * 60; $m += 15) {
        $label = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $v = $byTime[$label] ?? null;
        $intervals[] = [
            'zeit' => $label,
            'verbrauch_w' => $v['messung_w'] ?? null,
            'gemeinschaft_w' => $v['gemeinschaft_w'] ?? null,
            'erzeugung_gesamt_w' => $v['erzeugung_gesamt_w'] ?? null,
        ];
    }
    foreach ($rows as $r) {
        $totalMessung += (float)$r['kwh_messung'];
        $totalGemeinschaft += (float)$r['kwh_gemeinschaft'];
        $totalErzeugungGesamt += (float)$r['kwh_erzeugung_gesamt'];
    }
    return [
        'intervals' => $intervals,
        'total_messung_kwh' => round($totalMessung, 3),
        'total_gemeinschaft_kwh' => round($totalGemeinschaft, 3),
        'total_erzeugung_gesamt_kwh' => round($totalErzeugungGesamt, 3),
        'has_data' => count($rows) > 0,
        // Eigene Gesamterzeugung erst seit migrate_20260907.sql importiert -- ältere, noch nicht
        // erneut hochgeladene Tage haben nur kwh_gemeinschaft, kein kwh_erzeugung_gesamt. Eigenes
        // Flag statt einfach "total_erzeugung_gesamt_kwh > 0", weil 0 kWh (z.B. nachts) ein
        // gültiger Messwert ist, keine fehlende Spalte.
        'has_erzeugung_gesamt' => $hasErzeugungGesamt,
    ];
}

/**
 * Für welche Tage eines Monats liegen für ein Mitglied bereits Viertelstundenwerte vor --
 * Grundlage für den Tages-Picker auf /portal/my/verbrauch und /portal/my/einspeisung (Patrick,
 * 06.09.2026 [Folgetermin]: "wenn man sagt, man möchte zu einem gewissen Datum gehen [...] über
 * eine Eingabe oder über Pfeiltasten zu den Monaten springen [...] mit Zahlen [...] wenn Daten
 * vorhanden sind [...] grün/gelb, wenn noch keine Daten vorhanden sind [...] grau" -- statt sich
 * Tag für Tag über "Vortag"/"Folgetag" vorzuarbeiten, zeigt der Picker auf einen Blick, welche
 * Tage im gewählten Monat überhaupt Daten haben). Ein Tag zählt als "vorhanden", sobald
 * IRGENDEIN Viertelstundenwert an diesem Tag existiert -- keine Row-Level-Vollständigkeitsprüfung
 * (ein teilweise befüllter Tag ist immer noch nützlicher anzuzeigen als "grau/keine Daten").
 */
function memberIntervalMonthAvailability(string $communityId, array $meteringPointIds, string $yearMonth, string $energyDirection = 'CONSUMPTION'): array
{
    if (!$meteringPointIds) { return []; }
    DB::setCommunity($communityId);
    $placeholders = implode(',', array_fill(0, count($meteringPointIds), '?'));
    $rows = DB::fetchAll(
        "SELECT DISTINCT time::date AS tag
         FROM eda_interval_data
         WHERE community_id = ? AND metering_point_id IN ($placeholders)
           AND energy_direction = ? AND time >= ?::date AND time < ?::date + INTERVAL '1 month'",
        array_merge([$communityId], $meteringPointIds, [$energyDirection, $yearMonth . '-01', $yearMonth . '-01'])
    );
    $days = [];
    foreach ($rows as $r) { $days[(int)date('j', strtotime($r['tag']))] = true; }
    return $days;
}

$router->get('/portal/my/verbrauch', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('consumer', 'prosumer')", [$member['id']]),
        'id'
    );

    // Ohne Datumsangabe: den letzten Tag mit tatsächlich vorhandenen Daten vorschlagen (heute hat
    // fast nie schon Werte, siehe Lücken-Anzeige unter /portal/eda/upload) statt stur "heute" zu
    // zeigen, wo dann ohnehin nichts zu sehen wäre.
    $date = $_GET['date'] ?? null;
    if (!$date) {
        $latest = $mpIds ? DB::fetchOne(
            "SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ? AND metering_point_id IN ("
            . implode(',', array_fill(0, count($mpIds), '?')) . ")",
            array_merge([$member['community_id']], $mpIds)
        ) : null;
        $date = !empty($latest['letzter']) ? date('Y-m-d', strtotime($latest['letzter'])) : date('Y-m-d');
    }
    // Angezeigter Monat im Tages-Picker: per ?month=YYYY-MM wählbar (Monats-Navigation), sonst
    // der Monat des aktuell angezeigten Tages.
    $month = (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) ? $_GET['month'] : substr($date, 0, 7);

    $data = memberIntervalDayData($member['community_id'], $mpIds, $date);
    $monthAvailability = memberIntervalMonthAvailability($member['community_id'], $mpIds, $month);
    require ROOT . '/src/views/pages/my_verbrauch.php';
});

/**
 * Spiegelbild von /portal/my/verbrauch, für Einspeise-/Prosumer-Zählpunkte -- Patrick,
 * 06.09.2026: "warum haben die Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in
 * einem Diagramm einzusehen?". Nutzt dieselbe memberIntervalDayData(), aber mit
 * energy_direction='GENERATION' -- siehe dortiger Kommentar zur kwh_gemeinschaft-Semantik bei
 * Erzeugung.
 */
$router->get('/portal/my/einspeisung', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('producer', 'prosumer')", [$member['id']]),
        'id'
    );

    $date = $_GET['date'] ?? null;
    if (!$date) {
        $latest = $mpIds ? DB::fetchOne(
            "SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ? AND metering_point_id IN ("
            . implode(',', array_fill(0, count($mpIds), '?')) . ") AND energy_direction = 'GENERATION'",
            array_merge([$member['community_id']], $mpIds)
        ) : null;
        $date = !empty($latest['letzter']) ? date('Y-m-d', strtotime($latest['letzter'])) : date('Y-m-d');
    }
    $month = (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) ? $_GET['month'] : substr($date, 0, 7);

    $data = memberIntervalDayData($member['community_id'], $mpIds, $date, 'GENERATION');
    $monthAvailability = memberIntervalMonthAvailability($member['community_id'], $mpIds, $month, 'GENERATION');
    require ROOT . '/src/views/pages/my_einspeisung.php';
});

$router->get('/portal/my/documents', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true', [$member['id']]);
    $hasConsumer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'consumer'));
    $hasProducer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'producer'));
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$member['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$member['id'], $member['community_id']]);
    $assemblies = Services::assemblies()->publishedForCommunity($member['community_id']);
    if (!empty($_GET['success'])) { $success = $_GET['success']; }
    if (!empty($_GET['error']))   { $error = $_GET['error']; }
    if (!empty($_GET['info']))    { $info = $_GET['info']; }
    require ROOT . '/src/views/pages/my_documents.php';
});

/**
 * Sammelt die Jahresübersicht eines Mitglieds: alle Rechnungen eines Kalenderjahres (aus dem
 * Quartals-Präfix des Abrechnungslaufs), je mit Netto/USt/Brutto (zentrale taxBreakdown()) und
 * Zahlungsstatus, plus Jahressummen und die Liste der Jahre mit Rechnungen. Gemeinsame Datenbasis
 * für die Manager- und die Mitglied-Ansicht.
 */
function memberJahresUebersicht(string $memberId, string $communityId, ?int $jahr): array
{
    $member    = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $jahre = array_map(fn($r) => (int)$r['jahr'], DB::fetchAll(
        "SELECT DISTINCT substring(br.quartal from 1 for 4) AS jahr
           FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
          WHERE i.member_id = ? AND i.community_id = ?
          ORDER BY jahr DESC",
        [$memberId, $communityId]
    ));
    if ($jahr === null) { $jahr = $jahre[0] ?? (int)date('Y'); }
    $rows = DB::fetchAll(
        "SELECT i.*, br.quartal, br.period_from, br.status AS run_status,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           LEFT JOIN LATERAL (
               SELECT tax_model, tax_rate_percent FROM tax_config
               WHERE community_id = i.community_id AND valid_from <= br.period_from
               ORDER BY valid_from DESC LIMIT 1
           ) tx ON true
          WHERE i.member_id = ? AND i.community_id = ? AND substring(br.quartal from 1 for 4) = ?
          ORDER BY br.quartal",
        [$memberId, $communityId, (string)$jahr]
    );
    $sum = ['netto' => 0.0, 'ust' => 0.0, 'brutto' => 0.0, 'gebuehr' => 0.0];
    foreach ($rows as &$r) {
        $tb = taxBreakdown((float)$r['saldo_eur'], $r['eeg_tax_model'] ?? null, $r['eeg_tax_rate'] ?? null);
        $r['netto'] = $tb['netto']; $r['ust'] = $tb['ust']; $r['brutto'] = $tb['brutto'];
        $sum['netto'] += $tb['netto']; $sum['ust'] += $tb['ust']; $sum['brutto'] += $tb['brutto'];
        $sum['gebuehr'] += (float)($r['mahn_gebuehr_summe_eur'] ?? 0);
    }
    unset($r);
    // Bei Kleinunternehmern (keine USt, netto = brutto) sind eigene Netto-/USt-/Brutto-Spalten
    // irreführend (USt steht in jeder Zeile mit 0,00 €, netto und brutto sind identisch) --
    // Tabelle zeigt dann nur eine einzige "Betrag"-Spalte. Unabhängig von $rows (auch für ein
    // Jahr ohne Rechnungen) direkt aus der aktuellen Steuerkonfiguration der EEG ermittelt
    // (Patrick, 18.10.2026: "netto" ist bei Kleinunternehmern grundsätzlich irreführend).
    $currentTax = DB::fetchOne(
        'SELECT tax_model FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1',
        [$communityId]
    );
    $isKleinunternehmer = ($currentTax['tax_model'] ?? null) !== 'standard';
    return compact('member', 'community', 'jahre', 'jahr', 'rows', 'sum', 'isKleinunternehmer');
}

$router->get('/portal/members/:id/jahresuebersicht/:jahr', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $data = memberJahresUebersicht($member['id'], $member['community_id'], (int)$params['jahr']);
    $backUrl = '/portal/members/' . $member['id'];
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});
$router->get('/portal/members/:id/jahresuebersicht', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $data = memberJahresUebersicht($member['id'], $member['community_id'], null);
    $backUrl = '/portal/members/' . $member['id'];
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});

$router->get('/portal/my/jahresuebersicht/:jahr', function ($params) {
    Auth::requireLogin();
    $me = currentMemberFull();
    if (!$me) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $data = memberJahresUebersicht($me['id'], $me['community_id'], (int)$params['jahr']);
    $backUrl = '/portal/my/documents';
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});
$router->get('/portal/my/jahresuebersicht', function () {
    Auth::requireLogin();
    $me = currentMemberFull();
    if (!$me) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $data = memberJahresUebersicht($me['id'], $me['community_id'], null);
    $backUrl = '/portal/my/documents';
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});

$router->get('/portal/my/documents/:fileid/download', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ?',
        [$params['fileid'], $member['id']]
    );
    if (!$file || !is_file($file['pfad'])) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

/** Eigenes Beitrittsformular (nur bei Online-Beitritt vorhanden) selbst ansehen. */
$router->get('/portal/my/documents/formular', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$member['id'], $member['community_id']]);
    if (!$application) { http_response_code(404); echo 'Kein Online-Beitrittsformular vorhanden.'; return; }
    header('Location: /portal/applications/' . $application['id'] . '/formular');
    exit;
});

/**
 * Verwaltung persönlicher API-Keys (Grundlage für die künftige Smart-Home-API mit
 * Echtzeit-Bezugs-/Einspeiseleistung und Community-Autarkie). Die eigentlichen
 * Live-Daten-Endpoints, die diese Keys einmal prüfen werden, kommen erst, sobald das
 * Zählerdaten-Setup fürs Mitglied-Dashboard produktionsreif ist -- Mitglieder können ihre
 * Zugänge aber schon jetzt anlegen/benennen/mit Ablaufdatum versehen/widerrufen.
 */
$router->get('/portal/my/api-keys', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $apiKeys = DB::fetchAll(
        'SELECT * FROM member_api_keys WHERE member_id = ? ORDER BY created_at DESC',
        [$member['id']]
    );
    // Frisch erzeugter Token wird nur EINMAL direkt nach der Erstellung angezeigt (Flash über
    // die Session) -- danach ist nur noch der Hash in der DB, der Klartext ist unwiederbringlich weg.
    $newApiKey = $_SESSION['flash_new_api_key'] ?? null;
    unset($_SESSION['flash_new_api_key']);
    require ROOT . '/src/views/pages/my_api_keys.php';
});

$router->post('/portal/my/api-keys', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        header('Location: /portal/my/api-keys?error=' . urlencode('Bitte einen Namen für den API-Key vergeben.'));
        exit;
    }
    $validityDays = ['30' => 30, '90' => 90, '365' => 365][$_POST['validity'] ?? ''] ?? null;
    // date('c') statt 'Y-m-d H:i:s': mit Zeitzonen-Offset, sonst liest Postgres die Ortszeit als UTC.
    $expiresAt = $validityDays ? date('c', strtotime("+{$validityDays} days")) : null;

    // Eigener Zufallstoken statt bcrypt-Passwort: 32 Byte Zufall sind selbst schon die
    // Sicherheit, ein sha256-Hash reicht zur Speicherung (siehe migrate_20260730.sql).
    $token = bin2hex(random_bytes(32));
    DB::execute(
        'INSERT INTO member_api_keys (community_id, member_id, name, key_prefix, key_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$member['community_id'], $member['id'], $name, substr($token, 0, 8), hash('sha256', $token), $expiresAt]
    );
    $_SESSION['flash_new_api_key'] = $token;
    header('Location: /portal/my/api-keys?created=1');
    exit;
});

$router->post('/portal/my/api-keys/:id/revoke', function ($params) {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    DB::execute(
        'UPDATE member_api_keys SET revoked_at = now() WHERE id = ? AND member_id = ? AND revoked_at IS NULL',
        [$params['id'], $member['id']]
    );
    header('Location: /portal/my/api-keys?success=' . urlencode('API-Key wurde widerrufen.'));
    exit;
});

/**
 * Support-Ticket-System (siehe migrate_20260821.sql): Mitglieder können Probleme melden oder
 * Feature-Vorschläge machen, statt dass alles per E-Mail hin- und hergeschickt wird. Manager/
 * Platform-Admin sehen und beantworten alle Tickets ihrer Community unter /portal/support.
 */
$router->get('/portal/my/support', fn() => Services::supportController()->memberIndex()->send());
$router->post('/portal/my/support', fn() => Services::supportController()->memberCreate($_POST)->send());
$router->get('/portal/my/support/:id', fn($params) => Services::supportController()->memberShow($params['id'])->send());
$router->post('/portal/my/support/:id/reply', fn($params) => Services::supportController()->memberReply($params['id'], $_POST)->send());

/**
 * Wandelt einen von PostgreSQL/PDO gelieferten Datums-/Zeitstempel-String in striktes
 * ISO-8601 ("2026-08-18T17:03:00+00:00") um -- PDO gibt TIMESTAMPTZ-Spalten im
 * Postgres-eigenen Format zurück (Leerzeichen statt "T", Offset ohne Doppelpunkt, z.B.
 * "2026-08-18 17:03:00+00"), reine DATE-Spalten ganz ohne Uhrzeit (z.B. "2026-08-18"). Beides
 * ist kein gültiges ISO-8601 nach strengem Verständnis und lässt Swifts
 * `JSONDecoder`/`ISO8601DateFormatter` (Standardeinstellung, von der Xcode-App verwendet)
 * fehlschlagen -- genau das führte zu "Unerwartete Antwort vom Server" beim Mitglied-Detail
 * (viele Datumsfelder gleichzeitig: member_since, member_until, geburtsdatum, mehrere
 * registered_at/created_at) und potenziell an jeder anderen Stelle mit Datumsfeldern (Patrick,
 * 19.08.2026). ALLE Datums-/Zeitstempelfelder in /api/v1/*-JSON-Antworten laufen deshalb durch
 * diese Funktion, NICHT nur roh durchgereicht wie im Web-Portal (dort macht PHPs eigenes
 * date()/strtotime() auf denselben Rohstring ohnehin unabhängig vom Format weiter Sinn).
 *
 * Immer auf UTC normalisiert (Offset im Ergebnis ist IMMER "+00:00"), NICHT die
 * PHP-Standard-Zeitzone des Containers (Europe/Vienna) -- sonst hätte je nach Kalenderdatum ein
 * unterschiedlicher Offset im Ergebnis gestanden (+02:00 im Sommer/DST, +01:00 im Winter, siehe
 * Patrick 19.08.2026: "member_until":"...+01:00" vs. "member_since":"...+02:00" in derselben
 * Antwort) -- syntaktisch für Swift zwar beides gültiges ISO-8601, aber unnötig verwirrend und
 * schwerer zu vergleichen/cachen als ein durchgehend fester Offset.
 *
 * Reine DATE-Spalten (z.B. "2026-08-19", ohne Uhrzeit/Offset im Rohwert) werden explizit ALS
 * UTC-Mitternacht konstruiert statt über die PHP-Standard-Zeitzone interpretiert -- sonst hätte
 * "new DateTimeImmutable('2026-08-19')" das als Mitternacht in Europe/Vienna gelesen und beim
 * Umrechnen auf UTC (Sommerzeit: -2h) daraus fälschlich den 18.08. gemacht (Kalenderdatum
 * verschoben, z.B. bei geburtsdatum sichtbar falsch). Bei TIMESTAMPTZ-Werten ist das nicht
 * nötig -- die bringen ihren Offset schon selbst mit (Postgres liefert immer z.B. "...+00"),
 * PHP respektiert den beim Parsen unabhängig von der Standard-Zeitzone.
 */
function appDate(?string $value): ?string
{
    if ($value === null || $value === '') return null;
    try {
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? new DateTimeImmutable($value, new DateTimeZone('UTC'))
            : new DateTimeImmutable($value);
    } catch (\Throwable $e) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

// ─── Mitglieder-App: Login-Flow (seit 30.08.2026, siehe docs/APP_API.md) ─────
// Getrennt von der Smart-Home-API unten (member_api_keys, langlebig/selbst erzeugt): hier
// meldet sich ein MENSCH mit E-Mail/Passwort in der App an, bekommt ein kurzlebiges
// Zugriffstoken + ein erneuerbares Refresh-Token (siehe AppApiAuth.php). Alle Antworten JSON.

/**
 * Baut nach erfolgreichem Login (Passwort, ggf. 2FA) die endgültige Antwort: bei genau EINER
 * Rollen-Option (Mitgliedschaft ODER Obmann-Zugang einer EEG) direkt die Zugriffstoken, bei
 * mehreren (z.B. Mitglied in >1 EEG, oder gleichzeitig Mitglied UND Obmann) eine Auswahlliste +
 * Ticket für /api/v1/login/select-community, bei keiner ein Fehler.
 */
function appLoginSuccessResponse(string $userId, ?string $deviceLabel): array
{
    $options = resolveAppRoleOptions($userId);
    if (!$options) {
        http_response_code(403);
        return ['error' => 'Dieser Account hat weder eine aktive Mitgliedschaft noch eine Obmann-Berechtigung in einer EEG.'];
    }
    if (count($options) === 1) {
        return appIssueSessionResponse($options[0], $deviceLabel);
    }
    return [
        'community_selection_required' => true,
        'selection_ticket' => AppApiAuth::issueTicket('community_pending', ['uid' => $userId]),
        'memberships' => array_map(fn($m) => [
            'role'           => $m['role'],
            'community_id'   => $m['community_id'],
            'community_name' => $m['community_name'],
        ], $options),
    ];
}

/**
 * Stellt Zugriffs- + Refresh-Token für eine gewählte Rollen-Option (Mitglied ODER Obmann) aus.
 * "account" statt "member" im Antwortfeld -- bei role=manager gibt es keinen member_id/eigenen
 * Mitgliedsdatensatz, "member" wäre dort irreführend.
 */
function appIssueSessionResponse(array $membership, ?string $deviceLabel): array
{
    return [
        'access_token'  => AppApiAuth::issueAccessToken($membership['community_id'], $membership['role'], $membership['member_id'], $membership['user_id']),
        'refresh_token' => AppApiAuth::issueRefreshToken($membership['community_id'], $membership['role'], $membership['member_id'], $membership['user_id'], $deviceLabel),
        'expires_in'    => AppApiAuth::accessTokenTtl(),
        'role'          => $membership['role'],
        'account' => [
            'member_id'      => $membership['member_id'],
            'name'           => $membership['name'],
            'community_id'   => $membership['community_id'],
            'community_name' => $membership['community_name'],
        ],
    ];
}


// ─── Portal: Mitgliederverwaltung ───────────────────────
$router->get('/portal/members', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    // ESP-Fehlerstatus je Mitglied (für die neue "Zähler"-Spalte + Sidebar-Badge, Patrick
    // 30.07.2026): "Fehler" = mindestens ein aktiver Zählpunkt, der schon einmal gemeldet hat
    // (esp_last_seen_at gesetzt), aber entweder länger als die Offline-Schwelle nicht mehr
    // online war ODER online ist, dessen Zähler (P1-Signal) aber nicht erreichbar ist -- exakt
    // dieselbe Logik wie die Status-Kachelzeile im Obmann-Dashboard (espOfflineAfterMinutes()).
    $espOfflineMinutes = espOfflineAfterMinutes();
    // open_amount bewusst als eigene Subquery, NICHT als weiterer JOIN + SUM(...) FILTER: ein
    // zusätzlicher LEFT JOIN invoices NEBEN dem LEFT JOIN metering_points hätte (beides 1:n zu
    // members) ein klassisches Fan-out-Problem erzeugt -- bei z.B. 2 Zählpunkten UND 1 offener
    // Rechnung entstehen 2 kombinierte Zeilen (Kreuzprodukt), wodurch SUM(i.saldo_eur) dieselbe
    // Rechnung doppelt zählt. Genau das ließ "Offener Betrag" bei jedem Mitglied mit 2
    // Zählpunkten (eigener Bezugs- UND Einspeisungs-Zählpunkt) exakt doppelt so hoch erscheinen
    // wie bei einem Mitglied mit nur einem Zählpunkt (Patrick, 06.08.2026: "warum steht bei ein
    // paar schon 4,00€" bei nur einer einzigen Juli-Abrechnung). Eine Subquery ist vom
    // metering_points-JOIN völlig unabhängig und kann daher nicht mitmultipliziert werden.
    $members = DB::fetchAll(
        "SELECT m.*,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung,
                COALESCE(
                    (SELECT SUM(i.saldo_eur) FROM invoices i
                     WHERE i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL),
                    0
                ) AS open_amount,
                EXISTS(SELECT 1 FROM membership_applications ma WHERE ma.member_id = m.id) AS via_online,
                -- mp.meter_code IS NOT NULL zusätzlich zu mp.active: esp_last_seen_at/
                -- meter_reachable bleiben auf dem Zählpunkt stehen, auch wenn die Zählernummer
                -- später entfernt wurde (Zähler außer Betrieb, Zählpunkt selbst aber weiterhin
                -- aktiv) -- ohne diese Sperre zeigte die Zähler-Spalte für so ein Mitglied
                -- weiterhin OK/Fehler statt kein Zähler (Patrick, 09.08.2026).
                bool_or(mp.active AND mp.meter_code IS NOT NULL AND mp.esp_last_seen_at IS NOT NULL) AS hat_esp_bekannt,
                -- NICHT mehr zusätzlich auf mp.esp_online geprüft (Patrick, 19.08.2026: Status
                -- blinkte zwischen Mama/Papa trotz durchgehender Live-Daten alle 5s) -- die
                -- Recency von esp_last_seen_at allein ist das zuverlässigere Signal, seit
                -- insert_measurement() (mqtt-subscriber) diesen Zeitstempel bei JEDER Live-
                -- Messung mitzieht. esp_online selbst bleibt dagegen weiterhin nur eine
                -- Momentaufnahme des zuletzt empfangenen Status-Heartbeats und kann durch dessen
                -- MQTT-Last-Will-Testament bei einem kurzen Verbindungsaussetzer auf false
                -- hängen bleiben, bis der NÄCHSTE Heartbeat (nicht: die nächste Live-Nachricht)
                -- eintrifft -- ein zusätzliches AND mp.esp_online hätte also weiterhin genau die
                -- Fehlanzeige verursacht, die eigentlich behoben werden sollte. esp_online bleibt
                -- als Spalte/Diagnosewert erhalten (WLAN-Info-Anzeige), fließt hier aber
                -- bewusst nicht mehr ein.
                bool_or(
                    mp.active AND mp.meter_code IS NOT NULL AND mp.esp_last_seen_at IS NOT NULL AND
                    NOT (mp.esp_last_seen_at > now() - (? || ' minutes')::interval AND mp.meter_reachable)
                ) AS hat_esp_fehler,
                MAX(u.last_login_at) AS last_login_at,
                (SELECT mp2.zaehlpunkt_nr FROM metering_points mp2
                 WHERE mp2.member_id = m.id AND mp2.type IN ('consumer', 'prosumer') AND mp2.active = true
                 ORDER BY mp2.created_at LIMIT 1) AS znr_bezug,
                (SELECT mp2.zaehlpunkt_nr FROM metering_points mp2
                 WHERE mp2.member_id = m.id AND mp2.type IN ('producer', 'prosumer') AND mp2.active = true
                 ORDER BY mp2.created_at LIMIT 1) AS znr_einspeisung
         FROM members m
         LEFT JOIN users u ON u.id = m.user_id
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$espOfflineMinutes, $communityId]
    );
    // Demo-Login (siehe migrate_20260905.sql): echte Mitgliederdaten hier NIE unmaskiert zeigen
    // (Patrick, 05.09.2026: "Bei Plattform, Admin und Obmann auch keine personenbezogenen
    // Daten") -- die beiden fiktiven Demo-Mitglieder selbst bleiben unverändert.
    $members = demoMaskMembers($members, Auth::isDemo());
    $contractsEnabled = contractsEnabled($communityId);
    require ROOT . '/src/views/pages/member_list.php';
});

$router->get('/portal/files', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        "SELECT id, first_name, last_name, company_name, email, kundennummer, is_demo
         FROM members WHERE community_id = ? ORDER BY kundennummer NULLS LAST, last_name, first_name",
        [$communityId]
    );
    $members = demoMaskMembers($members, Auth::isDemo());
    require ROOT . '/src/views/pages/files_search.php';
});

$router->get('/portal/files/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];
    $member = demoMaskMember($member, Auth::isDemo());

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

    // Neueste hochgeladene Datei je Kategorie (member_files ist bereits nach created_at DESC
    // sortiert, das erste Match pro Kategorie ist also automatisch das aktuellste).
    $filesByCategory = ['beitritt' => null, 'bezug' => null, 'einspeisung' => null, 'ausweis' => null];
    foreach ($member_files as $f) {
        $cat = matchFileCategory($f['name']);
        if ($cat && !$filesByCategory[$cat]) { $filesByCategory[$cat] = $f; }
    }

    require ROOT . '/src/views/pages/files_member.php';
});

$router->get('/portal/members/new', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
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

    // Optionale Zählpunkte gleich bei der Anlage (statt erst hinterher auf der Detailseite) --
    // Bezug und Einspeisung haben eigene Zählpunktnummern (AT...), auch wenn es derselbe
    // physische Zähler/dieselbe Zählernummer ist (Prosumer). Beide Checkboxen sind optional.
    $znrBezugNew = null;
    $znrEinspNew = null;
    if (!empty($_POST['add_bezug_zp'])) {
        $znrBezugNew = strtoupper(trim($_POST['bezug_zaehlpunkt_nr'] ?? ''));
        if ($znrBezugNew === '') {
            $error = 'Bitte die Zählpunktnummer für den Bezugs-Zählpunkt angeben (oder das Häkchen entfernen).';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }
    if (!empty($_POST['add_einspeisung_zp'])) {
        $znrEinspNew = strtoupper(trim($_POST['einspeisung_zaehlpunkt_nr'] ?? ''));
        if ($znrEinspNew === '') {
            $error = 'Bitte die Zählpunktnummer für den Einspeise-Zählpunkt angeben (oder das Häkchen entfernen).';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }
    if ($znrBezugNew && $znrEinspNew && $znrBezugNew === $znrEinspNew) {
        $error = 'Bezugs- und Einspeise-Zählpunkt dürfen nicht dieselbe Zählpunktnummer haben (dieselbe Zählernummer ist bei einem Prosumer dagegen normal).';
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }
    foreach (array_filter([$znrBezugNew, $znrEinspNew]) as $znrToCheck) {
        $znrOwner = DB::fetchOne(
            "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
             JOIN members m ON m.id = mp.member_id
             WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ?",
            [$communityId, $znrToCheck]
        );
        if ($znrOwner) {
            $error = 'Die Zählpunktnummer ' . $znrToCheck . ' ist bereits vergeben — an '
                . $znrOwner['first_name'] . ' ' . $znrOwner['last_name'] . ' (KdNr ' . ($znrOwner['kundennummer'] ?? '—') . ').';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
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
    $result = createMemberRecord($communityId, array_merge($_POST, [
        'andere_eeg' => isset($_POST['andere_eeg']),
        'titel_vor'  => resolveTitelField($_POST, 'titel_vor'),
        'titel_nach' => resolveTitelField($_POST, 'titel_nach'),
    ]));
    logAudit($communityId, 'member.create', 'member', $result['member_id'],
        'Mitglied ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']) . ' angelegt (KdNr ' . $result['kundennummer'] . ')');

    if ($znrBezugNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrBezugNew, 'consumer', [
            'meter_code'          => trim($_POST['bezug_meter_code'] ?? ''),
            'jahresverbrauch_kwh' => $_POST['bezug_jahresverbrauch_kwh'] ?? '',
        ]);
    }
    if ($znrEinspNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrEinspNew, 'producer', [
            'meter_code'               => trim($_POST['einspeisung_meter_code'] ?? ''),
            'engpassleistung_kw'       => $_POST['einspeisung_engpassleistung_kw'] ?? '',
            'geplante_einspeisung_kwh' => $_POST['einspeisung_geplante_einspeisung_kwh'] ?? '',
        ]);
    }

    // Erstlogin-Einladung wurde per E-Mail verschickt -> kein Temp-Passwort am Bildschirm nötig.
    if ($result['invite_sent']) {
        header('Location: /portal/members?success=invite_sent');
        exit;
    }

    // Fallback: Mailversand nicht konfiguriert/fehlgeschlagen (oder E-Mail existierte schon,
    // dann gibt's ohnehin kein Temp-Passwort) -- Temp-Passwort anzeigen, falls ein neuer User
    // angelegt wurde, damit der Manager die Zugangsdaten notfalls selbst weitergeben kann.
    if ($result['temp_password']) {
        $successTempPw = $result['temp_password'];
        $successEmail  = $email;
        $successInviteError = $result['invite_error'];
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
        $contractsEnabled = contractsEnabled($communityId);
        require ROOT . '/src/views/pages/member_list.php';
        exit;
    }

    header('Location: /portal/members?success=1');
    exit;
});

$router->get('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoPage('Dieser Demo-Zugang dient nur zur Ansicht. Mitgliederdaten können hier nicht bearbeitet werden.');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

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
        'UPDATE members SET salutation=?, titel_vor=?, titel_nach=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, kontoinhaber=?, konto_adresse=?, mandatsreferenz=?,
         member_since=?, member_until=?, mandat_unterschrift_datum=?,
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?,
         email_anrede_mode=?
         WHERE id=?',
        [
            $_POST['salutation'] ?? null,
            resolveTitelField($_POST, 'titel_vor'),
            resolveTitelField($_POST, 'titel_nach'),
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
            ($_POST['mandat_unterschrift_datum'] ?? '') ?: null,
            ($_POST['geburtsdatum'] ?? '') ?: null,
            trim($_POST['stromlieferant'] ?? '') ?: null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)$_POST['speicher_kwh'] : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            in_array($_POST['email_anrede_mode'] ?? 'auto', ['auto', 'herr', 'frau', 'familie'], true) ? ($_POST['email_anrede_mode'] ?? 'auto') : 'auto',
            $params['id'],
        ]
    );
    if (communitySupplierDuties($member['community_id']) && array_key_exists('invoice_interval', $_POST)) {
        $ivl = in_array($_POST['invoice_interval'], ['monthly', 'yearly'], true) ? $_POST['invoice_interval'] : null;
        DB::execute('UPDATE members SET invoice_interval = ? WHERE id = ?', [$ivl, $params['id']]);
    }
    $memberAfter = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    $memberChanges = auditDiff($member, $memberAfter ?? [], [
        'invoice_interval' => 'Rechnungsintervall', 'salutation' => 'Anrede', 'titel_vor' => 'Titel (vorgestellt)', 'titel_nach' => 'Titel (nachgestellt)',
        'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'company_name' => 'Firma', 'address' => 'Adresse', 'zip' => 'PLZ', 'city' => 'Ort', 'phone' => 'Telefon',
        'invoice_uid' => 'UID', 'member_iban' => 'IBAN', 'member_bic' => 'BIC', 'kontoinhaber' => 'Kontoinhaber',
        'konto_adresse' => 'Konto-Adresse', 'mandatsreferenz' => 'Mandatsreferenz', 'member_since' => 'Mitglied seit',
        'member_until' => 'Mitglied bis', 'mandat_unterschrift_datum' => 'Mandatsunterschrift-Datum',
        'geburtsdatum' => 'Geburtsdatum', 'stromlieferant' => 'Stromlieferant',
        'speicher_status' => 'Speicher', 'speicher_kwh' => 'Speicher kWh', 'andere_eeg' => 'Andere EEG',
        'andere_eeg_name' => 'Andere-EEG-Name', 'email_anrede_mode' => 'E-Mail-Anrede-Modus',
    ]);
    $memberName = trim(($memberAfter['first_name'] ?? '') . ' ' . ($memberAfter['last_name'] ?? ''));
    if (!empty($memberChanges)) {
        logAuditDiff($member['community_id'], 'member.update', 'member', $params['id'], $memberChanges,
            'Mitglied ' . $memberName . ':');
    } else {
        logAudit($member['community_id'], 'member.update', 'member', $params['id'],
            'Mitglied ' . $memberName . ' gespeichert (keine Änderung)');
    }
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

// Kein echter Hard-Delete für einzelne Mitglieder mehr (Aufbewahrungspflicht für Verträge/
// Dateien) -- siehe /deactivate weiter unten. Ein Hard-Delete gibt es nur noch komplett auf
// EEG-Ebene (/admin/communities/:id/delete), wenn die ganze EEG aufgelöst wird.

// Nur Plattform-Admins dürfen ein Mitglied-Login löschen (danach kann der Account kein
// Passwort mehr anfragen). Da users.email plattformweit eindeutig ist, wird die users-Zeile
// nur gelöscht, wenn der Account sonst KEINE Rolle mehr hat — sonst würde man einer Person
// versehentlich den Zugriff auf eine andere EEG entziehen, in der sie z.B. ebenfalls
// Mitglied ist.
$router->post('/portal/members/:id/delete-login', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $communityId = $member['community_id'];
    $userId = $member['user_id'];

    DB::execute('UPDATE members SET user_id = NULL WHERE id = ?', [$params['id']]);
    DB::execute('DELETE FROM user_roles WHERE user_id = ? AND community_id = ?', [$userId, $communityId]);

    $remainingRoles = DB::fetchOne('SELECT COUNT(*) AS cnt FROM user_roles WHERE user_id = ?', [$userId])['cnt'];
    if ((int)$remainingRoles === 0) {
        DB::execute('DELETE FROM users WHERE id = ?', [$userId]);
    }

    logAudit($communityId, 'member.delete_login', 'member', $params['id'],
        'Login-Konto von ' . $member['first_name'] . ' ' . $member['last_name'] . ' entfernt (Mitglied bleibt bestehen)');

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/**
 * "Wirklich löschen": Soft-Deactivation statt Hard-Delete. Wegen der Aufbewahrungspflicht
 * bleiben Mitgliedsdaten, Verträge und Dateien vollständig erhalten -- nur der Login wird
 * gesperrt (users.active=false, falls ein Login existiert) und members.status auf
 * 'inactive' gesetzt, was auf der Detailseite den "Freigeben"-Button statt der
 * Löschen-Aktionen anzeigt. Das Mitglied wird per E-Mail informiert.
 */
$router->post('/portal/members/:id/deactivate', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    DB::execute("UPDATE members SET status = 'inactive' WHERE id = ?", [$params['id']]);
    if ($member['user_id']) {
        DB::execute('UPDATE users SET active = false WHERE id = ?', [$member['user_id']]);
    }
    logAudit($member['community_id'], 'member.deactivate', 'member', $params['id'],
        'Mitglied ' . $member['first_name'] . ' ' . $member['last_name'] . ' deaktiviert (Daten aus Aufbewahrungspflicht erhalten)');

    $mailError = null;
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('member_deactivated', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
        ],
            'Ihre Mitgliedschaft bei Strom für alle wurde deaktiviert',
            '<p>{{anrede}} {{nachname}},</p><p>Ihr Zugang wurde deaktiviert. Ihre Daten bleiben aus '
            . 'Aufbewahrungsgründen erhalten. Bitte wenden Sie sich zur Reaktivierung an Ihre EEG-Verwaltung.</p>'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
    } catch (\Throwable $e) {
        $mailError = $e->getMessage();
    }

    header('Location: /portal/members/' . $params['id'] . '?' . ($mailError
        ? 'error=mail&detail=' . urlencode($mailError)
        : 'success=' . urlencode('Mitglied deaktiviert — Benachrichtigung wurde per E-Mail verschickt.')));
    exit;
});

/** "Freigeben": Hebt eine über /deactivate gesetzte Deaktivierung wieder auf. */
$router->post('/portal/members/:id/reactivate', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    DB::execute("UPDATE members SET status = 'active' WHERE id = ?", [$params['id']]);
    if ($member['user_id']) {
        DB::execute('UPDATE users SET active = true WHERE id = ?', [$member['user_id']]);
    }
    logAudit($member['community_id'], 'member.reactivate', 'member', $params['id'],
        'Mitglied ' . $member['first_name'] . ' ' . $member['last_name'] . ' wieder freigegeben');

    header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Mitglied wieder freigegeben.'));
    exit;
});

$router->post('/portal/members/:id/reset-password', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Kein Login-Konto vorhanden.'; return; }
    $loginEmail = DB::fetchOne('SELECT email FROM users WHERE id = ?', [$member['user_id']])['email'];

    // 10 Minuten statt der 1-Stunden-Standardgültigkeit der Selbstbedienungs-"Passwort
    // vergessen"-Funktion, da dieser Link vom Manager direkt im Beisein/Auftrag des
    // Mitglieds ausgelöst wird und entsprechend kurzlebig sein soll.
    $token = Auth::createResetToken($loginEmail, 600);
    try {
        $link = htmlspecialchars(passwordResetLink($token));
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('password_reset', [
            'vorname'     => htmlspecialchars($member['first_name']),
            'anrede'      => htmlspecialchars($anrede['anrede']),
            'nachname'    => htmlspecialchars($anrede['nachname']),
            'link'        => $link,
            'gueltigkeit' => '10 Minuten',
        ],
            'Passwort zurücksetzen – Strom für alle',
            '<p>Liebes Mitglied,</p>'
            . '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>'
            . '<p><a href="{{link}}">{{link}}</a></p>'
            . '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
        );
        Mailer::send($loginEmail, $mail['subject'], $mail['body']);
        header('Location: /portal/members/' . $params['id'] . '?success=reset_sent');
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Willkommens-E-Mail (Erstlogin-Link, 24h gültig) erneut senden -- für Mitglieder, die sich seit
 * dem Anlegen noch nie eingeloggt haben (z.B. weil die ursprüngliche Mail im Spam landete oder der
 * erste Link inzwischen abgelaufen ist). Bewusst eine eigene Route statt reset-password oben
 * wiederzuverwenden: andere Vorlage ("Willkommen" statt "Passwort zurücksetzen"), andere
 * Standard-Gültigkeit (24h statt 10min) und ein serverseitiger Schutz gegen Mehrfachnutzung durch
 * bereits aktive Mitglieder (siehe last_login_at-Prüfung unten).
 */
$router->post('/portal/members/:id/resend-invite', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Kein Login-Konto vorhanden.'; return; }
    $user = DB::fetchOne('SELECT email, last_login_at FROM users WHERE id = ?', [$member['user_id']]);
    if (!empty($user['last_login_at'])) {
        header('Location: /portal/members?error=' . urlencode('Dieses Mitglied hat sich bereits angemeldet -- bitte stattdessen "Passwort zurücksetzen" auf der Mitglieds-Detailseite verwenden.'));
        exit;
    }
    $token = Auth::createResetToken($user['email'], 86400);
    if (!$token) {
        header('Location: /portal/members?error=' . urlencode('Kein aktiver Login-Zugang für diese E-Mail-Adresse gefunden.'));
        exit;
    }
    try {
        $link = htmlspecialchars(passwordResetLink($token));
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('invite', [
            'vorname'     => htmlspecialchars($member['first_name']),
            'anrede'      => htmlspecialchars($anrede['anrede']),
            'nachname'    => htmlspecialchars($anrede['nachname']),
            'link'        => $link,
            'gueltigkeit' => '24 Stunden',
        ],
            'Willkommen bei Strom für alle – Zugang einrichten',
            '<p>{{anrede}} {{nachname}},</p>'
            . '<p>Ihr Zugang zum Mitgliederportal wurde angelegt. Bitte vergeben Sie über folgenden Link '
            . 'innerhalb der nächsten {{gueltigkeit}} Ihr persönliches Passwort:</p>'
            . '<p><a href="{{link}}">{{link}}</a></p>'
        );
        Mailer::send($user['email'], $mail['subject'], $mail['body']);
        header('Location: /portal/members?success=invite_resent');
    } catch (\Throwable $e) {
        header('Location: /portal/members?error=' . urlencode('E-Mail-Versand fehlgeschlagen: ' . $e->getMessage()));
    }
    exit;
});

$router->get('/portal/members/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // requireMemberAccess() scoped absichtlich NICHT über die aktive Rolle: Platform-Admins
    // müssen ein Mitglied auch dann ansehen können, wenn ihre aktuell aktive Rolle gerade eine
    // ANDERE EEG ist (z.B. von der EEG-Übersicht im Admin-Bereich aus) -- IDOR-Schutz erfolgt
    // dort explizit, setzt bei Erfolg auch gleich die RLS-Community.
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];
    // "Gelöschte" Zählpunkte sind nur soft-deaktiviert (active=false), damit historische
    // Abrechnungsperioden weiter nachvollziehbar bleiben -- auf der Mitglied-Detailseite
    // sollen sie aber wie erwartet aus der Liste verschwinden.
    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$params['id']]);
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    $member['last_login_at'] = $member['user_id']
        ? (DB::fetchOne('SELECT last_login_at FROM users WHERE id = ?', [$member['user_id']])['last_login_at'] ?? null)
        : null;
    $latestFirmwareVersion = latestFirmwareVersion();
    // Demo-Login: echte Mitgliederdaten (inkl. Zählpunkte) maskieren, siehe /portal/members oben.
    $isDemo = Auth::isDemo();
    $member = demoMaskMember($member, $isDemo);
    $metering_points = demoMaskMeteringPoints($metering_points, $isDemo);
    require ROOT . '/src/views/pages/member_detail.php';
});

/**
 * "Ins Portal des Kunden" (Patrick, 14.11.2026, für den Support): Obmann/Platform-Admin sieht
 * das Portal so, wie das Mitglied es sieht, mit dessen Funktionen -- siehe
 * Auth::startImpersonation() und die Sperrliste in Router::dispatch(). Start und Ende landen im
 * Aktivitätslog der EEG.
 */
$router->post('/portal/members/:id/impersonate', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $community = DB::fetchOne(
        'SELECT id, name, slug, COALESCE(LOWER(marktpartner_id), slug) AS mqtt_id FROM communities WHERE id = ?',
        [$member['community_id']]
    );
    if (!$community) { http_response_code(404); echo 'EEG nicht gefunden'; return; }
    $name = trim($member['first_name'] . ' ' . $member['last_name']);
    logAudit($member['community_id'], 'member.impersonate.start', 'member', $member['id'],
        'Kundenansicht geöffnet: ' . $name . ($member['kundennummer'] ? ' (KdNr ' . $member['kundennummer'] . ')' : ''));
    Auth::startImpersonation($member, $community);
    header('Location: /portal/dashboard');
    exit;
});

$router->post('/portal/impersonation/stop', function () {
    Auth::requireLogin();
    $imp = Auth::stopImpersonation();
    if ($imp === null) { header('Location: /portal/dashboard'); exit; }
    $minutes = max(1, (int)round((time() - (int)$imp['started_at']) / 60));
    logAudit($imp['community_id'], 'member.impersonate.stop', 'member', $imp['member_id'],
        'Kundenansicht beendet: ' . $imp['member_name'] . " (nach {$minutes} Min.)");
    // Zurück zum Mitglied -- requireMemberAccess() dort prüft wieder mit der eigenen Rolle.
    header('Location: /portal/members/' . rawurlencode((string)$imp['member_id']));
    exit;
});

/**
 * P1-Kundenschnittstellen-Schlüssel eines Zählpunkts setzen oder löschen (Patrick, 07.11.2026).
 * Nur Obmann/Platform-Admin; gespeichert ausschließlich verschlüsselt (encryptSecret(), Schlüssel
 * aus APP_SECRET der .env). Im Audit-Log steht nur DASS geändert wurde, nie der Schlüssel selbst.
 */
$router->post('/portal/members/:id/metering-points/:mpid/p1-key', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id, zaehlpunkt_nr FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $member['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    $back = '/portal/members/' . $member['id'];

    if (($_POST['action'] ?? '') === 'delete') {
        DB::execute('UPDATE metering_points SET p1_key_enc = NULL, p1_key_updated_at = now() WHERE id = ?', [$mp['id']]);
        logAudit($member['community_id'], 'p1_key.delete', 'metering_point', $mp['id'],
            'P1-Schlüssel für Zählpunkt ' . $mp['zaehlpunkt_nr'] . ' gelöscht');
        header('Location: ' . $back . '?success=p1_key_deleted'); exit;
    }

    $key = normalizeP1Key($_POST['p1_key'] ?? '');
    if ($key === null) {
        header('Location: ' . $back . '?error=' . urlencode('Ungültiger P1-Schlüssel: es werden genau 32 Hex-Zeichen (0-9, A-F) erwartet.'));
        exit;
    }
    DB::execute('UPDATE metering_points SET p1_key_enc = ?, p1_key_updated_at = now() WHERE id = ?', [encryptSecret($key), $mp['id']]);
    logAudit($member['community_id'], 'p1_key.set', 'metering_point', $mp['id'],
        'P1-Schlüssel für Zählpunkt ' . $mp['zaehlpunkt_nr'] . ' hinterlegt/geändert');
    header('Location: ' . $back . '?success=p1_key_saved'); exit;
});

/**
 * Hinterlegten P1-Schlüssel im Klartext abrufen -- erst auf Klick (nicht im Seiten-HTML), und
 * jeder Abruf wird protokolliert. Im Demo-Login nie (echte Schlüssel echter Mitglieder).
 */
$router->get('/portal/members/:id/metering-points/:mpid/p1-key', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (Auth::isDemo()) { http_response_code(403); echo json_encode(['error' => 'Im Demo-Zugang nicht verfügbar.']); return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id, zaehlpunkt_nr, p1_key_enc FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $member['id']]);
    if (!$mp) { http_response_code(404); echo json_encode(['error' => 'Zählpunkt nicht gefunden']); return; }
    if (empty($mp['p1_key_enc'])) { echo json_encode(['key' => null]); return; }
    logAudit($member['community_id'], 'p1_key.view', 'metering_point', $mp['id'],
        'P1-Schlüssel für Zählpunkt ' . $mp['zaehlpunkt_nr'] . ' angezeigt');
    echo json_encode(['key' => decryptSecret($mp['p1_key_enc'])]);
});

/**
 * Sammelliste P1-Freischaltung (CSV) für die Anfrage beim Netzbetreiber: alle aktiven
 * Zählpunkte aktiver Mitglieder, für die noch KEIN P1-Schlüssel hinterlegt ist. Spalten siehe
 * p1SammellisteColumns() -- bewusst dort zentral, weil die Formatvorgaben von Kärnten Netz noch
 * ausstehen. "Vollmacht umfasst P1" zeigt, ob das Mitglied die Vollmacht in einer Fassung
 * erteilt hat, die die P1-Freischaltung abdeckt (erst ab Zustimmungs-Fassung v2).
 * Eigener Pfad außerhalb von /portal/members/..., damit /portal/members/:id ihn nicht schluckt.
 */
$router->get('/portal/p1-sammelliste.csv', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $rows = DB::fetchAll(
        "SELECT m.last_name, m.first_name, m.address, m.zip, m.city, m.beitrittsdatum, m.consent_version,
                mp.zaehlpunkt_nr, mp.meter_code
         FROM metering_points mp
         JOIN members m ON m.id = mp.member_id
         WHERE mp.community_id = ? AND mp.active = true AND mp.p1_key_enc IS NULL
           AND mp.mirror_source_metering_point_id IS NULL
           AND m.status = 'active' AND m.is_demo = false
         ORDER BY m.last_name, m.first_name, mp.zaehlpunkt_nr",
        [$communityId]
    );
    foreach ($rows as &$r) {
        $r['vollmacht_p1'] = consentCoversP1($r['consent_version'])
            ? 'ja'
            : 'nein (Fassung ' . ($r['consent_version'] ?: 'unbekannt') . ')';
    }
    unset($r);
    logAudit($communityId, 'p1_key.export', null, null, 'Sammelliste P1-Freischaltung exportiert (' . count($rows) . ' Zählpunkte)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="p1-freischaltung-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo p1SammellisteCsv($rows);
});

/**
 * WLAN-Diagnoseinfos eines Zählpunkts (SSID/IP/Passwort) auf Abruf statt beim Seitenaufbau
 * mitzuschicken -- das entschlüsselte Passwort landet so nicht unnötig im initialen HTML
 * (z.B. Browser-Cache, Screenshot der Seite), sondern nur wenn Obmann/Admin aktiv auf
 * "WLAN-Info anzeigen" klicken. Siehe docs/ESP_IDEEN.md Punkt 1.
 */
$router->get('/portal/members/:id/metering-points/:mpid/wifi-info', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    header('Content-Type: application/json; charset=UTF-8');
    // metering_points hat Row-Level Security (migrate_20260822.sql) -- ohne vorheriges
    // DB::setCommunity() liefert die eingeschränkte Laufzeit-Rolle grundsätzlich GAR KEINE
    // Zeile, auch bei korrekter ID (siehe requireMemberAccess()-Kommentar oben). Dieser
    // Endpunkt hat das ursprünglich übersehen ("Zählpunkt nicht gefunden" trotz existierendem
    // Zählpunkt) -- Fix analog zu requireMemberAccess()/dem Avatar-Endpunkt: Platform-Admin
    // probiert jede Community durch, alle anderen nutzen direkt ihre aktive Rolle.
    if (Auth::isPlatformAdmin()) {
        $mp = null;
        foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
            DB::setCommunity($c['id']);
            $mp = DB::fetchOne(
                'SELECT wifi_ssid, wifi_ip, wifi_password_enc, esp_firmware_version, community_id
                 FROM metering_points WHERE id = ? AND member_id = ?',
                [$params['mpid'], $params['id']]
            );
            if ($mp) break;
        }
    } else {
        DB::setCommunity(Auth::activeCommunityId());
        $mp = DB::fetchOne(
            'SELECT wifi_ssid, wifi_ip, wifi_password_enc, esp_firmware_version, community_id
             FROM metering_points WHERE id = ? AND member_id = ?',
            [$params['mpid'], $params['id']]
        );
    }
    if (!$mp) { http_response_code(404); echo json_encode(['error' => 'Zählpunkt nicht gefunden']); return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo json_encode(['error' => 'Kein Zugriff']); return;
    }
    // Demo-Login: das entschlüsselte WLAN-Passwort ist das ECHTE Heim-WLAN-Passwort des
    // Mitglieds -- niemals im Klartext zeigen, außer beim fiktiven Demo-Mitglied selbst
    // (Patrick, 06.09.2026). GET-Endpunkt, also von der POST-only Read-only-Sperre NICHT erfasst.
    if (Auth::isDemo()) {
        $isDemoMember = (bool)DB::fetchOne('SELECT is_demo FROM members WHERE id = ?', [$params['id']])['is_demo'];
        if (!$isDemoMember) {
            echo json_encode([
                'ssid'             => !empty($mp['wifi_ssid']) ? demoMaskFull((string)$mp['wifi_ssid']) : '',
                'ip'               => !empty($mp['wifi_ip']) ? demoMaskFull((string)$mp['wifi_ip']) : '',
                'password'         => !empty($mp['wifi_password_enc']) ? '••••••••' : '',
                'firmware_version' => $mp['esp_firmware_version'] ?? '',
                'latest_version'   => latestFirmwareVersion() ?? '',
            ]);
            return;
        }
    }
    echo json_encode([
        'ssid'             => $mp['wifi_ssid'] ?? '',
        'ip'               => $mp['wifi_ip'] ?? '',
        'password'         => decryptSecret($mp['wifi_password_enc']),
        'firmware_version' => $mp['esp_firmware_version'] ?? '',
        'latest_version'   => latestFirmwareVersion() ?? '',
    ]);
});

$router->post('/portal/members/:id/files', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];

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
    // 500 ohne jeden Hinweis, was los ist. \Throwable statt nur \PDOException, weil auch ein
    // TypeError/Error (z.B. hash_file() liefert false bei nicht lesbarer Datei) sonst am
    // globalen Handler vorbei unkontrolliert durchläuft. So bekommt der Manager wenigstens
    // die konkrete Fehlermeldung angezeigt und kann sie weitergeben, statt dass wir blind
    // raten müssen.
    try {
        $sha256 = hash_file('sha256', $destPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Datei konnte nach dem Upload nicht gelesen werden (sha256 fehlgeschlagen).');
        }
        DB::execute(
            'INSERT INTO member_files (community_id, member_id, name, pfad, mime, sha256, hochgeladen_von)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $communityId,
                $params['id'],
                $displayName,
                $destPath,
                $_FILES['file']['type'] ?: null,
                $sha256,
                Auth::userId(),
            ]
        );
    } catch (\Throwable $e) {
        unlink($destPath);
        header('Location: /portal/members/' . $params['id'] . '?error=upload_db&detail=' . urlencode($e->getMessage()));
        exit;
    }

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id/files/:fileid/download', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ? AND community_id = ?',
        [$params['fileid'], $params['id'], $member['community_id']]
    );
    if (!$file || !is_file($file['pfad'])) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

// Profilbild eines Mitglieds ansehen -- entweder das Mitglied selbst oder ein Manager der
// gleichen Community (keine Community-Prüfung nötig, wenn es das eigene Konto ist).
$router->get('/portal/members/:id/avatar', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    // members hat Row-Level Security -- die Community ist hier wie in requireMemberAccess()
    // erst NACH dem Laden bekannt (Henne-Ei-Problem), deshalb dasselbe Muster: Platform-Admin
    // versucht jede Community einzeln, alle anderen nutzen direkt ihre aktive Rolle (reicht
    // auch für "eigenes Avatar ansehen", da die eigene Mitgliedschaft immer zur eigenen
    // aktiven Rolle gehört).
    if (Auth::isPlatformAdmin()) {
        $member = null;
        foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
            DB::setCommunity($c['id']);
            $member = DB::fetchOne('SELECT id, community_id, user_id, photo_path FROM members WHERE id = ?', [$params['id']]);
            if ($member) break;
        }
    } else {
        DB::setCommunity(Auth::activeCommunityId());
        $member = DB::fetchOne('SELECT id, community_id, user_id, photo_path FROM members WHERE id = ?', [$params['id']]);
    }
    if (!$member || !$member['photo_path']) { http_response_code(404); return; }

    $allowed = $member['user_id'] !== null && $member['user_id'] === Auth::userId();
    if (!$allowed) {
        $allowed = Auth::isManager() && (Auth::isPlatformAdmin() || Auth::activeCommunityId() === $member['community_id']);
    }
    if (!$allowed) { http_response_code(403); return; }
    if (!is_file($member['photo_path'])) { http_response_code(404); return; }

    sendImageFileSafely($member['photo_path']);
    // Siehe /logo-:variant.png (Vorfall 29.10.2026): Auth::start() setzt via PHPs
    // Session-Cache-Limiter automatisch ein abgelaufenes Expires + Pragma: no-cache, das dem
    // hier beabsichtigten privaten Caching sonst unbemerkt entgegenläuft.
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: private, max-age=3600');
    readfile($member['photo_path']);
    exit;
});

// Profilbild eines Login-Accounts ohne eigenen Mitgliedsdatensatz (Manager/Platform-Admin) --
// nur der Account selbst oder ein Platform-Admin darf es sehen.
$router->get('/portal/users/:id/avatar', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    if ($params['id'] !== Auth::userId() && !Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user = DB::fetchOne('SELECT photo_path FROM users WHERE id = ?', [$params['id']]);
    if (!$user || !$user['photo_path'] || !is_file($user['photo_path'])) { http_response_code(404); return; }

    sendImageFileSafely($user['photo_path']);
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: private, max-age=3600');
    readfile($user['photo_path']);
    exit;
});

$router->post('/portal/members/:id/photo', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!isset($_FILES['photo'])) { header('Location: /portal/members/' . $params['id'] . '?error=upload'); exit; }

    $err = saveMemberPhoto($params['id'], $_FILES['photo']);
    if ($err === null) {
        header('Location: /portal/members/' . $params['id'] . '?success=1');
    } elseif (str_starts_with($err, 'upload_db:')) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload_db&detail=' . urlencode(substr($err, 10)));
    } elseif ($err === 'phototype') {
        header('Location: /portal/members/' . $params['id'] . '?error=phototype');
    } else {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
    }
    exit;
});

$router->post('/portal/members/:id/metering-points', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];

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

    $meterCode = trim($_POST['meter_code'] ?? '') ?: null;
    // Eine Zählernummer darf sehr wohl zu ZWEI aktiven Zählpunkten gehören: in Österreich haben
    // Bezug und Einspeisung eines Prosumers unterschiedliche Zählpunktnummern (AT...), teilen
    // sich aber denselben physischen Zähler/dieselbe Zählernummer (Patrick, 30.07.2026, nach
    // anfänglich falscher Annahme "nur ein Zählpunkt pro Zähler möglich"). Kein Blocken -- der
    // mqtt-subscriber teilt eingehende ESP-Daten automatisch korrekt auf beide Zählpunkte auf
    // (get_metering_points()), nur eine informative Postfach-Meldung zur Transparenz.
    if ($meterCode) {
        $sharedWith = DB::fetchOne(
            "SELECT 1 FROM metering_points WHERE community_id = ? AND meter_code = ? AND active = true",
            [$communityId, $meterCode]
        );
        if ($sharedWith) {
            notifyMeterCodeShared($communityId, $meterCode);
        }
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, jahresverbrauch_kwh, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $member['id'], $znr, $_POST['type'] ?? 'consumer', $meterCode, $jahresverbrauch, $engpassleistung, $geplanteEinsp]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/** Baut die \item-Liste der Bezugs-Zählpunkte für den Bezugsvertrag. */
function bezugZpLines(array $mps): string
{
    return implode("\n", array_map(fn($mp) => '\\item ' . texEscape($mp['zaehlpunkt_nr']), $mps));
}

/**
 * Voller Name inkl. Anrede und Titel für die Namensanzeige in den Vertragsvorlagen -- nutzt
 * formatMemberName() für den vor-/nachgestellten Titel, stellt davor nur noch die Anrede
 * (Herr/Frau) voran.
 */
function memberFullName(array $member): string
{
    $salutation = trim((string)($member['salutation'] ?? ''));
    $name = formatMemberName($member);
    return $salutation !== '' ? $salutation . ' ' . $name : $name;
}

/** Aktuelle Fassung der Vertragsvorlagen (ElWG, ab 01.10.2026). Wird beim Unterschreiben je Vertrag festgehalten. */
const CONTRACT_TEMPLATE_VERSION = 'elwg-2026-11';

/**
 * Welche LaTeX-Vorlage für einen Vertrag gilt. Verträge werden bei jedem Aufruf live aus der
 * Vorlage gerendert (kein gespeichertes PDF). Damit ein bereits unterschriebener Vertrag nach
 * einer Textänderung nicht plötzlich anders aussieht, gilt: unterschrieben + keine festgehaltene
 * Fassung = Altfassung (ElWOG 2010, eingefrorene Kopie `*_elwog2010.tex`); noch nicht
 * unterschrieben = immer die aktuelle Fassung; beim Unterschreiben wird die aktuelle festgehalten.
 */
function contractTemplateName(string $type, array $member): string
{
    $base = $type === 'einspeisung' ? 'einspeisevereinbarung' : 'bezugsvereinbarung';
    $signed = ($member['contract_' . $type . '_status'] ?? 'none') === 'signed';
    $pinned = $member['contract_' . $type . '_template'] ?? null;
    return ($signed && !$pinned) ? $base . '_elwog2010' : $base;
}

/**
 * Tarif für die Vertrags-Ansicht/Erneut-Versenden: vor der ersten Erstellung der aktuell
 * gültige, danach für immer der zum Erstellungszeitpunkt gültige Tarif -- sonst würde ein
 * bereits versendeter oder gar digital unterschriebener Vertrag bei jeder erneuten Ansicht
 * plötzlich andere Zahlen zeigen, sobald sich der Tarif später ändert.
 */
function contractTariff(string $communityId, ?string $generatedAt): ?array
{
    if ($generatedAt) {
        return DB::fetchOne(
            'SELECT * FROM tariff_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $generatedAt]
        );
    }
    return DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
}

/**
 * Baut die Template-Variablen für die Bezugsvereinbarung. Gemeinsam genutzt von der
 * Ansichts-Route (Browser-Vorschau) und der "Jetzt senden"-Route (E-Mail-Anhang), damit
 * beide exakt denselben Vertragsinhalt erzeugen.
 */
function bezugsvereinbarungVars(array $member, array $community, ?array $tariff, string $zpLines, array $signature, array $memberSignature = ['var' => '', 'assets' => []]): array
{
    return [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => memberFullName($member),
        'MITGLIED_ADRESSE'          => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'      => $member['city'],
        'MITGLIED_UID_ZEILE'        => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEPA_MANDATSREFERENZ' => $member['mandatsreferenz'] ?? '--',
        'MITGLIED_IBAN'             => $member['member_iban'] ?? '--',
        'BEZUG_TARIF'               => $tariff ? number_format((float)$tariff['bezug_ct_kwh'], 4, ',', '.') : '--',
        'MITGLIEDSBEITRAG'          => $tariff ? number_format((float)$tariff['mitgliedsbeitrag_eur'], 2, ',', '.') : '--',
        'TARIF_GUELTIG_AB'          => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'RAW_ZAEHLPUNKTE_LISTE'     => $zpLines,
        // Frei in den EEG-Einstellungen konfigurierbar (communities.dashboard_url), da sich die
        // Verlinkung jederzeit ändern kann -- Standard-Link nur als Fallback, falls nichts gepflegt ist.
        // ?? statt ?: -- ein direkter Array-Zugriff auf einen fehlenden Key (z.B. Spalte noch
        // nicht migriert) erzeugt bei ?: trotzdem eine "Undefined array key"-Warning, die den
        // PDF-Response zerstört (Output vor den header()-Aufrufen). ?? liest den Key sicher aus.
        'EEG_DASHBOARD_URL'         => ($community['dashboard_url'] ?? null) ?: AppConfig::get()->portalBaseUrl() . '/portal/login',
        'RAW_EEG_UNTERSCHRIFT_BILD' => $signature['var'],
        'RAW_MITGLIED_UNTERSCHRIFT_BILD' => $memberSignature['var'],
        'RAW_MITGLIED_ORT_DATUM'    => memberOrtDatumLine($member, 'bezug'),
        'ERSTELLT_AM'               => date('d.m.Y'),
    ];
}

$router->get('/portal/members/:id/contract/bezug', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    if (!contractsEnabled(Auth::activeCommunityId())) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $communityId = $member['community_id'];

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Bezugs-Zählpunkt registriert. Bitte zuerst einen Bezugs-Zählpunkt (Typ: Bezug) anlegen.'; return; }

    $genAt  = $member['contract_bezug_generated_at'] ?? null;
    $status = $member['contract_bezug_status'] ?? 'none';
    $tariff = contractTariff($communityId, $genAt);

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $signature = eegSignatureAsset();
    $memberSig = memberSignatureAsset($member['contract_bezug_customer_signature'] ?? null, $member['community_id']);
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
    $ok = streamLatexPdf(contractTemplateName('bezug', $member), $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);

    // DB-Update NUR nach erfolgreichem PDF, und nicht mehr nach digitaler Unterschrift --
    // ab dann bleibt generated_at eingefroren, damit der signierte Vertrag bei jeder erneuten
    // Ansicht exakt dieselben (zum Unterschriftszeitpunkt gültigen) Tarifzahlen zeigt.
    if ($ok && $status !== 'signed') {
        DB::execute(
            "UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END, contract_bezug_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

/**
 * Baut den {{hinweis}}-Textbaustein für Vertrags-E-Mails: leer bei der erstmaligen Fassung,
 * sonst ein expliziter Hinweis, dass eine frühere, bereits versendete Fassung ab sofort
 * ungültig ist (Version wird beim Zurücksetzen eines gesendeten Vertrags hochgezählt).
 */
function contractInvalidationNote(int $version): string
{
    return $version > 1
        ? '<p><strong>Hinweis:</strong> Dies ist eine korrigierte Fassung. Eine Ihnen zuvor '
          . 'zugesendete frühere Version ist ab sofort ungültig.</p>'
        : '';
}

$router->post('/portal/members/:id/contract/bezug/send', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    if (empty($mps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Kein Bezugs-Zählpunkt registriert.'));
        exit;
    }
    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature);

    // PDF wird hier nur zur Validierung erzeugt (Template-/Latex-Fehler sollen dem Manager
    // sofort auffallen, nicht erst wenn das Mitglied den Portal-Link später öffnet) -- versendet
    // wird kein Anhang mehr, nur eine Benachrichtigung mit Link zur digitalen Unterschrift.
    $error = null;
    $pdf = generateLatexPdf(contractTemplateName('bezug', $member), $vars, $signature['assets'], $error);
    if ($pdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_bezug', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/bezug/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_bezug_version']),
        ],
            'Ihre Bezugsvereinbarung – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Bezugsvereinbarung mit {{eeg_name}} liegt für Sie bereit. '
            . 'Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, '
            . 'damit sie gültig wird:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END, contract_bezug_generated_at = now(), contract_bezug_sent_at = now() WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Bezugsvereinbarung wurde per E-Mail zur digitalen Unterschrift verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Setzt eine bereits versendete Vertragsfassung zurück, damit nach Korrekturen eine neue
 * Fassung erstellt und gesendet werden kann. Nur möglich, wenn der Vertrag tatsächlich schon
 * einmal per "Jetzt senden" verschickt wurde -- sonst gibt es ja noch nichts zurückzunehmen,
 * die reine Generierung/Vorschau kann jederzeit beliebig oft wiederholt werden.
 */
$router->post('/portal/members/:id/contract/:type/reset', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }

    if (empty($member['contract_' . $type . '_sent_at'])) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Dieser Vertrag wurde noch nicht per E-Mail gesendet, ein Zurücksetzen ist daher nicht nötig.'));
        exit;
    }

    DB::execute(
        "UPDATE members SET contract_{$type}_status = 'none', contract_{$type}_sent_at = NULL, contract_{$type}_version = contract_{$type}_version + 1 WHERE id = ?",
        [$params['id']]
    );
    logAudit($member['community_id'], 'contract.reset', 'member', $params['id'],
        ucfirst($type) . 'svereinbarung von ' . $member['first_name'] . ' ' . $member['last_name'] . ' zurückgesetzt (Korrektur erforderlich)');

    header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Vertrag wurde zurückgesetzt und kann neu erstellt werden.'));
    exit;
});

/** Baut die \item-Liste der Einspeise-Zählpunkte für den Einspeisevertrag. */
function einspeisungZpLines(array $mps): string
{
    return implode("\n", array_map(
        function ($mp) {
            $engpass = $mp['engpassleistung_kw'] ? number_format((float)$mp['engpassleistung_kw'], 2, ',', '.') . ' kWp' : '--';
            return '\\item Zählpunktnummer ' . texEscape($mp['zaehlpunkt_nr'])
                . ' --- Erzeugungsart: ' . texEscape($mp['erzeugungsart'] ?? 'Photovoltaik')
                . ', Engpassleistung: ' . $engpass;
        },
        $mps
    ));
}

/** Baut die Anlagenbeschreibung (Adresse/Gst.-Nr./KG) aus den Einspeise-Zählpunkten. */
function einspeisungAnlagenBeschreibung(array $mps): string
{
    return implode('; ', array_filter(array_map(
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
}

/**
 * Baut die Template-Variablen für die Einspeisevereinbarung. Gemeinsam genutzt von der
 * Ansichts-Route und der "Jetzt senden"-Route.
 */
function einspeisevereinbarungVars(array $member, array $community, ?array $tariff, string $zpLines, string $anlagenBeschreibung, array $signature, array $memberSignature = ['var' => '', 'assets' => []]): array
{
    return [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => memberFullName($member),
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
        'RAW_MITGLIED_UNTERSCHRIFT_BILD' => $memberSignature['var'],
        'RAW_MITGLIED_ORT_DATUM'    => memberOrtDatumLine($member, 'einspeisung'),
        'ERSTELLT_AM'               => date('d.m.Y'),
    ];
}

$router->get('/portal/members/:id/contract/einspeisung', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    if (!contractsEnabled(Auth::activeCommunityId())) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $communityId = $member['community_id'];

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Einspeise-Zählpunkt registriert. Bitte zuerst einen Zählpunkt (Typ: Einspeisung) anlegen.'; return; }

    $genAt  = $member['contract_einspeisung_generated_at'] ?? null;
    $status = $member['contract_einspeisung_status'] ?? 'none';
    $tariff = contractTariff($communityId, $genAt);

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $signature = eegSignatureAsset();
    $memberSig = memberSignatureAsset($member['contract_einspeisung_customer_signature'] ?? null, $member['community_id']);
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
    $ok = streamLatexPdf(contractTemplateName('einspeisung', $member), $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);

    if ($ok && $status !== 'signed') {
        DB::execute(
            "UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END, contract_einspeisung_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

$router->post('/portal/members/:id/contract/einspeisung/send', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($mps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Kein Einspeise-Zählpunkt registriert.'));
        exit;
    }
    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature);

    // PDF wird hier nur zur Validierung erzeugt -- versendet wird kein Anhang mehr, nur eine
    // Benachrichtigung mit Link zur digitalen Unterschrift (siehe Kommentar beim Bezugsvertrag).
    $error = null;
    $pdf = generateLatexPdf(contractTemplateName('einspeisung', $member), $vars, $signature['assets'], $error);
    if ($pdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_einspeisung', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/einspeisung/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_einspeisung_version']),
        ],
            'Ihre Einspeisevereinbarung – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Einspeisevereinbarung mit {{eeg_name}} liegt für Sie bereit. '
            . 'Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, '
            . 'damit sie gültig wird:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END, contract_einspeisung_generated_at = now(), contract_einspeisung_sent_at = now() WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Einspeisevereinbarung wurde per E-Mail zur digitalen Unterschrift verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Sendet Bezugs- und Einspeisevereinbarung gemeinsam in einer E-Mail mit beiden PDFs im
 * Anhang -- praktisch für Mitglieder, die sowohl Bezugs- als auch Einspeise-Zählpunkte haben,
 * damit nicht zweimal einzeln gesendet werden muss. Nutzt eine eigene, im Platform-Admin
 * editierbare Vorlage (contract_both), da der Text sich von den Einzel-Vorlagen unterscheiden soll.
 */
$router->post('/portal/members/:id/contract/send-both', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $consumerMps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    $producerMps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($consumerMps) || empty($producerMps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Für den gemeinsamen Versand werden sowohl ein Bezugs- als auch ein Einspeise-Zählpunkt benötigt.'));
        exit;
    }

    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();

    $error = null;
    $bezugVars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($consumerMps), $signature);
    $bezugPdf = generateLatexPdf(contractTemplateName('bezug', $member), $bezugVars, $signature['assets'], $error);
    if ($bezugPdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    $einspeisungVars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($producerMps), einspeisungAnlagenBeschreibung($producerMps), $signature);
    $einspeisungPdf = generateLatexPdf(contractTemplateName('einspeisung', $member), $einspeisungVars, $signature['assets'], $error);
    if ($einspeisungPdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }

    try {
        $hinweis = contractInvalidationNote((int)$member['contract_bezug_version'])
            ?: contractInvalidationNote((int)$member['contract_einspeisung_version']);
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_both', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/documents')),
            'hinweis'  => $hinweis,
        ],
            'Ihre Vereinbarungen – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Bezugsvereinbarung und Ihre Einspeisevereinbarung mit {{eeg_name}} liegen '
            . 'für Sie bereit. Bitte prüfen Sie beide Vereinbarungen im Mitgliederportal und unterschreiben Sie dort '
            . 'digital, damit sie gültig werden:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET
                contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END,
                contract_bezug_generated_at = now(), contract_bezug_sent_at = now(),
                contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END,
                contract_einspeisung_generated_at = now(), contract_einspeisung_sent_at = now()
             WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Beide Vereinbarungen wurden gemeinsam per E-Mail verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

$router->post('/portal/members/:id/contract-status', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];
    $type   = $_POST['type'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!in_array($type, ['bezug', 'einspeisung']) || !in_array($status, ['none', 'created', 'signed'])) {
        http_response_code(400); return;
    }
    // Nach dem Versand ist der Status nur noch über "Zurücksetzen" (setzt sent_at zurück
    // auf NULL) veränderbar -- schützt davor, dass ein bereits versendeter Vertrag über
    // dieses Dropdown unbemerkt "manuell" umgestellt wird.
    if (!empty($member['contract_' . $type . '_sent_at'])) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Bereits versendete Verträge sind nicht mehr über dieses Dropdown änderbar. Bitte zuerst zurücksetzen.'));
        exit;
    }
    $col = 'contract_' . $type . '_status';
    DB::execute("UPDATE members SET {$col} = ? WHERE id = ? AND community_id = ?", [$status, $params['id'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // requireMemberAccess() setzt (anders als der frühere direkte metering_points-Query hier)
    // korrekt DB::setCommunity() -- metering_points hat seit migrate_20260822.sql Row-Level
    // Security, ein Query ohne vorher gesetzte Community liefert für die eingeschränkte
    // Laufzeit-Rolle grundsätzlich GAR KEINE Zeile ("Zählpunkt nicht gefunden" trotz
    // existierendem Zählpunkt).
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    $communityId = $member['community_id'];

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

    $meterCode = trim($_POST['meter_code'] ?? '') ?: null;
    // Siehe Kommentar bei der Anlage-Route: eine Zählernummer darf sehr wohl zu ZWEI aktiven
    // Zählpunkten gehören (Bezug + Einspeisung eines Prosumers, ein physischer Zähler) --
    // kein Blocken, nur eine informative Postfach-Meldung zur Transparenz.
    if ($meterCode) {
        $sharedWith = DB::fetchOne(
            "SELECT 1 FROM metering_points WHERE community_id = ? AND meter_code = ? AND active = true AND id != ?",
            [$communityId, $meterCode, $params['mpid']]
        );
        if ($sharedWith) {
            notifyMeterCodeShared($communityId, $meterCode);
        }
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'UPDATE metering_points SET zaehlpunkt_nr=?, meter_code=?, type=?, jahresverbrauch_kwh=?, engpassleistung_kw=?, geplante_einspeisung_kwh=? WHERE id=? AND community_id=?',
        [
            $znr,
            $meterCode,
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
    // Siehe Kommentar bei .../edit oben -- gleicher RLS-Fix (requireMemberAccess() statt
    // direktem metering_points-Query ohne gesetzte Community).
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    $communityId = $member['community_id'];
    DB::execute('UPDATE metering_points SET active=false WHERE id=? AND community_id=?', [$params['mpid'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/**
 * Testphase-Reset (Patrick, 30.07.2026): löscht ALLE ESP-Live-Messdaten (esp_measurements) und
 * setzt den Online-/WLAN-Status auf allen Zählpunkten EINES Mitglieds zurück -- bewusst pro
 * Mitglied statt für die ganze EEG auf einmal, damit ein Reset beim Testen mit einem einzelnen
 * Test-Zählpunkt nicht versehentlich die Daten anderer Mitglieder mitlöscht. Nur im Testmodus
 * verfügbar (Platform-Admin -> Plattform-Technik) -- sobald eine EEG in den echten Betrieb
 * wechselt, verschwindet dieser destruktive Button automatisch, damit niemand aus Versehen
 * echte Live-Daten löscht.
 */
$router->post('/portal/members/:id/reset-live-data', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!platformTestMode()) {
        http_response_code(403); echo 'Nur im Testmodus verfügbar (Platform-Admin -> Plattform-Technik).'; return;
    }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mpIds = array_column(
        DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ?', [$member['id']]),
        'id'
    );
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $deleted = DB::execute("DELETE FROM esp_measurements WHERE metering_point_id IN ($placeholders)", $mpIds);
        DB::execute(
            // meter_reachable ist NOT NULL DEFAULT false (migrate_20260820.sql) -- auf false statt
            // NULL zuruecksetzen, sonst verletzt das UPDATE den NOT-NULL-Constraint (siehe
            // SQLSTATE[23502] beim ersten Versuch, Patrick 30.07.2026).
            "UPDATE metering_points
             SET esp_online = false, esp_last_seen_at = NULL, meter_reachable = false,
                 meter_last_seen_at = NULL, wifi_ssid = NULL, wifi_ip = NULL, wifi_password_enc = NULL
             WHERE id IN ($placeholders)",
            $mpIds
        );
        logAudit($member['community_id'], 'live_daten_reset', 'member', $member['id'],
            'Live-ESP-Messdaten zurückgesetzt (Testphase): ' . $deleted . ' Messzeilen gelöscht, '
            . count($mpIds) . ' Zählpunkt(e) auf "keine Daten" zurückgesetzt.');
    }
    header('Location: /portal/members/' . $params['id'] . '?success=live_reset');
    exit;
});

// ─── Portal: Passwort ändern ────────────────────────────
/**
 * Löst die members.id des eingeloggten Users in der angegebenen Community auf -- bevorzugt die
 * member_id der aktuell aktiven Rolle (Demo-Logins mit mehreren Mitglied-Identitäten in
 * derselben Community, siehe migrate_20260905.sql), fällt sonst auf die bisherige
 * user_id-Suche zurück (unverändertes Verhalten für alle echten Accounts, die weiterhin
 * höchstens einen Mitgliedsdatensatz je Community haben).
 */
function activeMemberId(string $communityId): ?string
{
    $active = Auth::activeRole();
    if ($active && ($active['community_id'] ?? null) === $communityId && !empty($active['member_id'])) {
        return $active['member_id'];
    }
    $row = DB::fetchOne('SELECT id FROM members WHERE user_id = ? AND community_id = ?', [Auth::userId(), $communityId]);
    return $row['id'] ?? null;
}

/**
 * Mitgliedsdatensatz des eingeloggten Users in der aktuell aktiven Community (falls
 * vorhanden) -- für das Profilbild in /portal/profile. Gibt null zurück für Accounts ohne
 * eigenen Mitgliedsdatensatz (reine Manager/Platform-Admins); die haben ihr Profilbild dann
 * stattdessen direkt am Login-Account (users.photo_path, siehe saveUserPhoto()).
 */
function currentProfileMember(): ?array
{
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { return null; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { return null; }
    return DB::fetchOne('SELECT id, photo_path, salutation FROM members WHERE id = ?', [$memberId]);
}

/**
 * Vollständiger Mitgliedsdatensatz des eingeloggten Users in der aktuell aktiven Community --
 * für die Selbstbedienungs-Ansichten (eigene Verträge/Dateien/Beitrittserklärung). Anders als
 * currentProfileMember() (nur id/photo_path/salutation fürs Profilbild) wird hier die
 * komplette Zeile gebraucht (Adresse, IBAN, Zustimmungen etc. für die Vertragsvorlagen).
 */
function currentMemberFull(): ?array
{
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { return null; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { return null; }
    return DB::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
}

/**
 * Stellt alle zu einem Mitglied gespeicherten personenbezogenen Daten strukturiert zusammen --
 * DSGVO Art. 15 (Auskunftsrecht) bzw. Art. 20 (Datenübertragbarkeit), maschinenlesbar als JSON.
 * Bewusst OHNE sicherheitskritische interne Felder (Passwort-Hash, API-Key-Hash, Signier-Token)
 * und ohne die eingebetteten Unterschriftsbilder (große Base64-Blobs ohne Mehrwert im Export).
 */
function buildMemberDsgvoExport(array $member): array
{
    $cid = $member['community_id'];
    $mid = $member['id'];
    DB::setCommunity($cid);

    $strip = function (array $rows, array $keys): array {
        foreach ($rows as &$r) { foreach ($keys as $k) { unset($r[$k]); } }
        return $rows;
    };

    $konto = null;
    if (!empty($member['user_id'])) {
        // Explizit nur unbedenkliche Spalten -- NICHT password_hash/reset_token.
        $konto = DB::fetchOne(
            'SELECT email, first_name, last_name, active, created_at, last_login_at FROM users WHERE id = ?',
            [$member['user_id']]
        );
    }

    $rechnungen = DB::fetchAll('SELECT * FROM invoices WHERE member_id = ? AND community_id = ? ORDER BY created_at', [$mid, $cid]);
    foreach ($rechnungen as &$r) {
        $r['positionen'] = DB::fetchAll('SELECT type, label, kwh, rate_ct_kwh, quantity, unit, amount_eur, zaehlpunkt_nr FROM invoice_items WHERE invoice_id = ?', [$r['id']]);
    }
    unset($r);

    return [
        'export_erzeugt_am' => date('c'),
        'hinweis' => 'DSGVO-Datenauskunft (Art. 15) bzw. Datenübertragbarkeit (Art. 20). Enthält alle '
            . 'zu dieser Person gespeicherten personenbezogenen Daten. Sicherheitskritische interne Felder '
            . '(Passwort-Hash, API-Key-Hash, Signier-Token) und eingebettete Unterschriftsbilder sind bewusst '
            . 'nicht enthalten.',
        'stammdaten'          => $member,
        'login_konto'         => $konto,
        'zaehlpunkte'         => DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND community_id = ?', [$mid, $cid]),
        'beitrittserklaerung' => $strip(DB::fetchAll('SELECT * FROM membership_applications WHERE member_id = ? AND community_id = ?', [$mid, $cid]), ['signature_image']),
        'vertraege'           => $strip(DB::fetchAll('SELECT * FROM contracts WHERE member_id = ? AND community_id = ?', [$mid, $cid]), ['signature_image', 'sign_token', 'sign_token_expires_at']),
        'rechnungen'          => $rechnungen,
        'hochgeladene_dateien'=> DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? AND community_id = ?', [$mid, $cid]),
        'api_zugaenge'        => $strip(DB::fetchAll('SELECT * FROM member_api_keys WHERE member_id = ?', [$mid]), ['key_hash']),
    ];
}

/** Sendet ein DSGVO-Export-Array als JSON-Download. */
function sendDsgvoExport(array $data, string $filename): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Mitglied exportiert seine eigenen Daten (Selbstauskunft).
$router->get('/portal/my/dsgvo-export', function () {
    Auth::requireLogin();
    denyDemoFileDownload();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    logAudit($member['community_id'], 'dsgvo.export.self', 'member', $member['id'], 'Mitglied hat DSGVO-Selbstauskunft exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? 'mitglied') . '.json');
});

// Manager/Platform-Admin exportiert die Daten eines bestimmten Mitglieds (Auskunftsersuchen).
$router->get('/portal/members/:id/dsgvo-export', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Mitglied nicht gefunden.'; return; }
    logAudit($communityId, 'dsgvo.export.manager', 'member', $member['id'],
        'DSGVO-Auskunft für ' . $member['first_name'] . ' ' . $member['last_name'] . ' exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? $member['id']) . '.json');
});

$router->get('/portal/profile', function () {
    Auth::requireLogin();
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path, totp_enabled FROM users WHERE id = ?', [Auth::userId()]);
    $profileMember = currentProfileMember();
    $profilePasskeys = DB::fetchAll('SELECT id, label, created_at, last_used_at FROM user_passkeys WHERE user_id = ? ORDER BY created_at', [Auth::userId()]);
    $kcLogin = Services::keycloakAuth()->profileInfo();
    if (!empty($_GET['success'])) { $success = $_GET['success']; }
    if (!empty($_GET['error'])) { $error = $_GET['error']; }
    require ROOT . '/src/views/pages/profile.php';
});

// Name ändern. Die E-Mail-Adresse wird NICHT hier geändert, sondern nur mit Bestätigungscode
// (/portal/profile/email/request + /confirm), damit keine fremde Adresse eingetragen werden kann.
$router->post('/portal/profile', function () {
    Auth::requireLogin();
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    if (!$firstName || !$lastName) {
        header('Location: /portal/profile?error=' . urlencode('Vor- und Nachname sind Pflichtfelder.'));
        exit;
    }
    DB::execute('UPDATE users SET first_name=?, last_name=? WHERE id=?', [$firstName, $lastName, Auth::userId()]);
    header('Location: /portal/profile?success=' . urlencode('Daten wurden gespeichert.'));
    exit;
});

/**
 * E-Mail-Adresse ändern, Schritt 1: Code (6 Ziffern) an die NEUE Adresse senden. Der Zustand liegt
 * nur in der Sitzung (Code als HMAC, 15 Minuten gültig, höchstens 5 Eingaben), keine Migration nötig.
 */
$router->post('/portal/profile/email/request', function () {
    Auth::requireLogin();
    $back = function (string $key, string $msg): never {
        header('Location: /portal/profile?' . $key . '=' . urlencode($msg) . '#email');
        exit;
    };
    $email = strtolower(trim($_POST['new_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $back('error', 'Ungültige E-Mail-Adresse.');
    }
    $own = DB::fetchOne('SELECT email FROM users WHERE id = ?', [Auth::userId()]);
    if (strtolower((string)($own['email'] ?? '')) === $email) {
        $back('error', 'Das ist bereits Ihre aktuelle E-Mail-Adresse.');
    }
    if (!RateLimiter::allow('email_change', Auth::userId(), 5, 3600)) {
        $back('error', 'Zu viele Versuche. Bitte später erneut versuchen.');
    }
    if (DB::fetchOne('SELECT 1 AS x FROM users WHERE lower(email) = ? AND id <> ?', [$email, Auth::userId()])) {
        $back('error', 'Diese E-Mail-Adresse kann nicht verwendet werden.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    try {
        $u = DB::fetchOne('SELECT first_name FROM users WHERE id = ?', [Auth::userId()]);
        $mail = renderMailTemplate('email_change_code', [
            'vorname' => htmlspecialchars($u['first_name'] ?? ''),
            'code'    => $code,
        ], 'Ihr Bestätigungscode – Strom für alle',
            '<p>Hallo {{vorname}},</p>'
            . '<p>Ihr Bestätigungscode für die neue E-Mail-Adresse lautet: <strong style="font-size:1.3em;letter-spacing:.2em">{{code}}</strong></p>'
            . '<p>Er ist 15 Minuten gültig. Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>');
        Mailer::send($email, $mail['subject'], $mail['body']);
    } catch (\Throwable $e) {
        error_log('[email_change_mail] ' . $e->getMessage());
        $back('error', 'Der Code konnte nicht gesendet werden. Bitte später erneut versuchen.');
    }
    $_SESSION['email_change'] = [
        'email' => $email,
        'hash'  => hash_hmac('sha256', $code, (getenv('APP_SECRET') ?: '') . Auth::userId()),
        'exp'   => time() + 900,
        'tries' => 0,
    ];
    $back('success', 'Wir haben einen 6-stelligen Code an die neue Adresse gesendet.');
});

/** E-Mail-Adresse ändern, Schritt 2: Code prüfen, dann Login-Adresse UND Mitglieds-E-Mail ändern. */
$router->post('/portal/profile/email/confirm', function () {
    Auth::requireLogin();
    $back = function (string $key, string $msg): never {
        header('Location: /portal/profile?' . $key . '=' . urlencode($msg) . '#email');
        exit;
    };
    $p = $_SESSION['email_change'] ?? null;
    if (!is_array($p) || $p['exp'] < time()) {
        unset($_SESSION['email_change']);
        $back('error', 'Der Code ist abgelaufen. Bitte neu anfordern.');
    }
    if ($p['tries'] >= 5) {
        unset($_SESSION['email_change']);
        $back('error', 'Zu viele falsche Eingaben. Bitte einen neuen Code anfordern.');
    }
    $_SESSION['email_change']['tries']++;
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    if (!hash_equals($p['hash'], hash_hmac('sha256', $code, (getenv('APP_SECRET') ?: '') . Auth::userId()))) {
        $back('error', 'Der Code stimmt nicht.');
    }
    $new = $p['email'];
    $userId = Auth::userId();
    if (DB::fetchOne('SELECT 1 AS x FROM users WHERE lower(email) = ? AND id <> ?', [$new, $userId])) {
        unset($_SESSION['email_change']);
        $back('error', 'Diese E-Mail-Adresse kann nicht verwendet werden.');
    }
    DB::execute('UPDATE users SET email = ? WHERE id = ?', [$new, $userId]);
    // Mitglieds-E-Mail (Rechnungen, Mails) in allen EEGs mitändern, in denen dieser Login Mitglied ist.
    $cids = array_column(DB::fetchAll('SELECT DISTINCT community_id FROM user_roles WHERE user_id = ? AND community_id IS NOT NULL', [$userId]), 'community_id');
    foreach ($cids as $cid) {
        DB::setCommunity($cid);
        DB::execute('UPDATE members SET email = ? WHERE user_id = ?', [$new, $userId]);
    }
    $_SESSION['user_email'] = $new;
    unset($_SESSION['email_change']);
    logAudit(null, 'user.email.change', 'user', $userId, 'E-Mail-Adresse (Login und Mitgliedsdaten) mit Bestätigungscode geändert');
    $back('success', 'E-Mail-Adresse geändert. Anmeldung, Rechnungen und Mails laufen jetzt über die neue Adresse.');
});

$router->post('/portal/profile/email/cancel', function () {
    Auth::requireLogin();
    unset($_SESSION['email_change']);
    header('Location: /portal/profile#email');
    exit;
});

$router->post('/portal/profile/photo', function () {
    Auth::requireLogin();
    if (!isset($_FILES['photo'])) { header('Location: /portal/profile?error=upload'); exit; }

    // Mit Community-Mitgliedsdatensatz (Mitglied, ggf. auch Manager mit eigener Mitgliedschaft):
    // Bild hängt am Mitglied, damit es auch in der Mitgliederliste/-detailseite erscheint.
    // Ohne Mitgliedsdatensatz (reiner Manager-/Platform-Admin-Account): Bild hängt am Login.
    $profileMember = currentProfileMember();
    $err = $profileMember
        ? saveMemberPhoto($profileMember['id'], $_FILES['photo'])
        : saveUserPhoto(Auth::userId(), $_FILES['photo']);

    if ($err === null) {
        header('Location: /portal/profile?success=' . urlencode('Profilbild gespeichert.'));
    } else {
        header('Location: /portal/profile?error=' . urlencode('Profilbild konnte nicht gespeichert werden.'));
    }
    exit;
});

/**
 * 2FA-Einrichtung starten: erzeugt ein neues TOTP-Secret und legt es NUR in der Session ab
 * (noch nicht in der DB / noch nicht aktiv). Erst nach erfolgreicher Code-Bestätigung wird es
 * gespeichert und aktiviert -- so ist sichergestellt, dass die App den Code auch wirklich liefert.
 */
$router->get('/portal/profile/2fa/setup', function () {
    Auth::requireLogin();
    $secret = totpGenerateSecret();
    $_SESSION['2fa_setup_secret'] = $secret;
    $account = $_SESSION['user_email'] ?? 'konto';
    $otpauthUri = totpProvisioningUri($secret, $account, 'Strom für alle');
    require ROOT . '/src/views/pages/profile_2fa.php';
});

$router->post('/portal/profile/2fa/enable', function () {
    Auth::requireLogin();
    $secret = $_SESSION['2fa_setup_secret'] ?? '';
    if ($secret === '') { header('Location: /portal/profile/2fa/setup'); exit; }
    if (!totpVerify($secret, $_POST['code'] ?? '')) {
        $account = $_SESSION['user_email'] ?? 'konto';
        $otpauthUri = totpProvisioningUri($secret, $account, 'Strom für alle');
        $error = 'Der Code stimmt nicht. Bitte den aktuellen 6-stelligen Code eingeben.';
        require ROOT . '/src/views/pages/profile_2fa.php';
        exit;
    }
    // Verschlüsselt speichern (AES-256-CBC, gleiche encryptSecret()-Funktion wie für das
    // ESP-WLAN-Passwort) statt im Klartext -- OWASP-Audit 13.08.2026: ein DB-Dump/-Leak hätte
    // sonst direkt alle 2FA-Codes kompromittiert, ganz ohne das jeweilige Passwort zu brauchen.
    DB::execute('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?',
        [encryptSecret($secret), 'true', Auth::userId()]);
    unset($_SESSION['2fa_setup_secret']);
    logAudit(null, 'user.2fa.enable', 'user', Auth::userId(), 'Zwei-Faktor-Authentifizierung (TOTP) aktiviert');
    header('Location: /portal/profile?success=' . urlencode('Zwei-Faktor-Authentifizierung ist jetzt aktiv.'));
    exit;
});

$router->post('/portal/profile/2fa/disable', function () {
    Auth::requireLogin();
    // Der Nutzer ist bereits authentifiziert -> Ausschalten ohne erneute Code-Abfrage (bewusst
    // einfach gehalten, da häufiger Account-Wechsel gewünscht ist). Secret wird gelöscht, ein
    // erneutes Aktivieren erzeugt ein frisches.
    DB::execute("UPDATE users SET totp_enabled = 'false', totp_secret = NULL WHERE id = ?", [Auth::userId()]);
    logAudit(null, 'user.2fa.disable', 'user', Auth::userId(), 'Zwei-Faktor-Authentifizierung (TOTP) deaktiviert');
    header('Location: /portal/profile?success=' . urlencode('Zwei-Faktor-Authentifizierung wurde deaktiviert.'));
    exit;
});

// Passwort ändern ist Teil des Profils (Abschnitt "Passwort ändern"); alte Adresse bleibt als Weiterleitung.
$router->get('/portal/password', function () {
    Auth::requireLogin();
    header('Location: /portal/profile#passwort');
    exit;
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
    } elseif (isPasswordBreached($new)) {
        $error = 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.';
    } else {
        $hash = hashPassword($new);
        DB::execute('UPDATE users SET password_hash=? WHERE id=?', [$hash, $userId]);
        header('Location: /portal/profile?success=' . urlencode('Passwort wurde erfolgreich geändert.') . '#passwort');
        exit;
    }
    header('Location: /portal/profile?error=' . urlencode($error) . '#passwort');
    exit;
});

// ─── Portal: Abrechnung ─────────────────────────────────
$router->get('/portal/billing', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    // POST /portal/billing/generate (u.a.) leiten bei einem Fehler auf ?error=... um -- diese
    // Route hat das bisher nie in die $error-Variable übernommen, die die View unten prüft
    // (!empty($error)), wodurch eine fehlgeschlagene Berechnung (z.B. wegen L3-Werten) komplett
    // ohne jede Rückmeldung blieb, obwohl sie korrekt geloggt wurde (Patrick, 07.08.2026:
    // "Wollte Rechnung berechnen, geht aber nicht, und es kommt auch nichts").
    $error = $_GET['error'] ?? null;
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $runs = DB::fetchAll(
        'SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]
    );
    $extraItemsByRun = [];
    foreach (DB::fetchAll('SELECT * FROM billing_run_extra_items WHERE community_id = ? ORDER BY created_at', [$communityId]) as $ei) {
        $extraItemsByRun[$ei['billing_run_id']][] = $ei;
    }
    // Fehlende Monatsimporte je Lauf schon in der Übersicht sichtbar machen, nicht erst als
    // Fehlermeldung beim Freigabe-Versuch (siehe Billing::missingMonths(), Patrick 05.08.2026).
    $missingMonthsByRun = [];
    $qualityByRun = [];
    // Ausführungsdatum-Auswahl-Modal (pain.008-Download): die 8 Kacheln je freigegebenem Lauf
    // schon hier serverseitig berechnen (Patrick, 18.10.2026) -- die Seite wird bei jedem Aufruf
    // neu gerendert, "heute" ist also immer aktuell.
    $sepaTilesByRun = [];
    $today = date('Y-m-d');
    $minDays = (int)($community['sepa_prenotification_days'] ?? SEPA_PRENOTIFICATION_DAYS);
    foreach ($runs as $run) {
        $missingMonthsByRun[$run['id']] = Billing::missingMonths($communityId, $run['period_from'], $run['period_to']);
        $qualityByRun[$run['id']] = Billing::edaQualitySummary($communityId, $run['period_from'], $run['period_to']);
        if ($run['status'] === 'done') {
            $invoiceDate = $run['released_at'] ? date('Y-m-d', strtotime($run['released_at'])) : $today;
            $sepaTilesByRun[$run['id']] = sepaExecutionDateTiles(
                $invoiceDate, $minDays, $today,
                $run['sepa_collection_date_used'] ? date('Y-m-d', strtotime($run['sepa_collection_date_used'])) : null
            );
        }
    }
    require ROOT . '/src/views/pages/billing.php';
});

/**
 * Test-Vorschau einer Rechnung mit Platzhalter-Werten statt echten Mitgliedsdaten -- erzeugt
 * keinen Datenbankeintrag, dient nur dazu, das Layout (bzw. eine angepasste rechnung.tex) zu
 * begutachten. Nutzt die echten Stammdaten der Community (Name/Adresse/IBAN/Logo), damit die
 * Vorschau realistisch aussieht.
 */
$router->get('/portal/billing/preview', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tax = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    // Beispiel-Netto der Vorschau (Summe der Beispielpositionen) durch die konfigurierte
    // Steuerlogik schicken, damit die Vorschau Kleinunternehmer vs. Standard (mit USt) zeigt.
    $tbPreview = taxBreakdown(49.04, $tax['tax_model'] ?? null, $tax['tax_rate_percent'] ?? null);
    if ($tbPreview['model'] === 'standard') {
        $ustFmt = number_format($tbPreview['ust'], 2, ',', '.');
        $satzFmt = rtrim(rtrim(number_format($tbPreview['rate'], 2, ',', '.'), '0'), ',');
        $steuerZeile = '\\multicolumn{3}{r}{\\footnotesize Netto} & \\footnotesize EUR ' . number_format($tbPreview['netto'], 2, ',', '.') . ' \\\\'
            . '\\multicolumn{3}{r}{\\footnotesize zzgl.\\,' . $satzFmt . '\\,\\% USt} & \\footnotesize EUR ' . $ustFmt . ' \\\\';
        $steuerText  = 'Im Rechnungsbetrag sind ' . $satzFmt . '\\,\\% Umsatzsteuer (EUR ' . $ustFmt . ') enthalten.';
    } else {
        // Kein eigener Tabellenzeile-Hinweis mehr -- siehe invoicePdfPayload() für die
        // ausführliche Begründung (Hinweis stand sonst doppelt auf der Rechnung).
        $steuerZeile = '';
        $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';
    }
    $summeTabelleLabel = $tbPreview['model'] === 'standard' ? 'Summe netto' : 'Summe';

    // Beispiel-Zusatzposition, damit die Vorschau auch zeigt, wie ein Rabatt/eine Gutschrift
    // aussieht (siehe /portal/billing -> Zusatzpositionen) -- inkl. "(anteilig, …)"-Beispiel,
    // damit auch dieses Label in der Vorschau sichtbar ist.
    $extraItemsLatex = rechnungExtraItemsLatex([
        ['label' => 'Beispiel: Rabatt Mitgliedsbeitrag Q1 (anteilig, 2 Monate)', 'quantity' => 1, 'unit' => 'Stk', 'amount_eur' => -6.00],
    ]);
    // Beispielhafte Positionslisten -- zeigen zwei Bezugs-Zählpunkte (mehrere Zeilen, einer davon
    // mit Beispiel-"Teilnahme ab") und einen Einspeise-Zählpunkt mit Zählpunktnummer als grauer
    // Zweitzeile.
    $bezugListe = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000001', 'kwh' => 250.00, 'rate_ct_kwh' => 9.80, 'amount_eur' => 24.50,
         'teilnahme_ab' => date('Y-m-d', strtotime('-10 days'))],
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000002', 'kwh' => 162.50, 'rate_ct_kwh' => 9.80, 'amount_eur' => 15.93],
    ], 'Energiebezug aus der Gemeinschaft', false);
    $einspListe = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000003', 'kwh' => 85.00, 'rate_ct_kwh' => 7.50, 'amount_eur' => -6.38],
    ], 'Einspeisevergütung (Gutschrift)', true);

    $eegAdrTeile = array_map('trim', explode(',', $community['address'] ?? 'Musterstraße 1, 9020 Klagenfurt', 2));

    streamLatexPdf('rechnung', [
        'EEG_NAME'              => $community['name'] ?? 'Muster-EEG',
        'EEG_ADRESSE'           => $community['address'] ?? 'Musterstraße 1, 9020 Klagenfurt',
        'EEG_STRASSE'           => $eegAdrTeile[0] ?? '',
        'EEG_PLZ_ORT'           => $eegAdrTeile[1] ?? '',
        'EEG_UID'               => $tax['uid_number'] ?? '',
        'EEG_ZVR'               => $community['zvr_number'] ?? '1778816746',
        'EEG_OBMANN_TELEFON'    => $community['contact_phone'] ?? '',
        'EEG_KONTAKT_EMAIL'     => $community['contact_email'] ?? '',
        'EEG_BANKNAME'          => $community['bank_name'] ?? '',
        'EEG_KONTOINHABER'      => $community['account_holder'] ?? '',
        'MITGLIED_ANREDE'       => 'Herr',
        'MITGLIED_NAME'         => 'Max Mustermann',
        'MITGLIED_ADRESSE'      => 'Musterweg 12, 9020 Klagenfurt',
        'MITGLIED_STRASSE'      => 'Musterweg 12',
        'MITGLIED_PLZ_ORT'      => '9020 Klagenfurt',
        'MITGLIED_UID'          => '',
        'KUNDENNUMMER'          => '123',
        'MITGLIED_SEPA_MANDATSREFERENZ' => 'MUSTER-MANDAT-001',
        'RECHNUNGSNUMMER'       => 'MUSTER-2026-Q1-000',
        'RECHNUNGSDATUM'        => date('d.m.Y'),
        'ABRECHNUNGSZEITRAUM'   => '01.01.2026 -- 31.03.2026',
        'BEZUG_KWH'             => '412,50',
        'BEZUG_TARIF'           => '9,8000',
        'BEZUG_BETRAG'          => '40,42',
        'EINSPEISUNG_KWH'       => '85,00',
        'EINSPEISUNG_TARIF'     => '7,5000',
        'EINSPEISUNG_BETRAG'    => '6,38',
        'RAW_BEZUG_POSITIONEN_LISTE'       => $bezugListe,
        'RAW_EINSPEISUNG_POSITIONEN_LISTE' => $einspListe,
        'MITGLIEDSBEITRAG_LABEL' => 'Mitgliedsbeitrag (anteilig, 2 Monate)',
        'MITGLIEDSBEITRAG'      => '15,00',
        'SUMME_TABELLE_LABEL'   => $summeTabelleLabel,
        'SUMME_NETTO'           => number_format($tbPreview['netto'], 2, ',', '.'),
        'RAW_SUMME_NETTO'       => rechnungBetragUnicode($tbPreview['netto']),
        'SUMME_BRUTTO'          => number_format($tbPreview['brutto'], 2, ',', '.'),
        'RAW_SUMME_BRUTTO'      => rechnungBetragUnicode($tbPreview['brutto']),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'RAW_ZUSATZPOSITIONEN_LISTE' => $extraItemsLatex,
        'RAW_ZAHLUNG_TEXT'      => 'Der Rechnungsbetrag von \\textbf{EUR ' . number_format($tbPreview['brutto'], 2, ',', '.') . '} wird gemäß SEPA-Lastschriftmandat'
            . ' (Mandatsreferenz \\textbf{MUSTER-MANDAT-001}) am \\textbf{' . date('d.m.Y', strtotime('+14 days')) . '}'
            . ' von Ihrem Konto eingezogen. Sie müssen nichts weiter veranlassen.'
            . ' Diese Rechnung gilt zugleich als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.',
        'RAW_SUMME_LABEL'       => '',
        'IBAN'                  => $community['iban'] ?? 'AT00 0000 0000 0000 0000',
        'BIC'                   => $community['bic'] ?? '',
        'ZAHLUNGSZIEL'          => date('d.m.Y', strtotime('+14 days')),
    ], 'Test-Rechnung.pdf', communityLogoAsset($communityId));
});

/**
 * Legt einen neuen Abrechnungslauf für ein Quartal an (z.B. "2026-Q1"). Ohne diese Route gab
 * es bisher gar keine Möglichkeit, überhaupt einen billing_runs-Eintrag anzulegen -- die
 * gesamte Rechnungs-Funktion war dadurch faktisch nie erreichbar.
 */
$router->post('/portal/billing/create', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $quartal = trim($_POST['quartal'] ?? '');
    if (!preg_match('/^\d{4}-(Q[1-4]|(0[1-9]|1[0-2]))$/', $quartal)) {
        header('Location: /portal/billing?error=' . urlencode('Ungültiges Zeitraum-Format (z.B. 2026-Q1 für ein Quartal oder 2026-07 für einen Monat).'));
        exit;
    }
    try {
        Billing::getOrCreateRun($communityId, $quartal);
        logAudit($communityId, 'billing.create', 'billing_run', null, 'Abrechnungslauf ' . $quartal . ' angelegt');
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Manuelle Zusatzposition (z.B. einmaliger Rabatt/Gutschrift) zu einem noch nicht berechneten
 * Abrechnungslauf hinzufügen -- gilt für alle Mitglieder dieses Laufs, wird bei der Berechnung
 * (Billing::generateDrafts()) in jede einzelne Rechnung übernommen.
 */
$router->post('/portal/billing/:id/extra-items', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { http_response_code(404); echo 'Abrechnungslauf nicht gefunden'; return; }
    if ($run['status'] !== 'pending') {
        header('Location: /portal/billing?error=' . urlencode('Zusatzpositionen können nur vor der Freigabe hinzugefügt werden.'));
        exit;
    }
    $label = trim($_POST['label'] ?? '');
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if ($label === '' || !is_numeric($amount)) {
        header('Location: /portal/billing?error=' . urlencode('Bitte Text und Betrag angeben.'));
        exit;
    }
    DB::execute(
        'INSERT INTO billing_run_extra_items (billing_run_id, community_id, label, quantity, unit, amount_eur)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$params['id'], $communityId, $label, str_replace(',', '.', trim($_POST['quantity'] ?? '') ?: '1'),
         trim($_POST['unit'] ?? '') ?: 'Stk', (float)$amount]
    );
    logAudit($communityId, 'billing.extra_item.add', 'billing_run', $params['id'], 'Zusatzposition "' . $label . '" (' . $amount . ' EUR) hinzugefügt');
    header('Location: /portal/billing?success=1');
    exit;
});

$router->post('/portal/billing/:id/extra-items/:itemId/delete', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    DB::execute(
        'DELETE FROM billing_run_extra_items WHERE id = ? AND billing_run_id = ? AND community_id = ?',
        [$params['itemId'], $params['id'], $communityId]
    );
    logAudit($communityId, 'billing.extra_item.delete', 'billing_run', $params['id'], 'Zusatzposition entfernt');
    header('Location: /portal/billing?success=1');
    exit;
});

/**
 * Eigener Reiter "Rechnungen" (getrennt von /portal/billing, das nur die Abrechnungsläufe pro
 * Quartal zeigt): listet einzelne Rechnungen aller Mitglieder der aktiven Community, filterbar
 * client-seitig nach Kundennummer/Name (Text), Quartal und Betrag (min/max). $_GET['quartal']
 * setzt die Quartals-Auswahl serverseitig vor, damit der "Rechnungen ansehen"-Link aus
 * /portal/billing direkt gefiltert aufmachen kann.
 */
$router->get('/portal/billing/invoices', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal, br.status AS run_status, m.kundennummer, m.first_name, m.last_name,
                m.company_name, m.email, m.member_iban, m.mandatsreferenz, m.is_demo,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
         FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN members m ON m.id = i.member_id
         LEFT JOIN LATERAL (
             SELECT tax_model, tax_rate_percent FROM tax_config
             WHERE community_id = i.community_id AND valid_from <= br.period_from
             ORDER BY valid_from DESC LIMIT 1
         ) tx ON true
         WHERE i.community_id = ?
         ORDER BY i.created_at DESC',
        [$communityId]
    );
    $invoices = demoMaskMembers($invoices, Auth::isDemo());
    // Anzeige immer in Brutto: bei Kleinunternehmer identisch mit netto, bei Standard inkl. USt.
    foreach ($invoices as &$inv) {
        $inv['brutto_eur'] = taxBreakdown((float)$inv['saldo_eur'], $inv['eeg_tax_model'] ?? null, $inv['eeg_tax_rate'] ?? null)['brutto'];
    }
    unset($inv);
    $quartalFilter = $_GET['quartal'] ?? '';
    // Fortschritt der Zahlungsabwicklung nur über bereits freigegebene Rechnungen (run_status
    // 'done') -- Entwürfe zählen nicht als "offen".
    $released = array_filter($invoices, fn($i) => ($i['run_status'] ?? '') === 'done');
    $paymentDone = count(array_filter($released, fn($i) => in_array($i['payment_status'] ?? 'offen', ['eingezogen', 'ueberwiesen'], true)));
    $paymentTotal = count($released);
    require ROOT . '/src/views/pages/billing_invoices.php';
});

/**
 * Sammel-Download aller Rechnungen eines Abrechnungslaufs (Quartal oder Monat) als ZIP
 * (Patrick, 18.10.2026: "alle Rechnungen aus einer Abrechnungsperiode auf einmal
 * runterladen... ein zip mit Monat"). Nutzt für jede Rechnung dieselbe Erzeugung wie der
 * Einzel-Download (invoicePdfBytes()) -- eingefrorene (freigegebene) Rechnungen kommen aus der
 * gespeicherten Datei, Entwürfe werden live gerendert. ZipArchive schreibt in eine temporäre
 * Datei statt den ganzen Inhalt im Speicher aufzubauen, damit auch viele Rechnungen
 * unproblematisch bleiben.
 */
$router->get('/portal/billing/invoices.zip', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $quartal = trim($_GET['quartal'] ?? '');
    if ($quartal === '') {
        http_response_code(400);
        echo 'Bitte zuerst ein Quartal/einen Zeitraum auswählen.';
        return;
    }

    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE community_id = ? AND quartal = ?', [$communityId, $quartal]);
    if (!$run) { http_response_code(404); echo 'Abrechnungslauf nicht gefunden.'; return; }

    $invoiceIds = DB::fetchAll('SELECT id FROM invoices WHERE billing_run_id = ? ORDER BY rechnungsnummer', [$run['id']]);
    if (!$invoiceIds) { http_response_code(404); echo 'Keine Rechnungen für diesen Zeitraum.'; return; }

    $tmpPath = tempnam(sys_get_temp_dir(), 'eeg-rechnungen-');
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'ZIP konnte nicht erstellt werden.';
        return;
    }

    // Einzelne fehlgeschlagene PDFs (z.B. latex-service kurzzeitig nicht erreichbar) brechen
    // den ganzen Download nicht ab -- lieber ein ZIP mit den restlichen Rechnungen plus
    // Audit-Log-Eintrag, als dass eine einzige kaputte Rechnung alle anderen blockiert.
    $fehler = [];
    foreach ($invoiceIds as $row) {
        $invoice = loadInvoiceForPdf($row['id']);
        if (!$invoice) continue;
        $error = null;
        $body = invoicePdfBytes($invoice, $error);
        if ($body === null) {
            $fehler[] = $invoice['rechnungsnummer'] . ($error ? ' (' . $error . ')' : '');
            continue;
        }
        $zip->addFromString($invoice['rechnungsnummer'] . '.pdf', $body);
    }
    $zip->close();

    if ($fehler) {
        logAudit($communityId, 'billing.zip_error', 'billing_run', $run['id'],
            'ZIP-Sammeldownload ' . $quartal . ': ' . count($fehler) . ' Rechnung(en) fehlgeschlagen: ' . implode(', ', $fehler));
    }

    $zipFilename = 'Rechnungen_' . $quartal . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($zipFilename) . '"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
    unlink($tmpPath);
});

// Schritt 1: Rechnungen aus den EDA-Daten berechnen (Entwurf) -- Lauf geht auf 'ready',
// danach pro Rechnung manuell nachbearbeitbar. Auch erneut aufrufbar ("neu berechnen").
$router->post('/portal/billing/generate', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    // Fehlte hier bisher (anders als bei jeder anderen /portal/billing/*-Route) -- Billing::
    // generateDrafts() liest billing_runs aber ALS ERSTES per reinem "SELECT ... WHERE id = ?"
    // (ohne community_id in der WHERE-Klausel, siehe Billing.php), noch bevor die Funktion
    // selbst DB::setCommunity() aufruft. Row-Level-Security (siehe DB.php: "USING (community_id
    // = current_setting('app.community_id', true)::uuid)") ließ diese Abfrage dadurch buchstäblich
    // JEDE Zeile verwerfen, weil app.community_id für diese Anfrage noch nie gesetzt worden war --
    // "Abrechnungslauf nicht gefunden", obwohl der Lauf echt existierte (Patrick, 26.09.2026: neu
    // angelegten Lauf für Juli berechnen wollte, Fehler kam trotzdem, auch nach Löschen/Neu-Anlegen).
    DB::setCommunity($communityId);
    try {
        Billing::generateDrafts($runId);
        logAudit($communityId, 'billing.generate', 'billing_run', $runId, 'Rechnungs-Entwürfe berechnet');
        header('Location: /portal/billing?success=' . urlencode('Rechnungs-Entwürfe berechnet. Sie können jede Rechnung vor der Freigabe noch anpassen.'));
    } catch (Throwable $e) {
        logAudit($communityId, 'billing.generate', 'billing_run', $runId, 'Berechnung fehlgeschlagen: ' . $e->getMessage(), true);
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

// Schritt 2: berechneten Lauf endgültig freigeben (60-Tage-Gate). Route-Name "release" bleibt
// aus Kompatibilität, ruft aber jetzt finalize() (Berechnung ist ein eigener Schritt davor).
$router->post('/portal/billing/release', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    // Derselbe fehlende DB::setCommunity()-Aufruf wie bei /portal/billing/generate oben --
    // Billing::finalize() liest billing_runs ebenso zuerst per rohem "SELECT ... WHERE id = ?",
    // bevor irgendwo intern DB::setCommunity() gesetzt wird. Siehe Kommentar dort.
    DB::setCommunity($communityId);
    try {
        Billing::finalize($runId, Auth::userId());
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Abrechnungslauf freigegeben');
        $msg = 'Freigegeben.';
        // Jede Rechnung dieses Laufs per Mail verschicken (eingefrorenes PDF als Anhang, siehe
        // sendInvoiceReleasedEmails()) -- eigener try, damit ein Mail-/DB-Problem die bereits
        // erfolgreiche Freigabe nicht als "fehlgeschlagen" erscheinen lässt.
        try {
            $mailCount = sendInvoiceReleasedEmails($communityId, $runId);
            if ($mailCount > 0) $msg = 'Freigegeben. ' . $mailCount . ' Rechnung(en) per E-Mail versendet.';
        } catch (Throwable $e) {
            error_log('Rechnungs-Mail (Lauf ' . $runId . '): ' . $e->getMessage());
            $msg = 'Freigegeben. Hinweis: Rechnungs-E-Mails konnten nicht versendet werden.';
        }
        // Zusätzlich für alle einzuziehenden Salden (saldo > 0) am selben Tag die SEPA-
        // Vorabinformation (Pre-Notification), die zusätzlich zur Rechnungs-Mail oben das
        // voraussichtliche Abbuchungsdatum (= Rechnungsdatum + Vorlauftage der EEG) ankündigt --
        // ebenfalls ein eigener try, aus demselben Grund.
        try {
            $preInfo = sendSepaPrenotifications($communityId, $runId);
            if ($preInfo > 0) $msg .= ' ' . $preInfo . ' SEPA-Vorabinformation(en) versendet.';
        } catch (Throwable $e) {
            error_log('SEPA-Vorabinfo (Lauf ' . $runId . '): ' . $e->getMessage());
            $msg .= ' Hinweis: SEPA-Vorabinformationen konnten nicht versendet werden.';
        }
        // Direkt nach der Freigabe zur Gutschriften-Übersicht weiterleiten, falls in diesem Lauf
        // überhaupt Guthaben (saldo_eur < 0) entstanden sind -- Patrick, 26.09.2026: er will die
        // Überweisungen dafür direkt im Anschluss der Reihe nach in seinem Online-Banking
        // eintragen, nicht erst wieder danach suchen. Kein Gutschriften-Fall -> normale Meldung
        // wie bisher.
        $hatGutschriften = DB::fetchOne(
            'SELECT 1 FROM invoices WHERE billing_run_id = ? AND saldo_eur < 0 LIMIT 1',
            [$runId]
        );
        if ($hatGutschriften) {
            header('Location: /portal/billing/gutschriften?run_id=' . urlencode($runId) . '&success=' . urlencode($msg));
        } else {
            header('Location: /portal/billing?success=' . urlencode($msg));
        }
    } catch (Throwable $e) {
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Freigabe fehlgeschlagen: ' . $e->getMessage(), true);
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Liefert alle noch offenen Gutschriften (Auszahlungen an Mitglieder mit negativem Saldo,
 * gutschrift_ausgezahlt_at IS NULL) einer Community -- optional auf einen einzelnen
 * Abrechnungslauf eingeschränkt. Grundlage für /portal/billing/gutschriften; die Login-Erinnerung
 * im Portal-Layout (layouts/portal.php) zählt separat per eigenem COUNT(*), da sie nur die Anzahl
 * braucht, nicht die vollen Zeilen (gleiche WHERE-Bedingung: saldo_eur < 0 UND
 * gutschrift_ausgezahlt_at IS NULL).
 */
function offeneGutschriften(string $communityId, ?string $runId = null): array
{
    $sql = 'SELECT i.id, i.rechnungsnummer, i.saldo_eur, br.quartal,
                   m.first_name, m.last_name, m.company_name, m.invoice_name, m.titel_vor, m.titel_nach,
                   m.kontoinhaber, m.member_iban, m.member_bic,
                   tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
            FROM invoices i
            JOIN billing_runs br ON br.id = i.billing_run_id
            JOIN members m ON m.id = i.member_id
            LEFT JOIN LATERAL (
                SELECT tax_model, tax_rate_percent FROM tax_config
                WHERE community_id = ? AND valid_from <= br.period_from
                ORDER BY valid_from DESC LIMIT 1
            ) tx ON true
            WHERE i.community_id = ? AND i.saldo_eur < 0 AND i.gutschrift_ausgezahlt_at IS NULL';
    $params = [$communityId, $communityId];
    if ($runId !== null) {
        $sql .= ' AND i.billing_run_id = ?';
        $params[] = $runId;
    }
    $sql .= ' ORDER BY br.quartal, m.last_name, m.first_name';
    $rows = DB::fetchAll($sql, $params);

    // Derselbe Aufbau wie in renderInvoicePdf() (Anzeigename-Priorität, taxBreakdown() für den
    // tatsächlich zu überweisenden Brutto-Betrag) -- der hier ausgewiesene Betrag muss exakt dem
    // "Ihre Gutschrift von ..."-Betrag auf dem PDF entsprechen.
    return array_map(function ($r) {
        $tax = taxBreakdown((float)$r['saldo_eur'], $r['eeg_tax_model'], $r['eeg_tax_rate']);
        $anzeigeName = ($r['invoice_name'] ?? '') ?: (($r['company_name'] ?? '') ?: formatMemberName($r));
        // Kontoinhaber-Fallback bewusst OHNE formatMemberName() (kein nachgestellter Titel mit
        // Komma) -- landet ggf. als Kopiervorlage direkt im Online-Banking (siehe
        // billing_gutschriften.php), dort sind einfache Namen ohne Zusätze erwartet.
        $kontoName = trim((!empty($r['titel_vor']) ? $r['titel_vor'] . ' ' : '') . $r['first_name'] . ' ' . $r['last_name']);
        return [
            'invoice_id'       => $r['id'],
            'quartal'          => $r['quartal'],
            'name'             => $anzeigeName,
            'kontoinhaber'     => $r['kontoinhaber'] ?: $kontoName,
            'iban'             => $r['member_iban'] ?? '',
            'bic'              => $r['member_bic'] ?? '',
            'betrag'           => number_format(abs($tax['brutto']), 2, ',', '.'),
            'verwendungszweck' => $r['rechnungsnummer'],
        ];
    }, $rows);
}

/**
 * Übersicht der offenen Gutschriften (Auszahlungen an Mitglieder mit negativem Saldo) --
 * Kontoinhaber/IBAN/Betrag/Verwendungszweck je Mitglied, jeweils mit eigenem Kopieren-Button,
 * damit Patrick die Überweisungen der Reihe nach händisch im Online-Banking eintragen kann
 * (aktuell kein automatisierter SEPA-Überweisungsexport, nur der bereits bestehende
 * SEPA-LASTSCHRIFT-XML-Export für die einzuziehenden, positiven Salden). Ohne ?run_id=
 * kommunityweit über ALLE Läufe (Ziel der Login-Erinnerung/des "Gutschriften"-Menüpunkts), mit
 * ?run_id= auf einen einzelnen Lauf eingeschränkt (Ziel des Buttons direkt nach der Freigabe
 * bzw. in der Abrechnungsliste). Jede hier angezeigte Gutschrift lässt sich per Button als
 * "Überweisung durchgeführt" markieren -- verschwindet danach aus dieser Liste UND aus der
 * Login-Erinnerung (Patrick, 26.09.2026: "Erst wenn's durchgeführt ist, soll es dann irgendwo
 * weg sein, damit ich auch nicht vergesse, dass ich noch Geld an meine Mitglieder überweisen muss").
 */
$router->get('/portal/billing/gutschriften', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    // Enthält echte Kontoinhaber-Namen/IBANs -- dieselbe Sensibilität wie das WLAN-Info-Feld
    // oder das Mitglied-Bearbeiten-Formular, deshalb für den Demo-Zugang komplett gesperrt statt
    // nur maskiert (eine Liste zum Abkopieren ergibt maskiert ohnehin keinen Sinn).
    denyDemoPage('Dieser Demo-Zugang dient nur zur Ansicht. Aus Datenschutzgründen können hier keine echten Bankdaten angezeigt werden.');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $runId = trim((string)($_GET['run_id'] ?? '')) ?: null;
    $run = null;
    if ($runId !== null) {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$runId, $communityId]);
        if (!$run) { header('Location: /portal/billing?error=' . urlencode('Abrechnungslauf nicht gefunden.')); exit; }
    }

    $gutschriften = offeneGutschriften($communityId, $runId);
    // Ausführungsdatum-Auswahl-Modal (pain.001-Download) -- anders als die Lastschrift braucht
    // die Überweisung keine Vorabankündigungsfrist, startet also direkt ab heute (Patrick,
    // 18.10.2026: "ich kann es ja gleich ab heute überweisen ... brauche keine zwei Wochen zu
    // warten"), siehe bankingDayTiles().
    $today = date('Y-m-d');
    $sepaTransferTiles = !empty($gutschriften) ? bankingDayTiles(
        $today, SEPA_TRANSFER_TILE_COUNT,
        ($run && $run['sepa_transfer_date_used']) ? date('Y-m-d', strtotime($run['sepa_transfer_date_used'])) : null
    ) : null;
    require ROOT . '/src/views/pages/billing_gutschriften.php';
});

/**
 * Markiert eine einzelne Gutschrift als manuell überwiesen -- verschwindet danach aus
 * /portal/billing/gutschriften und aus der Login-Erinnerung. Bewusst kein "Rückgängig"-Button
 * (siehe Frontend: der native confirm()-Dialog vor dem Absenden ist die einzige "Abbrechen"-
 * Möglichkeit, gleiches Muster wie überall sonst im Portal, z.B. beim Löschen eines Abrechnungslaufs).
 */
$router->post('/portal/billing/gutschriften/:invoiceId/erledigt', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    // Setzt BEIDE "erledigt"-Felder -- gutschrift_ausgezahlt_at (steuert diese Liste + die
    // Login-Erinnerung) UND payment_status/paid_at (steuert die Jahresübersicht/Rechnungsliste,
    // siehe /portal/billing/invoices/:id/mark-paid). Vorher setzte diese Route nur Ersteres --
    // über die Rechnungsliste als "überwiesen" markierte Gutschriften verschwanden zwar aus der
    // Jahresübersicht, blieben aber als "offen" in dieser Liste und der Login-Erinnerung hängen,
    // und umgekehrt.
    DB::execute(
        "UPDATE invoices SET gutschrift_ausgezahlt_at = now(), payment_status = 'ueberwiesen', paid_at = now()
         WHERE id = ? AND community_id = ? AND saldo_eur < 0 AND gutschrift_ausgezahlt_at IS NULL",
        [$params['invoiceId'], $communityId]
    );
    logAudit($communityId, 'billing.gutschrift_erledigt', 'invoice', $params['invoiceId'], 'Gutschrift als überwiesen markiert');
    $runId = trim((string)($_POST['run_id'] ?? ''));
    header('Location: /portal/billing/gutschriften' . ($runId !== '' ? '?run_id=' . urlencode($runId) : ''));
    exit;
});

/**
 * Sammelt die offenen Gutschriften (siehe offeneGutschriften()) für eine SEPA-Sammelüberweisung
 * (pain.001, Auszahlungs-Richtung) auf -- Pendant zu sepaCollectionData() (Lastschrift,
 * Einzugs-Richtung). Gutschriften ohne gültige Empfänger-IBAN werden NICHT aufgenommen (sonst
 * weist die Bank die komplette Datei zurück), sondern separat unter 'ohne_iban' gemeldet, damit
 * der Obmann sie nicht stillschweigend übergeht (analog zu 'ohne_mandat' bei sepaCollectionData).
 * Rückgabe: ['payer' => [...], 'txns' => [...], 'ohne_iban' => [...]].
 */
function sepaCreditTransferData(string $communityId, ?string $runId = null): array
{
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $sql = 'SELECT i.rechnungsnummer, i.saldo_eur,
                   m.first_name, m.last_name, m.company_name, m.invoice_name, m.titel_vor,
                   m.kontoinhaber, m.member_iban, m.member_bic, m.address, m.zip, m.city,
                   tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
            FROM invoices i
            JOIN billing_runs br ON br.id = i.billing_run_id
            JOIN members m ON m.id = i.member_id
            LEFT JOIN LATERAL (
                SELECT tax_model, tax_rate_percent FROM tax_config
                WHERE community_id = ? AND valid_from <= br.period_from
                ORDER BY valid_from DESC LIMIT 1
            ) tx ON true
            WHERE i.community_id = ? AND i.saldo_eur < 0 AND i.gutschrift_ausgezahlt_at IS NULL';
    $params = [$communityId, $communityId];
    if ($runId !== null) {
        $sql .= ' AND i.billing_run_id = ?';
        $params[] = $runId;
    }
    $rows = DB::fetchAll($sql, $params);

    $txns = [];
    $ohneIban = [];
    foreach ($rows as $r) {
        // Bewusst OHNE formatMemberName() -- landet direkt im SEPA-Kontoinhaber-Namen der
        // pain.001-XML, dort nur einfacher Name wie bisher (kein nachgestellter Titel mit Komma).
        $anzeigeName = ($r['invoice_name'] ?? '')
            ?: (($r['company_name'] ?? '')
            ?: trim((!empty($r['titel_vor']) ? $r['titel_vor'] . ' ' : '') . $r['first_name'] . ' ' . $r['last_name']));
        $kontoinhaber = $r['kontoinhaber'] ?: $anzeigeName;
        $iban = trim((string)$r['member_iban']);
        // Gleiche Steuerlogik wie überall sonst bei Gutschriften (taxBreakdown() auf saldo_eur) --
        // der hier überwiesene Betrag muss exakt dem "Ihre Gutschrift von ..."-Betrag auf dem
        // PDF und der Kopierliste unter /portal/billing/gutschriften entsprechen.
        $brutto = abs(taxBreakdown((float)$r['saldo_eur'], $r['eeg_tax_model'] ?? null, $r['eeg_tax_rate'] ?? null)['brutto']);
        if ($iban === '' || !validateIban($iban)) {
            $ohneIban[] = ['name' => $anzeigeName, 'rechnungsnummer' => $r['rechnungsnummer'], 'betrag' => $brutto];
            continue;
        }
        $txns[] = [
            'end_to_end_id' => $r['rechnungsnummer'],
            'amount'        => $brutto,
            'creditor_name' => $kontoinhaber,
            'creditor_iban' => $iban,
            'creditor_bic'  => trim((string)$r['member_bic']),
            'remittance'    => $r['rechnungsnummer'],
            // Strukturierte Postadresse (EPC-Vorgabe ab dem [verschobenen] Stichtag für
            // pain.001/pain.008, siehe sepaPostalAddressXml()).
            'creditor_street' => $r['address'] ?? '',
            'creditor_zip'    => $r['zip'] ?? '',
            'creditor_city'   => $r['city'] ?? '',
        ];
    }
    $eegAddr = splitEegAddress($community['address'] ?? '');
    $payer = [
        'name'   => $community['account_holder'] ?? ($community['name'] ?? ''),
        'iban'   => trim((string)($community['iban'] ?? '')),
        'bic'    => trim((string)($community['bic'] ?? '')),
        'street' => $eegAddr['street'],
        'zip'    => $eegAddr['zip'],
        'city'   => $eegAddr['city'],
    ];
    return ['payer' => $payer, 'txns' => $txns, 'ohne_iban' => $ohneIban];
}

/**
 * SEPA-Sammelüberweisung (pain.001) für alle offenen Gutschriften herunterladen -- Pendant zur
 * bestehenden Sammellastschrift (/portal/billing/:id/sepa-xml), nur für die AUSZAHLUNGS-Richtung
 * und bewusst OHNE :id in der Route, analog zur Gutschriften-Übersicht selbst: optional auf
 * einen einzelnen Lauf eingeschränkt (?run_id=), sonst über alle offenen Gutschriften der EEG.
 */
$router->get('/portal/billing/gutschriften/sepa-xml', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    // Enthält IBAN/Name ALLER Mitglieder mit offener Gutschrift auf einmal -- mindestens so
    // sensibel wie die bestehende Sammellastschrift, siehe denyDemoFileDownload().
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $runId = trim((string)($_GET['run_id'] ?? '')) ?: null;
    $backUrl = '/portal/billing/gutschriften' . ($runId !== null ? '?run_id=' . urlencode($runId) : '');
    $errSep  = $runId !== null ? '&' : '?';
    $run = null;
    if ($runId !== null) {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$runId, $communityId]);
        if (!$run) { header('Location: ' . $backUrl . $errSep . 'error=' . urlencode('Abrechnungslauf nicht gefunden.')); exit; }
    }
    $data = sepaCreditTransferData($communityId, $runId);
    if ($data['payer']['iban'] === '') {
        header('Location: ' . $backUrl . $errSep . 'error=' . urlencode('Bitte zuerst die EEG-IBAN in den Einstellungen hinterlegen.')); exit;
    }
    if (empty($data['txns'])) {
        header('Location: ' . $backUrl . $errSep . 'error=' . urlencode('Keine Gutschriften mit gültiger Empfänger-IBAN offen.')); exit;
    }

    // Ausführungsdatum kommt aus dem Auswahl-Modal (siehe billing_gutschriften.php) -- die
    // Überweisung braucht anders als die Lastschrift keine Vorabankündigungsfrist, "heute" ist
    // deshalb direkt der früheste mögliche Tag (Patrick, 18.10.2026).
    $today = date('Y-m-d');
    $date = trim((string)($_GET['date'] ?? ''));
    if ($date === '') {
        header('Location: ' . $backUrl . $errSep . 'error=' . urlencode('Bitte ein Ausführungsdatum auswählen.')); exit;
    }
    // Server-seitige Prüfung, unabhängig vom Frontend-Modal (Patrick: "nicht nur im Frontend").
    $validationError = validateTransferExecutionDate($date, $today);
    if ($validationError !== null) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo $validationError;
        return;
    }

    $msgId = 'SFA-GUT-' . date('YmdHis');
    $xml = sepaPain001Xml($data['payer'], $data['txns'], $date, $msgId);
    logAudit($communityId, 'billing.sepa_credit_export', $runId !== null ? 'billing_run' : 'community', $runId ?? $communityId,
        'SEPA-Überweisungs-XML (pain.001.001.03) mit ' . count($data['txns']) . ' Gutschrift(en) erzeugt, Ausführungsdatum ' . $date);
    if ($runId !== null) {
        DB::execute('UPDATE billing_runs SET sepa_transfer_date_used = ? WHERE id = ?', [$date, $runId]);
    }
    $fname = 'Gutschriften-' . date('Ymd') . '-pain001.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
});

/**
 * Sammelt die für einen freigegebenen Abrechnungslauf einzuziehenden Rechnungen (saldo > 0)
 * inkl. der SEPA-Mandatsdaten des jeweiligen Mitglieds. Rechnungen ohne verwertbares Mandat
 * (fehlende IBAN oder Mandatsreferenz) werden separat unter 'ohne_mandat' zurückgegeben, damit
 * der Obmann sie nicht stillschweigend übergeht.
 * Rückgabe: ['creditor' => [...], 'txns' => [...], 'ohne_mandat' => [...]].
 */
function sepaCollectionData(string $communityId, string $runId): array
{
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $run = DB::fetchOne('SELECT period_from FROM billing_runs WHERE id = ?', [$runId]);
    // Für den Einzug zählt der Brutto-Betrag (bei Standard inkl. USt) -- gleiche Steuerlogik
    // wie auf der Rechnung, damit Rechnung, SEPA und Vorabinfo denselben Betrag ausweisen.
    $tx = DB::fetchOne(
        'SELECT tax_model, tax_rate_percent FROM tax_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
        [$communityId, $run['period_from'] ?? date('Y-m-d')]
    );
    $rows = DB::fetchAll(
        "SELECT i.rechnungsnummer, i.saldo_eur, br.quartal,
                m.first_name, m.last_name, m.company_name, m.member_iban, m.member_bic,
                m.address, m.zip, m.city,
                m.mandatsreferenz,
                -- mandat_unterschrift_datum (siehe migrate_20261018.sql) hat Vorrang -- das
                -- einzige Feld mit einem echten Formular/Schreibpfad dafür. sepa_mandate_date
                -- bleibt als zweiter Fallback bestehen (seit migrate_20260808.sql vorhanden,
                -- aber ohne UI immer NULL -- falls je von Hand gesetzt, zählt es trotzdem).
                -- Ohne beides: beitrittsdatum (Registrierungsdatum, siehe Bericht) als bisheriges
                -- Verhalten, zuletzt member_since/CURRENT_DATE als letzte Fallbacks.
                COALESCE(m.mandat_unterschrift_datum, m.sepa_mandate_date, m.beitrittsdatum, m.member_since, CURRENT_DATE) AS mandate_date
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN members m ON m.id = i.member_id
          WHERE i.billing_run_id = ? AND i.community_id = ? AND i.saldo_eur > 0
          ORDER BY m.kundennummer",
        [$runId, $communityId]
    );
    $txns = [];
    $ohneMandat = [];
    foreach ($rows as $r) {
        $name = trim(($r['company_name'] ?: '') ?: ($r['first_name'] . ' ' . $r['last_name']));
        $iban = trim((string)$r['member_iban']);
        $ref  = trim((string)$r['mandatsreferenz']);
        $brutto = taxBreakdown((float)$r['saldo_eur'], $tx['tax_model'] ?? null, $tx['tax_rate_percent'] ?? null)['brutto'];
        // Ohne verwertbares Mandat (fehlende/ungültige IBAN oder fehlende Mandatsreferenz) NICHT
        // in die Sammellastschrift aufnehmen -- eine einzige ungültige IBAN lässt sonst die Bank
        // die komplette Datei zurückweisen.
        if ($iban === '' || $ref === '' || !validateIban($iban)) {
            $ohneMandat[] = ['name' => $name, 'rechnungsnummer' => $r['rechnungsnummer'], 'saldo' => $brutto];
            continue;
        }
        $txns[] = [
            'end_to_end_id' => $r['rechnungsnummer'],
            'amount'        => $brutto,
            'mandate_ref'   => $ref,
            'mandate_date'  => substr((string)$r['mandate_date'], 0, 10),
            'debtor_name'   => $name,
            'debtor_iban'   => $iban,
            'debtor_bic'    => trim((string)$r['member_bic']),
            'remittance'    => 'Rechnung ' . $r['rechnungsnummer'] . ' (' . $r['quartal'] . ') ' . ($community['name'] ?? ''),
            // Strukturierte Postadresse (EPC-Vorgabe ab dem [verschobenen] Stichtag für
            // pain.001/pain.008, siehe sepaPostalAddressXml()).
            'debtor_street' => $r['address'] ?? '',
            'debtor_zip'    => $r['zip'] ?? '',
            'debtor_city'   => $r['city'] ?? '',
        ];
    }
    $eegAddr = splitEegAddress($community['address'] ?? '');
    $creditor = [
        'name'        => $community['name'] ?? '',
        'iban'        => trim((string)($community['iban'] ?? '')),
        'bic'         => trim((string)($community['bic'] ?? '')),
        'creditor_id' => trim((string)($community['creditor_id'] ?? '')),
        'street'      => $eegAddr['street'],
        'zip'         => $eegAddr['zip'],
        'city'        => $eegAddr['city'],
    ];
    return ['creditor' => $creditor, 'txns' => $txns, 'ohne_mandat' => $ohneMandat, 'community' => $community];
}

/**
 * Versendet die Rechnung per E-Mail (eingefrorenes PDF, siehe freezeInvoicePdf(), als Anhang)
 * für JEDE Rechnung eines freigegebenen Laufs -- unabhängig vom Saldo (Forderung, Gutschrift
 * ODER 0,00 €). Schließt damit die Lücke, dass bisher NUR Mitglieder mit einzuziehendem Saldo
 * (über die separate SEPA-Vorabinfo) überhaupt eine Mail bekamen -- Mitglieder mit Gutschrift
 * oder ohne App-Login erfuhren von ihrer Rechnung bisher gar nicht automatisch, sondern nur
 * wenn sie selbst ins Portal schauten.
 *
 * Patrick, 02.10.2026, zur Fälligkeit: "Ab dem Zeitpunkt, an dem die E-Mail mit den fertigen
 * Rechnungen rausgeschickt wird, gelten die 14 Tage [...] erst, wenn wirklich freigegeben und
 * abgesendet werden." Genau das bildet diese Funktion ab -- sie läuft als Teil DESSELBEN
 * Freigabe-Vorgangs (direkt nach Billing::finalize(), siehe /portal/billing/release), der auch
 * RECHNUNGSDATUM/ZAHLUNGSZIEL auf released_at setzt (renderInvoicePdf()) -- Freigabe und
 * E-Mail-Versand fallen dadurch auf denselben Zeitpunkt, "freigegeben" bedeutet also faktisch
 * "abgesendet". Scheitert der Versand für eine einzelne Rechnung (Mailfehler), bleibt
 * email_sent_at für GENAU diese Rechnung leer -- RECHNUNGSDATUM/ZAHLUNGSZIEL auf dem PDF
 * selbst ändern sich dadurch nicht (sie hängen an released_at, nicht an email_sent_at); das
 * Mitglied kann die Rechnung aber jederzeit auch direkt im Portal einsehen, unabhängig vom
 * Mail-Status. Gibt die Anzahl tatsächlich versendeter Mails zurück.
 *
 * Nur an Mitglieder mit E-Mail-Adresse UND zustimmung_email_kommunikation=true (dieselbe
 * Zustimmung, die bei der Beitrittserklärung extra für "Zustellung von Rechnungen [...] per
 * E-Mail" eingeholt wird) -- ohne diese Zustimmung bleibt der Weg übers Portal der einzige.
 */
function sendInvoiceReleasedEmails(string $communityId, string $runId): int
{
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $rows = DB::fetchAll(
        "SELECT i.id, i.rechnungsnummer, i.saldo_eur, i.pdf_frozen_path,
                m.first_name, m.last_name, m.company_name, m.salutation, m.titel_vor,
                m.email_anrede_mode, m.email, m.zustimmung_email_kommunikation,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN members m ON m.id = i.member_id
           LEFT JOIN LATERAL (
               SELECT tax_model, tax_rate_percent FROM tax_config
               WHERE community_id = i.community_id AND valid_from <= br.period_from
               ORDER BY valid_from DESC LIMIT 1
           ) tx ON true
          WHERE i.billing_run_id = ? AND i.community_id = ? AND i.email_sent_at IS NULL",
        [$runId, $communityId]
    );
    $sent = 0;
    foreach ($rows as $r) {
        if (empty($r['email']) || !$r['zustimmung_email_kommunikation']) continue;
        // Ohne eingefrorenes PDF (z.B. weil freezeInvoicePdf() beim Freigeben scheiterte, siehe
        // dort) lieber gar keine Mail als eine Mail ohne Anhang -- das Mitglied bekommt dann
        // beim nächsten erfolgreichen Einfrieren (oder nie, falls das Problem bestehen bleibt)
        // alternativ nur den Portal-Zugriff.
        if (empty($r['pdf_frozen_path']) || !is_file($r['pdf_frozen_path'])) continue;
        $brutto = taxBreakdown((float)$r['saldo_eur'], $r['eeg_tax_model'] ?? null, $r['eeg_tax_rate'] ?? null)['brutto'];
        $anrede = mailSalutation($r);
        $gutschrift = $brutto < 0;
        $betragText = $gutschrift
            ? 'eine Gutschrift von <strong>' . number_format(abs($brutto), 2, ',', '.') . ' €</strong>, die wir Ihnen auf Ihr Konto überweisen'
            : 'ein offener Betrag von <strong>' . number_format(abs($brutto), 2, ',', '.') . ' €</strong>';
        try {
            $mail = renderMailTemplate('invoice_released', [
                'anrede'          => htmlspecialchars($anrede['anrede']),
                'nachname'        => htmlspecialchars($anrede['nachname']),
                'eeg_name'        => htmlspecialchars((string)($community['name'] ?? '')),
                'rechnungsnummer' => htmlspecialchars((string)$r['rechnungsnummer']),
                'betrag_text'     => $betragText,
            ],
                'Ihre Rechnung {{rechnungsnummer}} – {{eeg_name}}',
                '<p>{{anrede}} {{nachname}},</p>'
                . '<p>im Anhang finden Sie Ihre Rechnung <strong>{{rechnungsnummer}}</strong> von {{eeg_name}}. '
                . 'Darin ausgewiesen ist {{betrag_text}}.</p>'
                . '<p>Sie können die Rechnung außerdem jederzeit im Mitgliederportal einsehen.</p>'
            );
            Mailer::send($r['email'], $mail['subject'], $mail['body'], [[
                'name'        => $r['rechnungsnummer'] . '.pdf',
                'contentType' => 'application/pdf',
                'content'     => file_get_contents($r['pdf_frozen_path']),
            ]]);
            DB::execute('UPDATE invoices SET email_sent_at = now() WHERE id = ?', [$r['id']]);
            $sent++;
        } catch (Throwable $e) {
            error_log('Rechnungs-Mail fehlgeschlagen für Rechnung ' . $r['rechnungsnummer'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * Versendet die SEPA-Vorabinformation für alle noch nicht vorab-informierten, einzuziehenden
 * Rechnungen (saldo > 0, Mandat vorhanden) eines freigegebenen Laufs. Gibt die Anzahl
 * tatsächlich versendeter Mails zurück. Setzt invoices.prenotified_at, damit bei einer
 * erneuten Freigabe/Verarbeitung keine Doppel-Mail entsteht.
 */
function sendSepaPrenotifications(string $communityId, string $runId): int
{
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $days = (int)($community['sepa_prenotification_days'] ?? 14);
    $abbuchung = date('d.m.Y', strtotime('+' . $days . ' days'));
    $rows = DB::fetchAll(
        "SELECT i.id, i.rechnungsnummer, i.saldo_eur, br.quartal, br.period_from,
                m.first_name, m.last_name, m.company_name, m.salutation, m.titel_vor,
                m.email_anrede_mode, m.email, m.mandatsreferenz, m.member_iban
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN members m ON m.id = i.member_id
          WHERE i.billing_run_id = ? AND i.community_id = ? AND i.saldo_eur > 0
            AND i.prenotified_at IS NULL",
        [$runId, $communityId]
    );
    // USt-Modell einmal für den Lauf bestimmen (Betrag in der Vorabinfo = Brutto).
    $tx = DB::fetchOne(
        'SELECT tax_model, tax_rate_percent FROM tax_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
        [$communityId, $rows[0]['period_from'] ?? date('Y-m-d')]
    );
    $sent = 0;
    foreach ($rows as $r) {
        $ref = trim((string)$r['mandatsreferenz']);
        $iban = trim((string)$r['member_iban']);
        if (empty($r['email']) || $ref === '' || $iban === '') continue;
        $brutto = taxBreakdown((float)$r['saldo_eur'], $tx['tax_model'] ?? null, $tx['tax_rate_percent'] ?? null)['brutto'];
        $anrede = mailSalutation($r);
        try {
            $mail = renderMailTemplate('sepa_prenotification', [
                'vorname'        => htmlspecialchars((string)$r['first_name']),
                'anrede'         => htmlspecialchars($anrede['anrede']),
                'nachname'       => htmlspecialchars($anrede['nachname']),
                'eeg_name'       => htmlspecialchars((string)($community['name'] ?? '')),
                'rechnungsnummer'=> htmlspecialchars((string)$r['rechnungsnummer']),
                'betrag'         => number_format($brutto, 2, ',', '.'),
                'abbuchung'      => $abbuchung,
                'mandatsreferenz'=> htmlspecialchars($ref),
                'creditor_id'    => htmlspecialchars((string)($community['creditor_id'] ?? '')),
            ],
                'SEPA-Vorabinformation zu Rechnung {{rechnungsnummer}} – {{eeg_name}}',
                '<p>{{anrede}} {{nachname}},</p>'
                . '<p>Ihre Rechnung <strong>{{rechnungsnummer}}</strong> über <strong>{{betrag}} €</strong> wird '
                . 'im Wege des SEPA-Lastschriftverfahrens am <strong>{{abbuchung}}</strong> von Ihrem Konto eingezogen. '
                . 'Sie müssen nichts weiter veranlassen.</p>'
                . '<p>Mandatsreferenz: {{mandatsreferenz}}<br>Gläubiger-ID: {{creditor_id}}</p>'
                . '<p>Diese E-Mail gilt als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.</p>'
            );
            Mailer::send($r['email'], $mail['subject'], $mail['body']);
            DB::execute('UPDATE invoices SET prenotified_at = now() WHERE id = ?', [$r['id']]);
            $sent++;
        } catch (Throwable $e) {
            error_log('SEPA-Vorabinfo fehlgeschlagen für Rechnung ' . $r['rechnungsnummer'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * SEPA-Sammellastschrift (pain.008) für einen freigegebenen Abrechnungslauf herunterladen.
 * Enthält nur einzuziehende Rechnungen (saldo > 0) mit gültigem Mandat. Format (.08/.02) und
 * Gläubiger-ID stammen aus den EEG-Einstellungen.
 */
$router->get('/portal/billing/:id/sepa-xml', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // SEPA-Sammellastschrift enthält IBAN/Name/Mandatsreferenz ALLER Mitglieder eines
    // Abrechnungslaufs auf einmal -- mindestens so sensibel wie eine einzelne Rechnung, siehe
    // denyDemoFileDownload().
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { http_response_code(404); echo 'Abrechnungslauf nicht gefunden'; return; }
    if ($run['status'] !== 'done') {
        header('Location: /portal/billing?error=' . urlencode('SEPA-Datei erst nach der Freigabe verfügbar.')); exit;
    }
    $data = sepaCollectionData($communityId, $params['id']);
    $community = $data['community'];
    if ($data['creditor']['creditor_id'] === '' || $data['creditor']['iban'] === '') {
        header('Location: /portal/billing/invoices?quartal=' . urlencode($run['quartal'])
            . '&error=' . urlencode('Bitte zuerst Gläubiger-ID und EEG-IBAN in den Einstellungen hinterlegen.')); exit;
    }
    if (empty($data['txns'])) {
        header('Location: /portal/billing/invoices?quartal=' . urlencode($run['quartal'])
            . '&error=' . urlencode('Keine einzuziehenden Rechnungen mit gültigem SEPA-Mandat in diesem Lauf.')); exit;
    }
    $version = ($community['sepa_pain_version'] ?? '08') === '02' ? '02' : '08';

    // Einzugsdatum kommt jetzt aus dem Auswahl-Modal (siehe billing.php) statt automatisch
    // berechnet -- Patrick, 18.10.2026: "nach dem Klick auf den Download-Button soll ein Pop-up
    // erscheinen, in dem ich das Ausführungsdatum auswähle". Rechnungsdatum (Freigabe) bleibt der
    // Anker für die Mindestfrist (SEPA_PRENOTIFICATION_DAYS).
    $invoiceDate = $run['released_at'] ? date('Y-m-d', strtotime($run['released_at'])) : date('Y-m-d');
    $today = date('Y-m-d');
    $collect = trim((string)($_GET['date'] ?? ''));
    if ($collect === '') {
        header('Location: /portal/billing?error=' . urlencode('Bitte ein Ausführungsdatum auswählen.')); exit;
    }
    // Server-seitige Prüfung, unabhängig vom Frontend-Modal (Patrick: "nicht nur im Frontend").
    // Dieselbe Vorlauffrist wie die bestehende SEPA-Vorabinfo-Mail/der Erinnerungs-Cron
    // (scripts/sepa_faelligkeit_check.php) -- pro EEG einstellbar, SEPA_PRENOTIFICATION_DAYS nur
    // als Fallback, falls (noch) kein Wert in den Einstellungen hinterlegt ist.
    $minDays = (int)($community['sepa_prenotification_days'] ?? SEPA_PRENOTIFICATION_DAYS);
    $validationError = validateSepaExecutionDate($collect, $invoiceDate, $minDays, $today);
    if ($validationError !== null) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo $validationError;
        return;
    }

    $msgId = 'SFA-' . preg_replace('/[^A-Za-z0-9]/', '', $run['quartal']) . '-' . date('YmdHis');
    try {
        $xml = sepaPain008Xml($data['creditor'], $data['txns'], $collect, $version, 'RCUR', $msgId);
    } catch (\InvalidArgumentException $e) {
        header('Location: /portal/billing/invoices?quartal=' . urlencode($run['quartal'])
            . '&error=' . urlencode($e->getMessage())); exit;
    }
    logAudit($communityId, 'billing.sepa_export', 'billing_run', $params['id'],
        'SEPA-XML (pain.008.001.' . $version . ') mit ' . count($data['txns']) . ' Lastschrift(en) erzeugt, Ausführungsdatum ' . $collect);
    // Nur beim ERSTEN Download setzen (COALESCE) -- scripts/sepa_faelligkeit_check.php erinnert
    // sonst weiter an einen Lauf, den Patrick längst bei seiner Bank eingereicht hat.
    // sepa_collection_date_used wird dagegen bei JEDEM Download aktualisiert -- Grundlage für die
    // Vorauswahl im Modal beim nächsten erneuten Download desselben Laufs.
    DB::execute(
        'UPDATE billing_runs SET sepa_xml_heruntergeladen_at = COALESCE(sepa_xml_heruntergeladen_at, now()),
                sepa_collection_date_used = ? WHERE id = ?',
        [$collect, $params['id']]
    );
    $fname = 'SEPA-' . preg_replace('/[^A-Za-z0-9]/', '', $run['quartal']) . '-pain008-' . $version . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
});

/**
 * SEPA-Test-XML mit Beispieldaten herunterladen -- damit die EEG die Datei schon beim
 * Bank-/Prüftool testen kann, BEVOR echte Abrechnungsdaten (EDA) vorliegen. Nutzt die echte
 * Gläubiger-Konfiguration der EEG (Name/IBAN/BIC/Gläubiger-ID), falls hinterlegt, sonst
 * Platzhalter; die Schuldner sind reine Beispiel-Datensätze. Es entsteht kein Datenbankeintrag
 * und kein echter Einzug.
 */
$router->get('/portal/billing/sepa-test-xml', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $c = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $version = ($c['sepa_pain_version'] ?? '08') === '02' ? '02' : '08';
    $days    = (int)($c['sepa_prenotification_days'] ?? 14);
    $creditor = [
        'name'        => trim((string)($c['name'] ?? '')) ?: 'Beispiel-EEG',
        'iban'        => trim((string)($c['iban'] ?? '')) ?: 'AT611904300234573201',
        'bic'         => trim((string)($c['bic'] ?? '')) ?: 'BKAUATWW',
        'creditor_id' => trim((string)($c['creditor_id'] ?? '')) ?: 'AT12ZZZ00000000001',
    ];
    $txns = [
        ['end_to_end_id' => 'TEST-2026-Q1-001', 'amount' => 42.50, 'mandate_ref' => 'S00001F2026A100', 'mandate_date' => '2026-01-15',
         'debtor_name' => 'Max Mustermann', 'debtor_iban' => 'AT162011100000001234', 'debtor_bic' => 'GIBAATWWXXX', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
        ['end_to_end_id' => 'TEST-2026-Q1-002', 'amount' => 18.90, 'mandate_ref' => 'S00002F2026A101', 'mandate_date' => '2026-02-01',
         'debtor_name' => 'Anna Beispiel', 'debtor_iban' => 'AT613200000000005678', 'debtor_bic' => '', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
        ['end_to_end_id' => 'TEST-2026-Q1-003', 'amount' => 7.15, 'mandate_ref' => 'S00003F2026A102', 'mandate_date' => '2026-03-10',
         'debtor_name' => 'Familie Test', 'debtor_iban' => 'AT381200000002731300', 'debtor_bic' => 'BKAUATWW', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
    ];
    $collect = date('Y-m-d', strtotime('+' . max($days, 2) . ' days'));
    $xml = sepaPain008Xml($creditor, $txns, $collect, $version, 'RCUR', 'SFA-TEST-' . date('YmdHis'));
    logAudit($communityId, 'billing.sepa_test_export', 'community', $communityId, 'SEPA-Test-XML (pain.008.001.' . $version . ') erzeugt');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="SEPA-Testdatei-pain008-' . $version . '.xml"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
});

/**
 * Zahlungsstatus einer einzelnen Rechnung setzen: positive Salden nach erfolgtem SEPA-Einzug
 * auf 'eingezogen', negative Salden nach erfolgter Überweisung durch den Obmann auf
 * 'ueberwiesen' -- erst dann gilt die Rechnung als erledigt. 'offen' setzt zurück.
 */
$router->post('/portal/billing/invoices/:id/mark-paid', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne('SELECT * FROM invoices WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    $status = $_POST['payment_status'] ?? '';
    $saldo  = (float)$inv['saldo_eur'];
    // Plausibilität: einziehen nur bei Forderung (>0), überweisen nur bei Guthaben (<0).
    $allowed = ($status === 'eingezogen' && $saldo > 0)
            || ($status === 'ueberwiesen' && $saldo < 0)
            || ($status === 'fehlgeschlagen')
            || ($status === 'offen');
    if (!$allowed) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Ungültiger Zahlungsstatus für diese Rechnung.')); exit;
    }
    $paidAt = in_array($status, ['eingezogen', 'ueberwiesen'], true) ? 'now()' : 'NULL';
    // gutschrift_ausgezahlt_at mitziehen (siehe POST .../gutschriften/:invoiceId/erledigt, die
    // umgekehrte Route): "ueberwiesen" markiert eine Gutschrift zusätzlich als ausgezahlt --
    // ohne das blieb sie weiter in /portal/billing/gutschriften und der Login-Erinnerung offen
    // stehen, obwohl sie hier schon als bezahlt erfasst wurde. Ein Zurücksetzen auf
    // "offen"/"fehlgeschlagen" macht die Auszahlung konsequent wieder rückgängig.
    $gutschriftAt = $status === 'ueberwiesen' ? 'COALESCE(gutschrift_ausgezahlt_at, now())' : 'NULL';
    DB::execute(
        "UPDATE invoices SET payment_status = ?, paid_at = $paidAt, gutschrift_ausgezahlt_at = $gutschriftAt
         WHERE id = ? AND community_id = ?",
        [$status, $params['id'], $communityId]
    );
    logAudit($communityId, 'billing.payment_status', 'invoice', $params['id'],
        'Zahlungsstatus von Rechnung ' . $inv['rechnungsnummer'] . ' auf "' . $status . '" gesetzt');
    header('Location: /portal/billing/invoices?success=1');
    exit;
});

/**
 * Rücklastschrift melden: eine per SEPA eingezogene Rechnung wurde von der Bank zurückgebucht
 * (R-Transaktion). Setzt payment_status='fehlgeschlagen' und merkt sich den Zeitpunkt -- danach
 * kann gemahnt werden.
 */
$router->post('/portal/billing/invoices/:id/ruecklastschrift', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne('SELECT * FROM invoices WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    DB::execute(
        "UPDATE invoices SET payment_status = 'fehlgeschlagen', paid_at = NULL, ruecklastschrift_at = now()
         WHERE id = ? AND community_id = ?",
        [$params['id'], $communityId]
    );
    logAudit($communityId, 'billing.ruecklastschrift', 'invoice', $params['id'],
        'Rücklastschrift zu Rechnung ' . $inv['rechnungsnummer'] . ' erfasst (Zahlung offen)');
    header('Location: /portal/billing/invoices?success=1');
    exit;
});

/**
 * Nächste Mahnstufe auslösen: Stufe hochzählen (max. 3), konfigurierte Mahngebühr aufschlagen und
 * die Zahlungserinnerung/Mahnung per E-Mail verschicken. Nur für freigegebene, noch offene bzw.
 * fehlgeschlagene Rechnungen mit Forderung (Saldo > 0).
 */
$router->post('/portal/billing/invoices/:id/mahnung', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne(
        'SELECT i.*, br.status AS run_status, c.name AS eeg_name, c.iban AS eeg_iban, c.mahngebuehr_eur,
                c.sepa_prenotification_days,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate,
                m.first_name, m.last_name, m.company_name, m.salutation, m.titel_vor, m.email_anrede_mode, m.email
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN communities c ON c.id = i.community_id
           JOIN members m ON m.id = i.member_id
           LEFT JOIN LATERAL (
               SELECT tax_model, tax_rate_percent FROM tax_config
               WHERE community_id = i.community_id AND valid_from <= br.period_from
               ORDER BY valid_from DESC LIMIT 1
           ) tx ON true
          WHERE i.id = ? AND i.community_id = ?',
        [$params['id'], $communityId]
    );
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    if (($inv['run_status'] ?? '') !== 'done') {
        header('Location: /portal/billing/invoices?error=' . urlencode('Nur freigegebene Rechnungen können gemahnt werden.')); exit;
    }
    if ((float)$inv['saldo_eur'] <= 0) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Nur Rechnungen mit offener Forderung können gemahnt werden.')); exit;
    }
    if (in_array($inv['payment_status'], ['eingezogen', 'ueberwiesen'], true)) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Diese Rechnung ist bereits bezahlt.')); exit;
    }
    if ((int)$inv['mahnstufe'] >= 3) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Höchste Mahnstufe bereits erreicht – bitte weiteres Vorgehen (Inkasso o.ä.) außerhalb der Plattform klären.')); exit;
    }

    $neueStufe = (int)$inv['mahnstufe'] + 1;
    $gebuehr   = moneyRound((float)($inv['mahngebuehr_eur'] ?? 0));
    $brutto    = taxBreakdown((float)$inv['saldo_eur'], $inv['eeg_tax_model'] ?? null, $inv['eeg_tax_rate'] ?? null)['brutto'];
    $gebuehrSummeNeu = moneyRound((float)$inv['mahn_gebuehr_summe_eur'] + $gebuehr);
    $gesamt    = moneyRound($brutto + $gebuehrSummeNeu);
    $fristTage = (int)($inv['sepa_prenotification_days'] ?? 14);
    $frist     = date('d.m.Y', strtotime('+' . max(7, $fristTage) . ' days'));

    $mailError = null;
    if (!empty($inv['email'])) {
        try {
            $anrede = mailSalutation($inv);
            $mail = renderMailTemplate('mahnung', [
                'anrede'          => htmlspecialchars($anrede['anrede']),
                'nachname'        => htmlspecialchars($anrede['nachname']),
                'eeg_name'        => htmlspecialchars((string)$inv['eeg_name']),
                'rechnungsnummer' => htmlspecialchars((string)$inv['rechnungsnummer']),
                'mahnstufe_text'  => htmlspecialchars(mahnstufeText($neueStufe)),
                'betrag'          => number_format($brutto, 2, ',', '.'),
                'gesamt'          => number_format($gesamt, 2, ',', '.'),
                'gebuehr_zeile'   => $gebuehrSummeNeu > 0 ? '<br>Mahngebühren: ' . number_format($gebuehrSummeNeu, 2, ',', '.') . ' €' : '',
                'ruecklast_hinweis' => !empty($inv['ruecklastschrift_at']) ? ' (die SEPA-Lastschrift wurde von Ihrer Bank zurückgebucht)' : '',
                'frist'           => $frist,
                'iban'            => htmlspecialchars((string)($inv['eeg_iban'] ?? '')),
            ],
                '{{mahnstufe_text}}: Rechnung {{rechnungsnummer}} – {{eeg_name}}',
                '<p>{{anrede}} {{nachname}},</p>'
                . '<p>zu Ihrer Rechnung <strong>{{rechnungsnummer}}</strong> ist bei uns bislang kein vollständiger Zahlungseingang verbucht{{ruecklast_hinweis}}.</p>'
                . '<p>Offener Betrag: <strong>{{betrag}} €</strong>{{gebuehr_zeile}}<br>Bitte zu überweisen: <strong>{{gesamt}} €</strong></p>'
                . '<p>Wir bitten Sie, den Betrag bis spätestens <strong>{{frist}}</strong> auf folgendes Konto zu überweisen:<br>'
                . 'IBAN: {{iban}}<br>Verwendungszweck: {{rechnungsnummer}}</p>'
                . '<p>Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie es bitte als gegenstandslos.</p>'
            );
            Mailer::send($inv['email'], $mail['subject'], $mail['body']);
        } catch (Throwable $e) {
            $mailError = $e->getMessage();
            error_log('Mahnung-Mail fehlgeschlagen für ' . $inv['rechnungsnummer'] . ': ' . $e->getMessage());
        }
    }

    DB::execute(
        'UPDATE invoices SET mahnstufe = ?, mahn_gebuehr_summe_eur = ?, letzte_mahnung_at = now() WHERE id = ? AND community_id = ?',
        [$neueStufe, $gebuehrSummeNeu, $params['id'], $communityId]
    );
    logAudit($communityId, 'billing.mahnung', 'invoice', $params['id'],
        mahnstufeText($neueStufe) . ' (Stufe ' . $neueStufe . ') zu Rechnung ' . $inv['rechnungsnummer']
        . ' ausgelöst' . ($gebuehr > 0 ? ', Mahngebühr ' . number_format($gebuehr, 2, ',', '.') . ' €' : '')
        . ($mailError ? ' – Mailversand fehlgeschlagen' : ''));

    $msg = $mailError
        ? 'Mahnstufe gesetzt, aber E-Mail-Versand fehlgeschlagen: ' . $mailError
        : mahnstufeText($neueStufe) . ' verschickt.';
    header('Location: /portal/billing/invoices?success=' . urlencode($msg));
    exit;
});

/**
 * Einzelbearbeitung einer Rechnung vor der Freigabe: nur solange der Abrechnungslauf im
 * Status 'ready' ist (nach der Berechnung, vor der endgültigen Freigabe). Zeigt alle
 * Positionen einer Rechnung und erlaubt Bearbeiten/Löschen/Hinzufügen; der Saldo wird nach
 * jeder Änderung neu berechnet.
 */
$router->get('/portal/billing/invoices/:id/edit', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $invoice = DB::fetchOne(
        'SELECT i.*, br.status AS run_status, br.quartal, m.first_name, m.last_name, m.kundennummer, m.is_demo
         FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN members m ON m.id = i.member_id
         WHERE i.id = ? AND i.community_id = ?',
        [$params['id'], $communityId]
    );
    if (!$invoice) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    $invoice = demoMaskMember($invoice, Auth::isDemo());
    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$params['id']]);
    require ROOT . '/src/views/pages/invoice_edit.php';
});

/** Hilfsfunktion: prüft, dass die Rechnung existiert, zur Community gehört und ihr Lauf noch
 *  im Status 'ready' ist (nur dann darf bearbeitet werden). Gibt die Rechnung zurück oder null. */
function editableInvoiceOrNull(string $invoiceId, string $communityId): ?array
{
    $inv = DB::fetchOne(
        'SELECT i.id, br.status AS run_status FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.id = ? AND i.community_id = ?',
        [$invoiceId, $communityId]
    );
    return ($inv && $inv['run_status'] === 'ready') ? $inv : null;
}

$router->post('/portal/billing/invoices/:id/items/add', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    $label  = trim($_POST['label'] ?? '');
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if ($label === '' || !is_numeric($amount)) {
        header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?error=' . urlencode('Bitte Text und Betrag angeben.')); exit;
    }
    DB::execute(
        'INSERT INTO invoice_items (invoice_id, type, label, quantity, unit, amount_eur) VALUES (?, ?, ?, ?, ?, ?)',
        [$params['id'], 'manuell', $label, str_replace(',', '.', trim($_POST['quantity'] ?? '') ?: '1'),
         trim($_POST['unit'] ?? '') ?: 'Stk', (float)$amount]
    );
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.add', 'invoice', $params['id'], 'Position "' . $label . '" (' . $amount . ' EUR) hinzugefügt');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/invoices/:id/items/:itemId/update', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if (!is_numeric($amount)) {
        header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?error=' . urlencode('Ungültiger Betrag.')); exit;
    }
    // label nur bei manuellen Positionen editierbar; Beträge bei allen.
    DB::execute(
        'UPDATE invoice_items SET amount_eur = ?, label = COALESCE(?, label) WHERE id = ? AND invoice_id = ?',
        [(float)$amount, (array_key_exists('label', $_POST) ? trim($_POST['label']) : null), $params['itemId'], $params['id']]
    );
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.update', 'invoice', $params['id'], 'Position angepasst (' . $amount . ' EUR)');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/invoices/:id/items/:itemId/delete', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    DB::execute('DELETE FROM invoice_items WHERE id = ? AND invoice_id = ?', [$params['itemId'], $params['id']]);
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.delete', 'invoice', $params['id'], 'Position gelöscht');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isManager()) { http_response_code(403); echo 'Kein Zugriff.'; return; }
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT quartal, status FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { header('Location: /portal/billing?error=' . urlencode('Abrechnungslauf nicht gefunden.')); exit; }
    // Ein bereits freigegebener Lauf darf nicht mehr gelöscht werden -- sonst verschwinden
    // bereits verschickte/freigegebene Rechnungen rückwirkend samt ihrer Rechnungsnummer
    // (kaskadierendes Löschen, siehe migrate_20260715.sql), was einer unerlaubten nachträglichen
    // Stornierung ohne jede Spur gleichkäme. Ein noch nicht freigegebener Entwurf ('pending'/
    // 'ready') bleibt löschbar, z.B. um einen versehentlich angelegten Testlauf zu entfernen.
    if (in_array($run['status'], ['done', 'released'], true)) {
        header('Location: /portal/billing?error=' . urlencode('Ein bereits freigegebener Abrechnungslauf kann nicht mehr gelöscht werden.')); exit;
    }
    DB::execute('DELETE FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    logAudit($communityId, 'billing.delete', 'billing_run', $params['id'], 'Abrechnungslauf ' . ($run['quartal'] ?? '?') . ' gelöscht');
    header('Location: /portal/billing?success=1');
    exit;
});

// ─── Portal: Internes Postfach (Benachrichtigungen) ─────
$router->get('/portal/postfach', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $notifications = DB::fetchAll(
        "SELECT * FROM notifications WHERE community_id = ? ORDER BY (status = 'offen') DESC, created_at DESC",
        [$communityId]
    );
    $notifications = demoMaskNotifications($notifications, Auth::isDemo());
    require ROOT . '/src/views/pages/postfach.php';
});

$router->post('/portal/postfach/:id/erledigt', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ? WHERE id = ? AND community_id = ?",
        [Auth::userId(), $params['id'], $communityId]
    );
    header('Location: /portal/postfach?success=1');
    exit;
});

// ─── Portal: Support-Tickets (Manager/Platform-Admin) ───
$router->get('/portal/support', fn() => Services::supportController()->staffIndex($_GET)->send());
$router->get('/portal/support/:id', fn($params) => Services::supportController()->staffShow($params['id'])->send());
$router->post('/portal/support/:id/reply', fn($params) => Services::supportController()->staffReply($params['id'], $_POST)->send());

// ─── Portal: Protokolle von Mitgliederversammlungen (AssemblyController) ───
// Obmann/Manager der aktiven EEG; Mitglieder laden nur abgeschlossene, freigegebene PDFs;
// Unterzeichner ohne Login über einen Einmal-Link (Token, 14 Tage gültig).
$assemblyCtl = fn() => Services::assemblyController();
$router->get('/portal/versammlungen', fn() => $assemblyCtl()->index($_GET)->send());
$router->post('/portal/versammlungen', fn() => $assemblyCtl()->create($_POST)->send());
$router->post('/portal/versammlungen/upload', fn() => $assemblyCtl()->upload($_POST, $_FILES['pdf'] ?? [])->send());
$router->get('/portal/versammlungen/:id', fn($p) => $assemblyCtl()->show($p['id'], $_GET)->send());
$router->get('/portal/versammlungen/:id/pdf', fn($p) => $assemblyCtl()->pdf($p['id'])->send());
$router->get('/portal/versammlungen/:id/unterschreiben/:signerId', fn($p) => $assemblyCtl()->signOnSiteForm($p['id'], $p['signerId'])->send());
$router->post('/portal/versammlungen/:id/unterschreiben/:signerId', fn($p) => $assemblyCtl()->signOnSite($p['id'], $p['signerId'], $_POST)->send());
foreach ([
    ''                    => 'header',
    '/punkte'             => 'add_item',
    '/unterzeichner'      => 'add_signer',
    '/freigeben'          => 'start_signing',
    '/entwurf'            => 'back_to_draft',
    '/abschliessen'       => 'finalize',
    '/sichtbarkeit'       => 'visibility',
    '/loeschen'           => 'delete',
] as $suffix => $action) {
    $router->post('/portal/versammlungen/:id' . $suffix, fn($p) => $assemblyCtl()->act($p['id'], $action, $_POST)->send());
}
foreach ([
    '/punkte/:subId'              => 'update_item',
    '/punkte/:subId/loeschen'     => 'delete_item',
    '/punkte/:subId/verschieben'  => 'move_item',
    '/unterzeichner/:subId/loeschen' => 'delete_signer',
    '/unterzeichner/:subId/link'  => 'send_link',
] as $suffix => $action) {
    $router->post('/portal/versammlungen/:id' . $suffix, fn($p) => $assemblyCtl()->act($p['id'], $action, $_POST, $p['subId'])->send());
}
$router->get('/portal/my/versammlungen/:id/pdf', fn($p) => $assemblyCtl()->memberPdf($p['id'])->send());
$router->get('/portal/unterschrift/versammlung/:token', fn($p) => $assemblyCtl()->linkForm($p['token'])->send());
$router->get('/portal/unterschrift/versammlung/:token/vorschau', fn($p) => $assemblyCtl()->linkPreview($p['token'])->send());
$router->post('/portal/unterschrift/versammlung/:token', fn($p) => $assemblyCtl()->linkSign($p['token'], $_POST)->send());

// ─── Portal: Online-Beitrittserklärungen (Freigabe) ─────
$router->get('/portal/applications', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    // Nur wirklich neue (unbearbeitete) Anfragen -- abgeschlossene (freigegeben/abgelehnt) sind
    // schon am jeweiligen Mitglied über den Online/Offline-Badge und den Formular-Ausdruck
    // nachvollziehbar und müssen die Neuanmeldungen-Übersicht nicht mehr zumüllen.
    $applications = DB::fetchAll(
        "SELECT * FROM membership_applications WHERE community_id = ? AND status = 'pending' ORDER BY created_at DESC",
        [$communityId]
    );
    $applications = demoMaskApplications($applications, Auth::isDemo());
    require ROOT . '/src/views/pages/applications_list.php';
});

$router->get('/portal/applications/:id', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $application = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$application) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $application = demoMaskApplication($application, Auth::isDemo());
    require ROOT . '/src/views/pages/application_detail.php';
});

$router->get('/portal/applications/:id/formular', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $a = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$a) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // Zugriff: Manager/Platform-Admin der Community (bisheriges Verhalten) ODER das Mitglied
    // selbst, dessen eigene Beitrittserklärung das ist (Selbstbedienung über /portal/my/documents).
    if (!Auth::isManager()) {
        $ownMember = currentMemberFull();
        if (!$ownMember || $a['member_id'] !== $ownMember['id']) {
            http_response_code(403); echo 'Kein Zugriff'; return;
        }
    }

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);

    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1], true);
    $cb = fn(bool $checked) => $checked ? '\\cbon' : '\\cb';

    // Anrede-Checkboxen (Divers hat am Papierformular kein eigenes Kästchen,
    // wird stattdessen im Titel-Feld vermerkt)
    $salutation = $a['salutation'] ?? '';
    // Beitrittserklärung kennt nur EIN Titel-Feld (Papierformular) -- vor- und nachgestellter
    // Titel werden hier zusammengeführt, z.B. "Ing., MSc" oder nur "BA" (siehe formatMemberName()
    // für die getrennte Verwendung auf Rechnung/E-Mail).
    $titelVorA = trim((string)($a['titel_vor'] ?? ''));
    $titelNachA = trim((string)($a['titel_nach'] ?? ''));
    $titelCombined = trim($titelVorA . ($titelVorA !== '' && $titelNachA !== '' ? ', ' : '') . $titelNachA);
    $titelDisplay = trim($titelCombined . ($salutation === 'Divers' ? ' · Divers' : ''));

    $speicherStatus = $a['speicher_status'] ?? '';

    // SEPA-Block: exakt im Kasten-Layout des Papierformulars, mit echten Werten.
    // Unterschrift per \floatsig (Vorlage): schwebt mittig über der Linie statt sie nach
    // unten zu schieben -- Box bleibt dadurch kompakt, ob mit oder ohne Bild. War früher
    // links an den Linienanfang verankert (Patrick, 03.10.2026: "wäre cool, wenn es ein
    // bisschen weiter rechts wäre") -- \floatsig zentriert stattdessen über der ganzen Linie.
    $sigOffset = communitySignatureOffsetCm($communityId);
    $sigOffsetX = communitySignatureOffsetXCm($communityId);
    // Zustimmungen in der Fassung, die DIESER Antrag akzeptiert hat (consent_version) -- ein
    // alter Antrag zeigt weiterhin den damals angezeigten Text, nicht den heutigen (Patrick,
    // 07.11.2026). Kurzfassung + Hinweis auf den vollständigen Wortlaut, siehe consent_texts.php.
    loadCommunityConsentVersions($communityId);
    $consentBlock = onlineBeitrittConsentLatex($a, $community);
    $consentVersionA = $consentBlock['version'];
    $sepaAssets = [];
    if (trim($a['iban'] ?? '') !== '') {
        $sepaSigBox = '';
        if (!empty($a['sepa_signature_image'])) {
            $sepaAssets['sepa_unterschrift.png'] = $a['sepa_signature_image'];
            $sepaSigBox = '\\floatsig{' . floatsigHalfWidth(3.25, $sigOffsetX) . '}{' . signatureRaise(0.85, $sigOffset) . '}{\\includegraphics[height=0.85cm]{sepa_unterschrift.png}}';
        }
        $sepaSignedAt = fmtVienna($a['sepa_signed_at']);
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
            // Der Satz "Ich erkläre ausdrücklich ... berechtigt zu sein" kam erst mit v2 dazu --
            // ältere Online-Anträge haben ihn nicht gesehen, also auch nicht im Nachdruck.
            . '\\scriptsize ' . texEscape(sepaMandateText($community, $consentVersionA !== 'v1-online')) . "\n"
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

    $assets = ['unterschrift_beitritt.png' => $a['signature_image']] + $sepaAssets;

    streamLatexPdf('beitrittserklaerung_formular', [
        'EEG_NAME'                  => $community['name'],
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EINGEREICHT_AM'            => fmtVienna($a['created_at']),
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
        'RAW_ZUSTIMMUNGEN_LISTE'    => $consentBlock['liste'],
        'RAW_ZUSTIMMUNGEN_HINWEIS'  => $consentBlock['hinweis'],
        'RAW_UNTERSCHRIFT_BILD'     => '\\floatsig{' . floatsigHalfWidth(3.25, $sigOffsetX) . '}{' . signatureRaise(1.3, $sigOffset) . '}{\\includegraphics[height=1.3cm]{unterschrift_beitritt.png}}',
        'UNTERSCHRIEBEN_DATUM'      => fmtVienna($a['signed_at'], 'd.m.Y'),
        'UNTERSCHRIEBEN_AM'         => fmtVienna($a['signed_at']),
        'SIGNER_IP'                 => $a['signer_ip'] ?: '--',
    ], 'Beitrittserklaerung_' . $a['last_name'] . '.pdf', $assets);
});

$router->post('/portal/applications/:id/approve', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $application = DB::fetchOne(
        "SELECT * FROM membership_applications WHERE id = ? AND community_id = ? AND status = 'pending'",
        [$params['id'], $communityId]
    );
    if (!$application) { http_response_code(404); echo 'Nicht gefunden oder bereits bearbeitet'; return; }

    $result = createMemberRecord($communityId, [
        'salutation' => $application['salutation'],
        'titel_vor' => $application['titel_vor'], 'titel_nach' => $application['titel_nach'],
        'first_name' => $application['first_name'], 'last_name' => $application['last_name'],
        'email' => $application['email'], 'phone' => $application['phone'],
        'address' => $application['address'], 'zip' => $application['zip'], 'city' => $application['city'],
        'geburtsdatum' => $application['geburtsdatum'], 'stromlieferant' => $application['stromlieferant'],
        'speicher_status' => $application['speicher_status'], 'speicher_kwh' => $application['speicher_kwh'],
        'andere_eeg' => in_array($application['andere_eeg'], [true, 't', '1', 1], true), 'andere_eeg_name' => $application['andere_eeg_name'],
        'member_iban' => $application['iban'], 'member_bic' => $application['bic'],
        'kontoinhaber' => $application['kontoinhaber'], 'konto_adresse' => $application['konto_adresse'],
        'member_since' => date('Y-m-d'),
        'email_anrede_mode' => $_POST['email_anrede_mode'] ?? 'auto',
        'consent_version' => $application['consent_version'] ?? null,
    ]);
    // Rechnungsintervall aus dem Online-Beitritt (§ 69 ElWG) übernehmen, falls gewählt.
    if (!empty($application['invoice_interval'])) {
        DB::execute('UPDATE members SET invoice_interval = ? WHERE id = ?', [$application['invoice_interval'], $result['member_id']]);
    }

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

    if ($result['invite_sent']) {
        header('Location: /portal/members/' . $result['member_id'] . '?success=invite_sent');
        exit;
    }

    // Fallback: Einladungs-Mail nicht verschickt (Mailversand nicht konfiguriert/fehlgeschlagen,
    // oder es gab schon einen Login für diese E-Mail) -- Temp-Passwort direkt auf der
    // Mitgliedsseite anzeigen, damit der Manager es notfalls selbst weitergeben kann.
    $memberIdForRedirect = $result['member_id'];
    if ($result['temp_password']) {
        $successTempPw = $result['temp_password'];
        $successEmail = $application['email'];
        $successInviteError = $result['invite_error'];
        $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberIdForRedirect, $communityId]);
        $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$memberIdForRedirect]);
        $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$memberIdForRedirect]);
        $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$memberIdForRedirect, $communityId]);
        $latestFirmwareVersion = latestFirmwareVersion();
        require ROOT . '/src/views/pages/member_detail.php';
        exit;
    }

    header('Location: /portal/members/' . $memberIdForRedirect . '?success=1');
    exit;
});

$router->post('/portal/applications/:id/reject', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
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

/**
 * Korrigiert die Zählpunktnummer(n) einer bereits eingereichten Beitrittserklärung -- nötig,
 * weil z.B. eine aus einem Netzbetreiber-Portal mit Leerzeichen kopierte Nummer bei einem
 * älteren, bereits eingereichten Antrag (vor dem Leerzeichen-Fix, siehe validateZaehlpunkt())
 * schon abgeschnitten in membership_applications gespeichert wurde (Patrick, 03.09.2026: "die
 * letzten drei Stellen sind frei"). Das Beitrittserklärung-PDF (/portal/applications/:id/formular)
 * wird bei JEDEM Abruf frisch aus dieser Tabelle gerendert (kein Caching) -- diese Korrektur
 * allein reicht deshalb bereits aus, damit der nächste PDF-Ausdruck die richtigen Daten zeigt,
 * ohne einen gesonderten "neu generieren"-Schritt. Erlaubt für JEDEN Antragsstatus (auch schon
 * freigegeben/abgelehnt), nicht nur 'pending' -- die Korrektur betrifft nur das Dokument selbst.
 */
$router->post('/portal/applications/:id/zaehlpunkt', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$application) { http_response_code(404); echo 'Nicht gefunden'; return; }

    $bezug = normalizeZaehlpunkt($_POST['bezug_zaehlpunkt'] ?? '');
    if ($bezug !== '' && !validateZaehlpunkt($bezug)) {
        header('Location: /portal/applications/' . $params['id'] . '?error=' . urlencode('Zählpunktnummer (Bezug) ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern) benötigt.'));
        exit;
    }
    $einspeisung = normalizeZaehlpunkt($_POST['einspeisung_zaehlpunkt'] ?? '');
    if ($einspeisung !== '' && !validateZaehlpunkt($einspeisung)) {
        header('Location: /portal/applications/' . $params['id'] . '?error=' . urlencode('Zählpunktnummer (Einspeisung) ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern) benötigt.'));
        exit;
    }

    DB::execute(
        'UPDATE membership_applications SET bezug_zaehlpunkt = ?, einspeisung_zaehlpunkt = ? WHERE id = ? AND community_id = ?',
        [$bezug ?: null, $einspeisung ?: null, $params['id'], $communityId]
    );
    logAudit($communityId, 'application.zaehlpunkt_correct', 'membership_application', $params['id'],
        'Zählpunktnummer(n) einer Beitrittserklärung nachträglich korrigiert.');

    header('Location: /portal/applications/' . $params['id'] . '?success=' . urlencode('Zählpunktnummer(n) gespeichert -- das Beitrittserklärung-PDF zeigt beim nächsten Ausdruck die korrigierten Daten.'));
    exit;
});

// ─── Portal: EDA-Import ─────────────────────────────────
// Import-Historie dieser Community: für die Übersicht auf /portal/eda/upload (welche Dateien
// wurden für welchen Zeitraum importiert) UND für den Lösch-Button dort (siehe
// /portal/eda/imports/:id/delete weiter unten).
function edaImportsForCommunity(string $communityId): array
{
    DB::setCommunity($communityId);
    // quality_l1/l2/l3: Datenqualität direkt aus den importierten Messwerten ausgelesen (Patrick,
    // 07.08.2026: "Bitte die EDA-Datenqualität aus der Datei auslesen und auch anzeigen") --
    // period_from/period_to sind hier beides TIMESTAMPTZ-Spalten (eda_imports), ein direkter
    // >=/<=-Vergleich gegen em.time (ebenfalls TIMESTAMPTZ) ist deshalb zeitzonenunabhängig
    // korrekt, ganz ohne ::date-Umwandlung (siehe die Zeitzonen-Fallgrube bei
    // Billing::missingMonths(), wo genau das nötig war -- dort waren die Vergleichswerte
    // hingegen reine DATE-Strings aus billing_runs).
    return DB::fetchAll(
        "SELECT ei.*, u.first_name, u.last_name,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L1') AS quality_l1,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L2') AS quality_l2,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L3') AS quality_l3
         FROM eda_imports ei
         LEFT JOIN users u ON u.id = ei.imported_by
         WHERE ei.community_id = ?
         ORDER BY ei.imported_at DESC",
        [$communityId]
    );
}

/**
 * Detaillierter Datenqualitäts-Bericht für einen EDA-Zeitraum (ein Monat ODER ein ganzer
 * Abrechnungszeitraum) -- zählt L1/L2/L3 und listet JEDEN nicht belastbaren (L2/L3) Datensatz
 * einzeln mit Mitglied, Zählpunkt und Monat auf. Patrick, 01.10.2026: "kannst du mir wirklich
 * bitte eine Überprüfung machen und sagen, wie viele Daten fehlerhaft sind, also L3? Wie viele
 * sind L2 und wie viele sind L1? Wenn Daten L2 oder L3 sind, mir sagen, von welchem Kunden und
 * von welchem Zeitraum." -- genau diese Unterscheidung (Gesamtlauf -> Freigabe blockiert oder
 * nicht, siehe Billing::datenqualitaetProblem()) macht hier als Detailliste sichtbar, WER/WELCHER
 * ZEITRAUM konkret betroffen ist, statt nur die Gesamtzahl wie bisher in edaImportsForCommunity().
 */
function edaQualityReport(string $communityId, string $periodFrom, string $periodTo): array
{
    DB::setCommunity($communityId);

    // Bevorzugt die monatsgenaue Tabelle (seit 01.10.2026, siehe migrate_20261001.sql) -- liefert
    // für JEDEN Zählpunkt eine eigene Zeile je Kalendermonat, unabhängig davon, ob die zugrunde
    // liegende EDA-Datei einen Einzelmonat oder einen längeren, selbst gewählten Zeitraum (z.B.
    // ein ganzes Quartal in einer Datei) abdeckte. Patrick, 01.10.2026: "Sieht man [...] nicht
    // auch die einzelnen Monate [...] wo man heraussehen kann, welcher Monat [...] schuld ist?"
    // -- ja, die EDA-Detailübersicht hat das schon immer geliefert, nur bisher nicht importiert.
    $monthlyRows = DB::fetchAll(
        "SELECT q.month, q.quality, mp.zaehlpunkt_nr, mp.type,
                m.first_name, m.last_name, m.company_name
         FROM eda_measurement_quality_monthly q
         JOIN metering_points mp ON mp.id = q.metering_point_id
         LEFT JOIN members m ON m.id = mp.member_id
         WHERE q.community_id = ? AND q.month >= date_trunc('month', ?::date)
           AND q.month <= date_trunc('month', ?::date)
         ORDER BY CASE q.quality WHEN 'L3' THEN 1 WHEN 'L2' THEN 2 ELSE 3 END,
                  m.last_name, m.first_name, q.month",
        [$communityId, $periodFrom, $periodTo]
    );

    if (!empty($monthlyRows)) {
        $counts = ['L1' => 0, 'L2' => 0, 'L3' => 0];
        $details = [];
        foreach ($monthlyRows as $r) {
            $q = $r['quality'] ?? 'L1';
            if (isset($counts[$q])) $counts[$q]++;
            if ($q !== 'L1') {
                $name = trim((string)($r['company_name'] ?? '')) ?: trim($r['first_name'] . ' ' . $r['last_name']);
                $details[] = [
                    'name'          => $name !== '' ? $name : '(nicht zugeordnet)',
                    'zaehlpunkt_nr' => $r['zaehlpunkt_nr'],
                    'typ'           => $r['type'] === 'producer' ? 'Einspeisung' : ($r['type'] === 'prosumer' ? 'Prosumer' : 'Bezug'),
                    'monat'         => monatsLabel($r['month']),
                    'quality'       => $q,
                ];
            }
        }
        return ['counts' => $counts, 'details' => $details, 'is_multi_month' => false];
    }

    // Fallback für Importe von VOR dem 01.10.2026 (es existiert noch keine monatsgenaue Zeile,
    // weil der Import lief, bevor der Parser diese Tabelle befüllt hat) -- dieselbe, nur grobe
    // Logik wie bisher: EINE Zeile je Zählpunkt für den KOMPLETTEN Zeitraum. Bei einem
    // Mehrmonats-/Quartalsexport ist die Datenqualität dieser einen Zeile oft gemischt
    // ("L1,L2,L3" -- siehe _worst_quality() im Parser) und wird zurecht auf L3 abgewertet, sobald
    // irgendein Teil des Zeitraums noch nicht belastbar ist -- monatsLabel($r['time']) würde das
    // fälschlich als "nur dieser eine Monat betroffen" lesen lassen, deshalb bei einem Zeitraum
    // von mehr als ~35 Tagen die ECHTE Zeitspanne statt eines einzelnen Monatsnamens anzeigen.
    $rows = DB::fetchAll(
        "SELECT em.time, em.quality, mp.zaehlpunkt_nr, mp.type,
                m.first_name, m.last_name, m.company_name
         FROM eda_measurements em
         JOIN metering_points mp ON mp.id = em.metering_point_id
         LEFT JOIN members m ON m.id = mp.member_id
         WHERE em.community_id = ? AND em.time >= ? AND em.time <= ?
         ORDER BY CASE em.quality WHEN 'L3' THEN 1 WHEN 'L2' THEN 2 ELSE 3 END,
                  m.last_name, m.first_name, em.time",
        [$communityId, $periodFrom, $periodTo]
    );

    $spanDays = (strtotime($periodTo) - strtotime($periodFrom)) / 86400;
    $isMultiMonth = $spanDays > 35;
    $spanLabel = monatsLabel($periodFrom) === monatsLabel($periodTo)
        ? monatsLabel($periodFrom)
        : monatsLabel($periodFrom) . ' – ' . monatsLabel($periodTo);

    $counts = ['L1' => 0, 'L2' => 0, 'L3' => 0];
    $details = [];
    foreach ($rows as $r) {
        $q = $r['quality'] ?? 'L1';
        if (isset($counts[$q])) $counts[$q]++;
        if ($q !== 'L1') {
            $name = trim((string)($r['company_name'] ?? '')) ?: trim($r['first_name'] . ' ' . $r['last_name']);
            $details[] = [
                'name'          => $name !== '' ? $name : '(nicht zugeordnet)',
                'zaehlpunkt_nr' => $r['zaehlpunkt_nr'],
                'typ'           => $r['type'] === 'producer' ? 'Einspeisung' : ($r['type'] === 'prosumer' ? 'Prosumer' : 'Bezug'),
                'monat'         => $isMultiMonth ? $spanLabel : monatsLabel($r['time']),
                'quality'       => $q,
            ];
        }
    }
    return ['counts' => $counts, 'details' => $details, 'is_multi_month' => $isMultiMonth];
}

/** Import-Historie der Viertelstundenwerte (zweiter Export-Typ, siehe
 *  eda-parser/parser_interval.py) -- eigene, schlankere Tabelle als edaImportsForCommunity()
 *  oben, da hier bewusst überlappende Zeiträume normal sind (siehe Kommentar in
 *  database/migrate_20260904.sql), keine Datenqualitäts-Aggregation nötig (nicht
 *  abrechnungsrelevant). */
function edaIntervalImportsForCommunity(string $communityId): array
{
    DB::setCommunity($communityId);
    return DB::fetchAll(
        "SELECT ei.*, u.first_name, u.last_name
         FROM eda_interval_imports ei
         LEFT JOIN users u ON u.id = ei.imported_by
         WHERE ei.community_id = ?
         ORDER BY ei.imported_at DESC",
        [$communityId]
    );
}

/**
 * Lücken-Anzeige für die Viertelstundenwerte: letzter vorhandener Zeitstempel community-weit
 * (über alle Zählpunkte) plus wie viele Tage bis heute fehlen -- Patrick, 03.09.2026: "ab
 * welchem Datum es noch keine Werte gibt, ab welchem Datum gehe ich die Daten exportieren
 * muss", "wenn ich jetzt den juli importier und dann von 01.08. bis 21.08. ich sehe, dass ab
 * 22.08. die Daten fehlen". Bewusst eine einfache MAX(time)-Abfrage statt eine eigene
 * Fortschritts-Tabelle zu pflegen -- immer exakt aktuell, unabhängig vom Import-Protokoll.
 */
function edaIntervalGap(string $communityId): array
{
    DB::setCommunity($communityId);
    $row = DB::fetchOne('SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ?', [$communityId]);
    $letzter = $row['letzter'] ?? null;
    $fehlendeTage = $letzter ? (int)floor((time() - strtotime($letzter)) / 86400) : null;
    return ['letzter' => $letzter, 'fehlende_tage' => $fehlendeTage];
}

/**
 * Ergebnis eines EDA-Importjobs (Container eda-worker) für die Upload-Seite aufbereiten: fertig ->
 * dieselben Variablen wie früher nach dem direkten Parser-Lauf ($result/$error bzw.
 * $intervalResult/$intervalError), sonst der Job selbst für die Statusanzeige.
 *
 * @return array{result: ?array, error: ?string, pending: ?array}
 */
function edaJobView(?string $jobId, string $communityId): array
{
    $view = ['result' => null, 'error' => null, 'pending' => null];
    if ($jobId === null || $jobId === '') return $view;
    DB::setCommunity($communityId);
    $job = \App\Support\Services::edaImportJobs()->find($jobId, $communityId);
    if ($job === null) {
        $view['error'] = 'Import nicht gefunden.';
    } elseif (!\App\Domain\EdaImport\EdaImportJobs::isFinished($job)) {
        $view['pending'] = $job;
    } elseif ($job['status'] === 'done' && is_array($job['result'])) {
        $view['result'] = $job['result'];
    } else {
        $diag = EdaParserRunner::diagnostics(\App\Domain\EdaImport\EdaImportJobs::runnerResult($job));
        $view['error'] = 'Parser-Fehler: ' . htmlspecialchars(substr($diag, 0, 4000));
    }
    return $view;
}

$router->get('/portal/eda/upload', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    // Nach dem Hochladen leitet die Seite hierher um (?job=… bzw. ?interval_job=…) und zeigt den
    // Fortschritt des Jobs; eda-job-status.js lädt nach, bis er fertig ist.
    $monthly = edaJobView($_GET['job'] ?? null, $communityId);
    $interval = edaJobView($_GET['interval_job'] ?? null, $communityId);
    $result = $monthly['result'];
    $error = $monthly['error'];
    $intervalResult = $interval['result'];
    $intervalError = $interval['error'];
    $pendingJob = $monthly['pending'] ?? $interval['pending'];
    // Sofortige Datenqualitäts-Übersicht direkt nach dem Import (Patrick, 01.10.2026).
    if (!empty($result['period_from']) && !empty($result['period_to'])) {
        $qualityReport = edaQualityReport($communityId, $result['period_from'], $result['period_to']);
    }
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);
    require ROOT . '/src/views/pages/eda_upload.php';
});

/** Status eines Importjobs für die automatische Aktualisierung der Upload-Seite. */
$router->get('/portal/eda/jobs/:id', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $job = \App\Support\Services::edaImportJobs()->find($params['id'], $communityId);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($job === null) { http_response_code(404); echo json_encode(['error' => 'Import nicht gefunden.']); return; }
    echo json_encode(['status' => $job['status'], 'finished' => \App\Domain\EdaImport\EdaImportJobs::isFinished($job)]);
});

$router->post('/portal/eda/upload', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);

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

    // Der Import selbst läuft im Container eda-worker; die Seite zeigt danach den Fortschritt.
    // Audit-Einträge schreibt der Worker, sobald der Import fertig ist.
    DB::setCommunity($communityId);
    $jobId = \App\Support\Services::edaImportJobs()->queue($communityId, 'monthly', 'upload', $savePath, $origName, Auth::userId());
    uploadRedirect('/portal/eda/upload?job=' . urlencode($jobId)); // ohne 3xx, siehe uploadRedirect()
});

/**
 * Datenqualitäts-Detailbericht für einen einzelnen bereits hochgeladenen EDA-Import (aus der
 * "Bisherige Importe"-Tabelle heraus aufrufbar, nicht nur direkt nach einem frischen Upload) --
 * damit Patrick auch ältere Monate desselben Quartals nachträglich prüfen kann, ohne sie erneut
 * hochladen zu müssen.
 */
$router->get('/portal/eda/imports/:id/quality', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // Zeigt Mitgliedernamen + Zählpunkte -- gleiche Sensibilität wie die übrigen
    // PII-Detailseiten, deshalb für den Demo-Zugang komplett gesperrt.
    denyDemoPage('Dieser Demo-Zugang dient nur zur Ansicht. Aus Datenschutzgründen können hier keine echten Mitgliederdaten angezeigt werden.');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $imp = DB::fetchOne('SELECT * FROM eda_imports WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$imp) { header('Location: /portal/eda/upload?error=' . urlencode('Import nicht gefunden.')); exit; }
    $qualityReport = edaQualityReport($communityId, $imp['period_from'], $imp['period_to']);
    require ROOT . '/src/views/pages/eda_import_quality.php';
});

/** Wie POST /portal/eda/upload, aber für den zweiten Export-Typ (Viertelstundenwerte,
 *  "Energiedaten"-Sheet, siehe eda-parser/parser_interval.py) -- eigene Route, eigenes
 *  Datei-Feld (xlsx_interval), damit beide Upload-Formulare unabhängig auf derselben Seite
 *  stehen können. */
$router->post('/portal/eda/upload-interval', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);

    if (!isset($_FILES['xlsx_interval']) || $_FILES['xlsx_interval']['error'] !== UPLOAD_ERR_OK) {
        $intervalError = 'Upload fehlgeschlagen (Fehlercode: ' . ($_FILES['xlsx_interval']['error'] ?? '?') . ')';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }
    $origName = basename($_FILES['xlsx_interval']['name']);
    if (!str_ends_with(strtolower($origName), '.xlsx')) {
        $intervalError = 'Nur XLSX-Dateien erlaubt.';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $savePath = '/var/www/html/storage/uploads/' . uniqid() . '_' . $origName;
    move_uploaded_file($_FILES['xlsx_interval']['tmp_name'], $savePath);

    DB::setCommunity($communityId);
    $jobId = \App\Support\Services::edaImportJobs()->queue($communityId, 'interval', 'upload', $savePath, $origName, Auth::userId());
    uploadRedirect('/portal/eda/upload?interval_job=' . urlencode($jobId)); // ohne 3xx, siehe uploadRedirect()
});

/**
 * Löscht einen EDA-Import wieder -- den Log-Eintrag UND die dabei importierten Messwerte
 * (eda_measurements), damit dieselbe Datei anschließend erneut hochgeladen werden kann (der
 * Parser verweigert sonst mit "Duplikat", siehe import_to_db() in eda-parser/parser.py).
 * eda_measurements hat KEINEN Verweis auf den einzelnen Import zurück (nur time/community_id/
 * metering_point_id) -- gelöscht wird deshalb alles im exakten Zeitraum dieses Imports für diese
 * Community, genau wie die Duplikat-Prüfung selbst prüft. Träfe für exakt denselben Zeitraum
 * zufällig noch ein ZWEITER Import vor (in der Praxis nicht vorgesehen -- EDA liefert eine Datei
 * pro Monat), würden dessen Messwerte mitgelöscht; die Metering-Points selbst (inkl. automatisch
 * angelegter, siehe neu_angelegt) bleiben bewusst unangetastet, nur die Energiedaten verschwinden.
 * Rührt NICHT an bereits berechneten Abrechnungslauf-Entwürfen -- deren invoice_items sind
 * statische Kopien, die durch das Löschen der Rohdaten nicht rückwirkend aktualisiert werden.
 */
$router->post('/portal/eda/imports/:id/delete', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);

    $imp = DB::fetchOne('SELECT * FROM eda_imports WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$imp) { http_response_code(404); return; }

    $deletedMeasurements = DB::execute(
        "DELETE FROM eda_measurements WHERE community_id = ? AND time >= ? AND time < ?::date + INTERVAL '1 day'",
        [$communityId, $imp['period_from'], $imp['period_to']]
    );
    DB::execute('DELETE FROM eda_imports WHERE id = ?', [$imp['id']]);

    logAudit($communityId, 'eda.import.delete', 'eda_import', $imp['id'],
        'EDA-Import "' . $imp['filename'] . '" (' . date('d.m.Y', strtotime($imp['period_from'])) . ' – '
        . date('d.m.Y', strtotime($imp['period_to'])) . ') gelöscht, ' . $deletedMeasurements . ' Messwert-Datensätze entfernt.');

    header('Location: /portal/eda/upload?deleted=1');
    exit;
});

/**
 * Löscht einen Viertelstundenwerte-Import-Protokolleintrag UND die dabei importierten Messwerte
 * im exakten Zeitraum dieses Imports (period_from/period_to) für die Community -- analog zur
 * Monatsimport-Löschung oben. Ursprünglich (bis 31.08.2026) löschte diese Route bewusst NUR den
 * Protokolleintrag, mit der Begründung, dass sich Zeiträume bei diesem Import-Typ normal
 * überlappen und "welche Zeilen gehören zu genau diesem Log-Eintrag" bei Überlappungen nicht
 * mehr eindeutig sei. Patrick, 31.08.2026, per Screenshot: nach einem kompletten Löschen aller
 * Protokolleinträge zeigte die "Daten vorhanden bis ..."-Anzeige weiterhin den alten Stand --
 * die Messwerte blieben ja unangetastet liegen. Auf ausdrücklichen Wunsch jetzt wie beim
 * Monatsimport: exakter Zeitraum wird mitgelöscht. Bei sich überlappenden Importen kann das
 * theoretisch auch Zeilen eines ANDEREN, noch bestehenden Imports mitlöschen, falls deren
 * Zeiträume sich decken -- in der Praxis unproblematisch, weil Patrick ohnehin regelmäßig neu
 * hochlädt und ein doppelt entfernter Bereich beim nächsten Upload einfach wieder befüllt wird.
 */
$router->post('/portal/eda/interval-imports/:id/delete', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);

    $imp = DB::fetchOne('SELECT * FROM eda_interval_imports WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$imp) { http_response_code(404); return; }

    $deletedRows = DB::execute(
        'DELETE FROM eda_interval_data WHERE community_id = ? AND time >= ? AND time <= ?',
        [$communityId, $imp['period_from'], $imp['period_to']]
    );
    DB::execute('DELETE FROM eda_interval_imports WHERE id = ?', [$imp['id']]);
    logAudit($communityId, 'eda.interval_import.delete', 'eda_interval_import', $imp['id'],
        'Viertelstundenwerte-Import "' . $imp['filename'] . '" (' . date('d.m.Y', strtotime($imp['period_from'])) . ' – '
        . date('d.m.Y', strtotime($imp['period_to'])) . ') gelöscht, ' . $deletedRows . ' Messwert-Datensätze entfernt.');

    header('Location: /portal/eda/upload?deleted=1');
    exit;
});

/**
 * Zählpunkte, die per automatischem EDA-Import-Abgleich angelegt wurden (siehe
 * eda-parser/parser.py, docs/ESP_IDEEN.md Punkt 3), aber noch keinem Mitglied zugeordnet sind
 * -- der Obmann weist sie hier einem bestehenden Mitglied zu, korrigiert bei Bedarf den Typ und
 * aktiviert den Zählpunkt (erst dann nimmt er an einer Abrechnung teil).
 */
$router->get('/portal/metering-points/unassigned', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $unassigned = DB::fetchAll(
        'SELECT * FROM metering_points WHERE community_id = ? AND member_id IS NULL ORDER BY registered_at DESC',
        [$communityId]
    );
    $members = DB::fetchAll(
        "SELECT id, first_name, last_name, kundennummer FROM members WHERE community_id = ? AND status = 'active' ORDER BY last_name, first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/metering_points_unassigned.php';
});

$router->post('/portal/metering-points/:id/assign', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);

    $mp = DB::fetchOne('SELECT * FROM metering_points WHERE id = ? AND community_id = ? AND member_id IS NULL', [$params['id'], $communityId]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden oder bereits zugeordnet.'; return; }

    $memberId = $_POST['member_id'] ?? '';
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    if (!$member) {
        header('Location: /portal/metering-points/unassigned?error=' . urlencode('Bitte ein gültiges Mitglied auswählen.'));
        exit;
    }
    $type = in_array($_POST['type'] ?? '', ['consumer', 'producer', 'prosumer'], true) ? $_POST['type'] : $mp['type'];

    DB::execute(
        'UPDATE metering_points SET member_id = ?, type = ?, active = true WHERE id = ? AND community_id = ?',
        [$memberId, $type, $params['id'], $communityId]
    );
    logAudit($communityId, 'metering_point.assign', 'metering_point', $params['id'],
        'Zählpunkt ' . $mp['zaehlpunkt_nr'] . ' (aus EDA-Import) einem Mitglied zugeordnet und aktiviert (Typ: ' . $type . ').');
    header('Location: /portal/metering-points/unassigned?success=1');
    exit;
});

// ─── Portal: Einstellungen ──────────────────────────────
// ─── Rechtsdokumente der EEG bearbeiten (/portal/dokumente) ─────────────────────
// Patrick, 08.11.2026: Dokumente, die pro EEG verschieden sind, pflegt der Obmann selbst
// ("andere möchten es lieber in ihrer Hand haben und selbst ändern können"). Jede Speicherung
// ist eine neue Version (legal_documents/consent_versions), ältere bleiben im Verlauf und
// lassen sich wiederherstellen. Der Plattform-Admin pflegt zusätzlich die plattformweiten
// Dokumente (allgemeiner Datenschutz-Teil, Barrierefreiheit).

/** Bearbeitbare Dokumente je Bereich: [Reiter-ID => [doc_key, Plattform?]] */
function editableLegalDocs(bool $isPlatformAdmin, bool $hasCommunity, bool $supplierDuties = false): array
{
    $docs = [];
    if ($hasCommunity) {
        foreach (['agb', 'statuten', 'datenschutz', 'widerruf', 'impressum'] as $k) {
            $docs[$k] = ['doc_key' => $k, 'platform' => false];
        }
        // § 69 ElWG: nur mit aktivem Schalter. Reiter-ID "lieferinfo", weil "infoblatt" schon das PDF-Infoblatt ist.
        if ($supplierDuties) {
            $docs['alb'] = ['doc_key' => 'alb', 'platform' => false];
            $docs['lieferinfo'] = ['doc_key' => 'infoblatt', 'platform' => false];
        }
    }
    if ($isPlatformAdmin) {
        $docs['plattform-datenschutz'] = ['doc_key' => 'datenschutz', 'platform' => true];
        $docs['plattform-impressum'] = ['doc_key' => 'impressum', 'platform' => true];
        $docs['plattform-kontakt'] = ['doc_key' => 'kontakt', 'platform' => true];
        $docs['plattform-barrierefreiheit'] = ['doc_key' => 'barrierefreiheit', 'platform' => true];
    }
    return $docs;
}

/** Prüft Zugriff und liefert [$community|null, $isPlatform] für eine Speicher-Aktion -- bricht sonst ab. */
function legalDocEditContext(string $tabId): array
{
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $community = communityById($communityId);
    $docs = editableLegalDocs(Auth::isPlatformAdmin(), $community !== null, $community !== null && communitySupplierDuties($community['id']));
    if (!isset($docs[$tabId])) { http_response_code(403); echo 'Kein Zugriff'; exit; }
    return [$docs[$tabId]['platform'] ? null : $community, $docs[$tabId]['doc_key']];
}

$router->get('/portal/dokumente', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $community = communityById($communityId);
    $isPlatformAdmin = Auth::isPlatformAdmin();
    $editDocs = [];
    foreach (editableLegalDocs($isPlatformAdmin, $community !== null, $community !== null && communitySupplierDuties($community['id'])) as $tabId => $def) {
        $owner = $def['platform'] ? null : $community;
        $current = legalDocument($owner, $def['doc_key']);
        $history = DB::fetchAll(
            'SELECT d.id, d.created_at, u.first_name, u.last_name FROM legal_documents d
             LEFT JOIN users u ON u.id = d.created_by
             WHERE d.doc_key = ? AND ' . ($owner ? 'd.community_id = ?' : 'd.community_id IS NULL') . '
             ORDER BY d.created_at DESC LIMIT 15',
            $owner ? [$def['doc_key'], $owner['id']] : [$def['doc_key']]
        );
        $editDocs[$tabId] = $def + [
            'title'       => legalDocumentDefs()[$def['doc_key']]['title'],
            'current'     => $current,
            'history'     => $history,
            'has_default' => legalDefaultFile($owner, $def['doc_key']) !== null,
            'public_url'  => $owner ? '/' . strtolower($owner['marktpartner_id']) . '/' . $def['doc_key'] : '/' . $def['doc_key'],
        ];
    }
    $consentCurrent = null;
    $consentHistory = [];
    $infoblatt = null;
    if ($community) {
        $consentCurrent = consentCurrentVersionFor($community);
        $consentHistory = DB::fetchAll(
            'SELECT c.version_key, c.gueltig_ab, c.created_at, u.first_name, u.last_name FROM consent_versions c
             LEFT JOIN users u ON u.id = c.created_by WHERE c.community_id = ? ORDER BY c.created_at DESC',
            [$community['id']]
        );
        $own = communityDocDir($community['id']) . '/infoblatt.pdf';
        $infoblatt = is_file($own) ? ['own' => true, 'size' => filesize($own), 'mtime' => filemtime($own)] : ['own' => false];
    }
    require ROOT . '/src/views/pages/portal_dokumente.php';
});

/** Zustimmungstexte der EEG als neue Fassung speichern (gilt ab sofort für neue Beitritte).
 *  Muss VOR /portal/dokumente/:tab/save stehen, sonst fängt jene Route "zustimmungen" ab. */
$router->post('/portal/dokumente/zustimmungen/save', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $community = communityById($communityId);
    if (!$community) { http_response_code(403); echo 'Keine Energiegemeinschaft aktiv'; return; }
    $texts = [];
    foreach (array_keys(consentFields()) as $field) {
        foreach (['kurz', 'lang'] as $variant) {
            $t = trim(str_replace("\r\n", "\n", (string)($_POST[$field . '_' . $variant] ?? '')));
            if ($t === '' || mb_strlen($t) > 5000) {
                header('Location: /portal/dokumente?error=' . urlencode('Bitte alle zwölf Felder ausfüllen (je max. 5000 Zeichen).') . '#zustimmungen');
                exit;
            }
            $texts[$field][$variant] = $t;
        }
    }
    $n = (int)DB::fetchOne('SELECT COUNT(*) AS n FROM consent_versions WHERE community_id = ?', [$community['id']])['n'] + 1;
    $key = strtolower($community['marktpartner_id']) . '-' . $n;
    DB::execute('INSERT INTO consent_versions (community_id, version_key, texts, gueltig_ab, created_by) VALUES (?, ?, ?, CURRENT_DATE, ?)',
        [$community['id'], $key, json_encode($texts, JSON_UNESCAPED_UNICODE), Auth::userId()]);
    // Zwischengespeicherte Papierformulare dieser EEG verwerfen (enthalten die Zustimmungstexte).
    foreach (glob('/var/www/html/storage/cache/beitrittserklaerung/' . strtolower($community['marktpartner_id']) . '_*.pdf') ?: [] as $f) { @unlink($f); }
    logAudit($community['id'], 'consent_version.save', null, null, 'Neue Fassung der Zustimmungstexte gespeichert (' . $key . ')');
    header('Location: /portal/dokumente?success=' . urlencode('Neue Fassung ' . $key . ' gespeichert -- gilt ab sofort für neue Beitrittserklärungen.') . '#zustimmungen');
    exit;
});

$router->post('/portal/dokumente/:tab/save', function ($params) {
    [$owner, $docKey] = legalDocEditContext($params['tab']);
    $html = sanitizeLegalHtml((string)($_POST['content_html'] ?? ''));
    $back = '/portal/dokumente';
    if (trim(strip_tags($html)) === '') {
        header('Location: ' . $back . '?error=' . urlencode('Der Text ist leer -- nichts gespeichert.') . '#' . $params['tab']); exit;
    }
    if (strlen($html) > 500000) {
        header('Location: ' . $back . '?error=' . urlencode('Der Text ist zu lang (max. ca. 500 kB).') . '#' . $params['tab']); exit;
    }
    DB::execute('INSERT INTO legal_documents (community_id, doc_key, content_html, created_by) VALUES (?, ?, ?, ?)',
        [$owner['id'] ?? null, $docKey, $html, Auth::userId()]);
    logAudit($owner['id'] ?? null, 'legal_document.save', 'legal_document', null,
        legalDocumentDefs()[$docKey]['title'] . ($owner ? '' : ' (Plattform)') . ' als neue Fassung gespeichert');
    header('Location: ' . $back . '?success=' . urlencode(legalDocumentDefs()[$docKey]['title'] . ' gespeichert und sofort veröffentlicht.') . '#' . $params['tab']);
    exit;
});

/** Ältere Fassung bzw. die Standardfassung erneut als neueste Fassung speichern (Verlauf bleibt vollständig). */
$router->post('/portal/dokumente/:tab/restore', function ($params) {
    [$owner, $docKey] = legalDocEditContext($params['tab']);
    $back = '/portal/dokumente';
    if (($_POST['version_id'] ?? '') === 'standard') {
        $file = legalDefaultFile($owner, $docKey);
        if (!$file) { http_response_code(404); echo 'Keine Standardfassung vorhanden'; return; }
        $legalStand = ''; $legalMeta = null;
        ob_start(); include $file; $html = sanitizeLegalHtml(ob_get_clean());
        $what = 'Standardfassung';
    } else {
        $old = DB::fetchOne(
            'SELECT content_html FROM legal_documents WHERE id = ? AND doc_key = ? AND '
            . ($owner ? 'community_id = ?' : 'community_id IS NULL'),
            $owner ? [$_POST['version_id'] ?? '', $docKey, $owner['id']] : [$_POST['version_id'] ?? '', $docKey]
        );
        if (!$old) { http_response_code(404); echo 'Fassung nicht gefunden'; return; }
        $html = $old['content_html'];
        $what = 'frühere Fassung';
    }
    DB::execute('INSERT INTO legal_documents (community_id, doc_key, content_html, created_by) VALUES (?, ?, ?, ?)',
        [$owner['id'] ?? null, $docKey, $html, Auth::userId()]);
    logAudit($owner['id'] ?? null, 'legal_document.restore', 'legal_document', null,
        legalDocumentDefs()[$docKey]['title'] . ': ' . $what . ' wiederhergestellt');
    header('Location: ' . $back . '?success=' . urlencode(ucfirst($what) . ' wiederhergestellt.') . '#' . $params['tab']);
    exit;
});

$router->post('/portal/dokumente/infoblatt', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    $community = communityById($communityId);
    if (!$community) { http_response_code(403); echo 'Keine Energiegemeinschaft aktiv'; return; }
    $dir = communityDocDir($community['id']);
    $target = $dir . '/infoblatt.pdf';
    if (($_POST['action'] ?? '') === 'delete') {
        @unlink($target);
        logAudit($community['id'], 'infoblatt.delete', null, null, 'Infoblatt der EEG gelöscht');
        header('Location: /portal/dokumente?success=' . urlencode('Infoblatt gelöscht.') . '#infoblatt'); exit;
    }
    $file = $_FILES['infoblatt'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 20 * 1024 * 1024
        || file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        header('Location: /portal/dokumente?error=' . urlencode('Bitte eine PDF-Datei (max. 20 MB) auswählen.') . '#infoblatt'); exit;
    }
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        header('Location: /portal/dokumente?error=' . urlencode('Speichern fehlgeschlagen (Dateirechte im Speicher-Ordner prüfen).') . '#infoblatt'); exit;
    }
    logAudit($community['id'], 'infoblatt.upload', null, null, 'Infoblatt der EEG hochgeladen (' . round($file['size'] / 1024) . ' kB)');
    header('Location: /portal/dokumente?success=' . urlencode('Infoblatt hochgeladen und veröffentlicht.') . '#infoblatt'); exit;
});

$router->get('/portal/settings', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $tax       = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $myUser    = DB::fetchOne('SELECT first_name, last_name, signature_image FROM users WHERE id = ?', [Auth::userId()]);
    // EEG-Einstellungen für den Demo-Zugang maskieren (ZVR/Name bleiben sichtbar, siehe
    // demoMaskCommunitySettings()) -- Patrick, 23.08.2026.
    $community = demoMaskCommunitySettings($community, Auth::isDemo());
    $myUser = demoMaskSettingsUser($myUser, Auth::isDemo());
    $tax = demoMaskTaxConfig($tax, Auth::isDemo());
    $hasCustomLogo = communityLogoPath($communityId) !== null;
    // Community-weite Smart-Home-API-Keys (member_id NULL, seit migrate_20260901.sql) -- nicht
    // personengebunden wie ein Mitglied-Key unter /portal/my/api-keys, sondern eine
    // EEG-Ressource, die jeder Obmann/Platform-Admin dieser Community sehen/anlegen/widerrufen
    // kann (gleiches Prinzip wie die MQTT-Zugangsdaten weiter unten).
    $liveApiKeys = DB::fetchAll(
        'SELECT * FROM member_api_keys WHERE community_id = ? AND member_id IS NULL ORDER BY created_at DESC',
        [$communityId]
    );
    $newLiveApiKey = $_SESSION['flash_new_live_api_key'] ?? null;
    unset($_SESSION['flash_new_live_api_key']);
    require ROOT . '/src/views/pages/settings.php';
});

/** Neuen Community-weiten Live-Daten-API-Key anlegen (Obmann/Platform-Admin) -- Gegenstück zu
 *  POST /portal/my/api-keys, nur ohne member_id. */
$router->post('/portal/settings/live-api-keys', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        header('Location: /portal/settings?error=' . urlencode('Bitte einen Namen für den API-Key vergeben.'));
        exit;
    }
    $validityDays = ['30' => 30, '90' => 90, '365' => 365][$_POST['validity'] ?? ''] ?? null;
    // date('c') statt 'Y-m-d H:i:s': mit Zeitzonen-Offset, sonst liest Postgres die Ortszeit als UTC.
    $expiresAt = $validityDays ? date('c', strtotime("+{$validityDays} days")) : null;

    $token = bin2hex(random_bytes(32));
    $newKey = DB::fetchOne(
        'INSERT INTO member_api_keys (community_id, member_id, name, key_prefix, key_hash, expires_at)
         VALUES (?, NULL, ?, ?, ?, ?) RETURNING id',
        [$communityId, $name, substr($token, 0, 8), hash('sha256', $token), $expiresAt]
    );
    logAudit($communityId, 'live_api_key.create', 'member_api_key', $newKey['id'], 'Community-weiter Live-Daten-API-Key „' . $name . '" angelegt.');
    $_SESSION['flash_new_live_api_key'] = $token;
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/live-api-keys/:id/revoke', function ($params) {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    DB::execute(
        'UPDATE member_api_keys SET revoked_at = now() WHERE id = ? AND community_id = ? AND member_id IS NULL AND revoked_at IS NULL',
        [$params['id'], $communityId]
    );
    logAudit($communityId, 'live_api_key.revoke', 'member_api_key', $params['id'], 'Community-weiter Live-Daten-API-Key widerrufen.');
    header('Location: /portal/settings?success=' . urlencode('API-Key wurde widerrufen.'));
    exit;
});

/**
 * Eigenes Logo der EEG für Rechnungen/Verträge (anders als das plattformweite Website-Logo
 * unter /admin/templates) -- landet im geteilten Volume unter community-logos/{id}.png, siehe
 * communityLogoPath()/communityLogoAsset().
 */
$router->post('/portal/settings/logo', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        uploadRedirect('/portal/settings?error=' . urlencode('Logo-Upload fehlgeschlagen.'));
    }
    if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
        uploadRedirect('/portal/settings?error=' . urlencode('Datei zu groß (max. 5 MB).'));
    }
    if (@getimagesize($_FILES['logo']['tmp_name']) === false) {
        uploadRedirect('/portal/settings?error=' . urlencode('Datei ist kein gültiges Bild.'));
    }
    @mkdir('/var/www/html/latex-templates/community-logos', 0775, true);
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], '/var/www/html/latex-templates/community-logos/' . $communityId . '.png')) {
        uploadRedirect('/portal/settings?error=' . urlencode('Datei konnte nicht gespeichert werden.'));
    }
    logAudit($communityId, 'community.logo.upload', 'community', $communityId, 'EEG-Logo für Rechnungen/Verträge aktualisiert');
    uploadRedirect('/portal/settings?success=1');
});

$router->get('/portal/settings/logo/preview', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    denyDemoFileDownload();
    $path = communityLogoPath(Auth::activeCommunityId());
    if (!$path) { http_response_code(404); return; }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    readfile($path);
});

$router->post('/portal/settings/logo/delete', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    @unlink('/var/www/html/latex-templates/community-logos/' . $communityId . '.png');
    logAudit($communityId, 'community.logo.delete', 'community', $communityId, 'EEG-Logo entfernt (Standard-Logo wirkt wieder)');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/signature', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/settings?error=upload');
        exit;
    }
    DB::execute('UPDATE users SET signature_image = ? WHERE id = ?', [$signature, Auth::userId()]);
    logAudit(Auth::activeCommunityId(), 'settings.signature', 'user', Auth::userId(), 'Manager-Unterschrift aktualisiert');
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
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
    DB::setCommunity($communityId);
    $auditBefore = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    DB::execute(
        'UPDATE communities SET name=?, address=?, iban=?, bic=?, zvr_number=?, marktpartner_id=?, dashboard_url=?,
                                 aufteilungsschluessel_info=?,
                                 bank_name=?, account_holder=?, contact_phone=?, contact_email=?, creditor_id=?,
                                 sepa_pain_version=?, sepa_prenotification_days=?, mahngebuehr_eur=?, contracts_enabled=?,
                                 messe_demo_enabled=?, signature_offset_cm=?, signature_offset_x_cm=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['dashboard_url'] ?? '') ?: null,
            trim($_POST['aufteilungsschluessel_info'] ?? '') ?: null,
            trim($_POST['bank_name'] ?? '') ?: null,
            trim($_POST['account_holder'] ?? '') ?: null,
            trim($_POST['contact_phone'] ?? '') ?: null,
            trim($_POST['contact_email'] ?? '') ?: null,
            trim($_POST['creditor_id'] ?? '') ?: null,
            in_array($_POST['sepa_pain_version'] ?? '08', ['02', '08'], true) ? $_POST['sepa_pain_version'] : '08',
            max(1, min(60, (int)($_POST['sepa_prenotification_days'] ?? 14))),
            max(0, (float)str_replace(',', '.', $_POST['mahngebuehr_eur'] ?? '0')),
            // Als 'true'/'false' binden, nicht als PHP-bool: PDO (pgsql, emulate_prepares=off)
            // schickt PHP-false als leeren String '', den eine boolean-Spalte ablehnt (22P02).
            !empty($_POST['contracts_enabled']) ? 'true' : 'false',
            !empty($_POST['messe_demo_enabled']) ? 'true' : 'false',
            max(-3, min(3, (float)str_replace(',', '.', $_POST['signature_offset_cm'] ?? '0'))),
            max(-3, min(3, (float)str_replace(',', '.', $_POST['signature_offset_x_cm'] ?? '0'))),
            $communityId,
        ]
    );
    $auditAfter = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    logAuditDiff($communityId, 'community.update', 'community', $communityId,
        auditDiff($auditBefore ?? [], $auditAfter ?? [], [
            'name' => 'Name', 'address' => 'Adresse', 'iban' => 'IBAN', 'bic' => 'BIC',
            'zvr_number' => 'ZVR', 'marktpartner_id' => 'Marktpartner-ID', 'dashboard_url' => 'Dashboard-URL',
            'aufteilungsschluessel_info' => 'Aufteilungsschlüssel (Info)',
            'bank_name' => 'Bankname', 'account_holder' => 'Kontoinhaber', 'contact_phone' => 'Telefon',
            'contact_email' => 'Kontakt-E-Mail', 'creditor_id' => 'Gläubiger-ID',
            'sepa_pain_version' => 'SEPA-Format', 'sepa_prenotification_days' => 'SEPA-Vorlauftage',
            'mahngebuehr_eur' => 'Mahngebühr', 'contracts_enabled' => 'Verträge aktiv',
            'messe_demo_enabled' => 'Simulierte Werte (Messe-Demo)',
            'signature_offset_cm' => 'Unterschrift-Fein-Korrektur hoch/runter (cm)',
            'signature_offset_x_cm' => 'Unterschrift-Fein-Korrektur links/rechts (cm)',
        ]),
        'EEG-Stammdaten:');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tariff', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
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
    logAudit($communityId, 'tariff.update', 'community', $communityId,
        'Neuer Tarif gültig ab ' . ($_POST['valid_from'] ?? date('Y-m-d')) . ': Bezug '
        . str_replace(',', '.', $_POST['bezug_ct_kwh'] ?? '0') . ' ct/kWh, Einspeisung '
        . str_replace(',', '.', $_POST['einspeisung_ct_kwh'] ?? '0') . ' ct/kWh, Mitgliedsbeitrag '
        . str_replace(',', '.', $_POST['mitgliedsbeitrag_eur'] ?? '0') . ' €/Jahr');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tax', function () {
    Auth::requireLogin(); $communityId = Auth::requireManagerCommunity();
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
    logAudit($communityId, 'tax.update', 'community', $communityId,
        'Steuermodell "' . $taxModel . '"' . ($taxRate !== null ? ' (' . $taxRate . ' %)' : '')
        . ' gültig ab ' . ($_POST['valid_from'] ?? date('Y-m-d')));
    header('Location: /portal/settings?success=1');
    exit;
});
