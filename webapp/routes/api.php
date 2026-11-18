<?php

declare(strict_types=1);

/**
 * API-Routen (/api/...): App-API v1, Live-Daten, Geräte. Ohne CSRF-Formularprüfung
 * (siehe Router::dispatch), Authentifizierung per Token.
 *
 * Wird von public/index.php eingebunden (gleicher globaler Gültigkeitsbereich: $router, ROOT,
 * Hilfsfunktionen). Reihenfolge der Dateien dort beachten: die erste passende Route gewinnt.
 *
 * @var Router $router
 */

use App\Support\Services;

// Backchannel-Abmeldung: Keycloak ruft das intern auf (http://webapp/api/kc/backchannel-logout), wenn die
// gemeinsame Sitzung endet. Kein CSRF (/api/), geprüft wird das signierte Logout-Token.
$router->post('/api/kc/backchannel-logout', fn() => \App\Support\Services::keycloakAuth()->backchannelLogout($_POST)->send());

$router->get('/api/live/:slug', function ($params) {
    header('Content-Type: application/json');
    $slug = $params['slug'];
    $community = DB::fetchOne('SELECT id, messe_demo_enabled FROM communities WHERE slug = ? AND active = true', [$slug]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'Nicht gefunden']); return; }

    // Kurzzeit-Cache (siehe LiveStatsCache.php): v.a. für mehrere gleichzeitige Besucher
    // derselben EEG innerhalb desselben 5-Sekunden-Poll-Fensters (Patrick, 06.10.2026:
    // "das werden wir irgendwo zwischenspeichern [...] und von mir aus immer die Werte
    // aktualisieren"). TTL liegt unter dem Poll-Intervall, Werte sind dadurch nie merklich alt.
    $cached = LiveStatsCache::get($community['id']);
    if ($cached !== null) { echo json_encode($cached); return; }

    DB::setCommunity($community['id']);

    // Mitgliederzahl (Patrick, 01.11.2026: "wie viele Mitglieder wir sind" für die
    // animierte Live-Kennzahlen-Anzeige auf der Startseite) -- bewusst nur "active"
    // (pending/inactive zählen nicht als echtes, aktuelles Mitglied) und is_demo=false
    // (dieselbe Ausschluss-Logik wie bei den Demo-Zählpunkten weiter unten: ein fiktiver
    // Messe-Demo-Login-Datensatz ist kein echtes Mitglied und soll hier nicht mitzählen).
    // Rein aggregierte Zahl, keine Einzeldaten -- unbedenklich für eine öffentliche Seite.
    $memberCount = DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM members WHERE community_id = ? AND status = 'active' AND is_demo = false",
        [$community['id']]
    );
    $memberCount = (int)($memberCount['cnt'] ?? 0);

    // Vorberechnete Werte bevorzugen (Patrick, 09.10.2026: "Die Autarkie kann immer vorberechnet
    // werden und beim Aufruf nur aus einem gespeicherten Wert herausgelesen werden [...] aktuelle
    // Erzeugung, den aktuellen Verbrauch [...] Erzeugung heute [...] Das kann nicht sein, dass es
    // wieder fast 5 Sekunden dauert") -- mqtt-subscriber (live_stats_loop(), main.py) berechnet
    // dieselben Kennzahlen seither bereits alle ~15s im Hintergrund für JEDE EEG und schreibt sie
    // in community_live_stats/community_power_minutely. Dieser Request liest dann nur noch eine
    // einzelne, schon fertige Zeile -- keine eigene Aggregation über esp_measurements mehr nötig.
    // "updated_at"-Frische-Prüfung als Sicherheitsnetz: läuft mqtt-subscriber gerade nicht (Absturz/
    // Neustart), fällt dieser Request auf die alte, direkte Live-Berechnung weiter unten zurück,
    // statt veraltete oder fehlende Werte auszuliefern.
    $precomputed = DB::fetchOne(
        "SELECT bezug_w, einspeisung_w, today_wh, autarkie_pct, active_meters, total_meters
         FROM community_live_stats WHERE community_id = ? AND updated_at >= now() - INTERVAL '2 minutes'",
        [$community['id']]
    );
    if ($precomputed) {
        $series = DB::fetchAll(
            "SELECT bucket, bezug_w, einspeisung_w FROM community_power_minutely
             WHERE community_id = ? AND bucket >= now() - INTERVAL '2 hours' ORDER BY bucket",
            [$community['id']]
        );
        $payload = [
            'bezug_w'       => (int)$precomputed['bezug_w'],
            'einspeisung_w' => (int)$precomputed['einspeisung_w'],
            'autarkie_pct'  => (int)$precomputed['autarkie_pct'],
            'today_kwh'     => round(((float)$precomputed['today_wh']) / 1000, 2),
            'active_meters' => (int)$precomputed['active_meters'],
            'total_meters'  => (int)$precomputed['total_meters'],
            'member_count'  => $memberCount,
            'demo_simulation' => (bool)($community['messe_demo_enabled'] ?? false),
            'series'        => $series,
        ];
        LiveStatsCache::set($community['id'], $payload);
        echo json_encode($payload);
        return;
    }

    // Sicherheitsnetz: keine (aktuellen) vorberechneten Werte für diese EEG vorhanden (z.B.
    // mqtt-subscriber gerade erst gestartet) -- wie bisher direkt live berechnen.
    // mirror_source_metering_point_id IS NULL: Demo-Zählpunkte (siehe migrate_20260906.sql) aus
    // JEDER Community-weiten Summe ausschließen -- ihre Live-Werte sind nur eine Live-Spiegelung
    // eines ANDEREN, bereits selbst enthaltenen Zählpunkts, würden also doppelt zählen (Patrick,
    // 06.09.2026: "es dürfen die Daten nicht doppelt in dem Energiefluss angezeigt werden").
    // Diese Seite ist zudem öffentlich (live.stromfueralle.at), eine verdoppelte Zahl wäre also
    // nicht nur intern falsch, sondern für jeden Besucher sichtbar falsch.
    //
    // Bewusst als JOIN auf metering_points umgesetzt, NICHT als "metering_point_id NOT IN
    // (SELECT ...)" -- die erste Fassung (Vorfall 24.08.2026) crashte auf dem echten Server mit
    // PDOException "unsupported subplan type for SkipScan: Result" (DB.php:66), sobald ein
    // NOT-IN-Subplan mit TimescaleDBs SkipScan-Optimierung für DISTINCT ON auf esp_measurements
    // (einem Hypertable) zusammentraf -- reproduzierbar bei jedem Aufruf dieser Route, aber ohne
    // technische Fehlerdetails für anonyme Besucher sichtbar (nur "Es ist ein unerwarteter
    // Fehler aufgetreten"), deshalb erst über `docker compose logs webapp` gefunden. Dieselbe
    // Community-weite Ausschluss-Logik läuft in communityLivePower() (für die eingeloggte
    // Dashboard-Ansicht) bereits seit demselben Update als JOIN und crashte dort nie -- jetzt auf
    // exakt dasselbe, erprobte Muster vereinheitlicht.
    // mp.type-Filter: siehe ausführlicher Kommentar in communityLivePower() -- ein reiner
    // Einspeise-Zählpunkt (type='producer', kein Bezugsvertrag mit der EEG) darf seinen
    // privaten Netzbezug von dessen eigenem Energieversorger NICHT in die Community-weite
    // Bezugs-Summe dieser öffentlichen Seite einfließen lassen (Patrick, 01.11.2026).
    $agg = DB::fetchOne(
        "SELECT
            COALESCE(SUM(CASE WHEN mp_type IN ('consumer','prosumer') THEN power_bezug_w ELSE 0 END), 0)       AS total_bezug_w,
            COALESCE(SUM(CASE WHEN mp_type IN ('producer','prosumer') THEN power_einspeisung_w ELSE 0 END), 0) AS total_einspeisung_w,
            COUNT(*)                                AS active_meters
         FROM (
            SELECT DISTINCT ON (em.metering_point_id) em.power_bezug_w, em.power_einspeisung_w, mp.type AS mp_type
            FROM esp_measurements em
            JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.mirror_source_metering_point_id IS NULL
            WHERE em.community_id = ? AND em.time >= now() - INTERVAL '2 minutes'
            ORDER BY em.metering_point_id, em.time DESC
         ) latest",
        [$community['id']]
    );

    // Energie heute: kumulative Zählerstände, daher Differenz statt zeilenweise SUMmen. Als
    // Tages-Basiswert bewusst die letzte Messung VOR heute nehmen (nicht die erste Messung DES
    // Tages) -- sonst würde z.B. ein Test/Neustart mit nur 1-2 Messwerten "heute" (z.B. beim
    // manuellen Testen per mosquitto_pub, siehe esp32-firmware-README) MAX=MIN liefern und 0
    // kWh anzeigen, obwohl der Zähler längst deutlich mehr eingespeist hat. Gibt es für einen
    // Zählpunkt gar keine Messung vor heute (allererste Messung überhaupt ist von heute), lässt
    // sich "heute" nicht sinnvoll von "insgesamt" trennen -- dann 0 statt einer falsch hohen
    // Zahl (kompletter historischer Zählerstand als "heute" ausgegeben).
    //
    // Performance-Fix (Patrick, 06.10.2026: "das dauert 5 bis 10 Sekunden"): "jetzt" und
    // "basis" hatten ursprünglich GAR KEINE oder nur eine einseitige Zeitgrenze -- DISTINCT ON
    // musste dadurch pro Zählpunkt die GESAMTE Mess-Historie seit Inbetriebnahme durchsuchen
    // (esp_measurements ist ein TimescaleDB-Hypertable, wächst durch Messungen alle paar
    // Sekunden kontinuierlich). Mit einer zusätzlichen UNTEREN Zeitgrenze kann Postgres/
    // TimescaleDB per Chunk-Exclusion von vornherein alle älteren Chunks überspringen, statt
    // sie zu scannen. 2 bzw. 3 Tage sind großzügig bemessen (ein Zählpunkt, der so lange gar
    // nichts gemeldet hat, gilt ohnehin schon als "nicht aktiv", siehe total_meters/
    // active_meters unten) -- liefert in jedem realistischen Fall exakt dasselbe Ergebnis wie
    // zuvor, nur ohne den unbeschränkten Scan.
    $today = DB::fetchOne(
        "WITH jetzt AS (
            SELECT DISTINCT ON (em.metering_point_id) em.metering_point_id, em.energy_einspeisung_wh AS jetzt_wh
            FROM esp_measurements em
            JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.mirror_source_metering_point_id IS NULL
            WHERE em.community_id = ? AND em.time >= now() - INTERVAL '2 days'
            ORDER BY em.metering_point_id, em.time DESC
         ),
         basis AS (
            SELECT DISTINCT ON (em.metering_point_id) em.metering_point_id, em.energy_einspeisung_wh AS basis_wh
            FROM esp_measurements em
            JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.mirror_source_metering_point_id IS NULL
            WHERE em.community_id = ? AND em.time < CURRENT_DATE AND em.time >= CURRENT_DATE - INTERVAL '3 days'
            ORDER BY em.metering_point_id, em.time DESC
         )
         SELECT COALESCE(SUM(GREATEST(j.jetzt_wh - COALESCE(b.basis_wh, j.jetzt_wh), 0)), 0) AS today_wh
         FROM jetzt j LEFT JOIN basis b ON b.metering_point_id = j.metering_point_id",
        [$community['id'], $community['id']]
    );

    // Zeitreihe: pro Bucket/Zähler zuerst den Durchschnitt bilden, dann über die Zähler summieren
    // (sonst würde ein Zähler mit mehr Messwerten im Bucket das Ergebnis verzerren).
    $series = DB::fetchAll(
        "SELECT bucket,
            SUM(bezug_w)       AS bezug_w,
            SUM(einspeisung_w) AS einspeisung_w
         FROM (
            SELECT
                time_bucket('5 minutes', em.time) AS bucket,
                em.metering_point_id,
                AVG(em.power_bezug_w)             AS bezug_w,
                AVG(em.power_einspeisung_w)       AS einspeisung_w
            FROM esp_measurements em
            JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.mirror_source_metering_point_id IS NULL
            WHERE em.community_id = ? AND em.time >= now() - INTERVAL '2 hours'
            GROUP BY bucket, em.metering_point_id
         ) per_meter_bucket
         GROUP BY bucket ORDER BY bucket",
        [$community['id']]
    );

    $bezug = (int)($agg['total_bezug_w'] ?? 0);
    $einsp = (int)($agg['total_einspeisung_w'] ?? 0);
    $autarkie = $bezug > 0 ? min(100, round($einsp / $bezug * 100)) : 0;

    // Für den "nicht alle Zählpunkte online"-Hinweis: alle bekannten (schon mal gemeldeten)
    // Zählpunkte vs. die gerade aktiven -- siehe communityLivePower() (Patrick, 30.07.2026).
    $totalMeters = DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM metering_points
         WHERE community_id = ? AND active = true AND meter_code IS NOT NULL AND esp_last_seen_at IS NOT NULL
           AND mirror_source_metering_point_id IS NULL",
        [$community['id']]
    );

    $payload = [
        'bezug_w'       => $bezug,
        'einspeisung_w' => $einsp,
        'autarkie_pct'  => $autarkie,
        'today_kwh'     => round(($today['today_wh'] ?? 0) / 1000, 2),
        'active_meters' => (int)($agg['active_meters'] ?? 0),
        'total_meters'  => (int)($totalMeters['cnt'] ?? 0),
        'member_count'  => $memberCount,
        'demo_simulation' => (bool)($community['messe_demo_enabled'] ?? false),
        'series'        => $series,
    ];
    LiveStatsCache::set($community['id'], $payload);
    echo json_encode($payload);
});

$router->get('/api/communities/search', function () {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $results = DB::fetchAll(
        'SELECT name, slug FROM communities WHERE active = true AND name ILIKE ? LIMIT 10', [$q]
    );
    echo json_encode($results);
});


/**
 * App-Pendant zu /portal/invoices/:id/pdf, nur mit Bearer-Token statt Browser-Session als
 * Identitätsquelle -- der IDOR-Schutz ist hier sogar einfacher: das Token trägt bereits genau
 * EINE member_id (siehe AppApiAuth), es muss also nur noch mit der Rechnung abgeglichen werden,
 * kein zusätzlicher Manager/Platform-Admin-Sonderfall wie im Web (die App ist reine
 * Mitglieder-Selbstbedienung).
 */
$router->get('/api/v1/invoices/:id/pdf', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (denyDemoApiFileDownload($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $invoice = loadInvoiceForPdf($params['id']);
    if (!$invoice) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Rechnung nicht gefunden.']);
        return;
    }
    if ($invoice['member_id'] !== $ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Zugriff.']);
        return;
    }

    renderInvoicePdf($invoice);
});


/**
 * Schritt 1: E-Mail + Passwort. Nutzt dieselbe Auth::checkPassword()/RateLimiter-Logik wie der
 * Web-Login (gemeinsamer Fehlversuch-Zähler pro E-Mail/IP -- ein Angreifer kann die Sperre nicht
 * einfach umgehen, indem er zwischen Web- und App-Endpunkt wechselt). Bei aktiver 2FA folgt
 * Schritt 2 (/api/v1/login/2fa) statt sofort Token auszugeben.
 */
$router->post('/api/v1/login', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $email = (string)($body['email'] ?? '');
    $password = (string)($body['password'] ?? '');
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;

    if (RateLimiter::isLoginBlocked($email)) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.']);
        return;
    }
    $user = Auth::checkPassword($email, $password);
    if (!$user) {
        RateLimiter::registerLoginFailure($email);
        http_response_code(401);
        echo json_encode(['error' => 'E-Mail oder Passwort falsch.']);
        return;
    }
    RateLimiter::resetLoginAttempts($email);

    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        echo json_encode([
            'totp_required' => true,
            'login_ticket'  => AppApiAuth::issueTicket('totp_pending', ['uid' => $user['id'], 'dl' => $deviceLabel]),
        ]);
        return;
    }

    echo json_encode(appLoginSuccessResponse($user['id'], $deviceLabel));
});

/**
 * Schritt 2 (nur falls 2FA aktiv): Ticket aus Schritt 1 + 6-stelliger Code. Eigener
 * Rate-Limiter-Zähler pro User-ID (RateLimiter::isTotpBlocked), unabhängig vom E-Mail/IP-Zähler
 * aus Schritt 1 -- exakt dieselbe Logik wie /portal/login/2fa im Web.
 */
$router->post('/api/v1/login/2fa', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('totp_pending', (string)($body['login_ticket'] ?? ''));
    if (!$ticket || empty($ticket['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Login-Ticket ungültig oder abgelaufen. Bitte erneut mit E-Mail/Passwort anmelden.']);
        return;
    }
    $uid = (string)$ticket['uid'];
    $deviceLabel = $ticket['dl'] ?? null;

    if (RateLimiter::isTotpBlocked($uid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.']);
        return;
    }
    $u = DB::fetchOne('SELECT totp_secret FROM users WHERE id = ? AND active = true', [$uid]);
    if (!$u || empty($u['totp_secret']) || !totpVerify(totpSecretFromStorage($u['totp_secret']), (string)($body['code'] ?? ''))) {
        RateLimiter::registerTotpFailure($uid);
        http_response_code(401);
        echo json_encode(['error' => 'Code ungültig oder abgelaufen.']);
        return;
    }
    RateLimiter::resetTotpAttempts($uid);

    echo json_encode(appLoginSuccessResponse($uid, $deviceLabel));
});

/**
 * Nur nötig, wenn Schritt 1/2 eine Auswahlliste zurückgegeben haben (Account ist in mehreren
 * EEGs Mitglied und/oder gleichzeitig Mitglied UND Obmann). Löst die Optionen bewusst FRISCH
 * neu auf (statt der Liste aus dem Ticket zu vertrauen) -- verhindert, dass eine manipulierte
 * community_id/role akzeptiert wird, die zwischenzeitlich (z.B. Mitgliedschaft beendet) nicht
 * mehr gültig ist. "role" muss mitgeschickt werden, falls für dieselbe community_id sowohl eine
 * Mitglieds- als auch eine Obmann-Option existiert.
 */
$router->post('/api/v1/login/select-community', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('community_pending', (string)($body['selection_ticket'] ?? ''));
    if (!$ticket || empty($ticket['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Auswahl-Ticket ungültig oder abgelaufen. Bitte erneut anmelden.']);
        return;
    }
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;
    // Leerstring wie NULL behandeln (Admin-Option hat community_id=NULL, ein Client schickt für
    // "keine Auswahl nötig" typischerweise "" statt das Feld ganz wegzulassen).
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    $role = (string)($body['role'] ?? 'member');

    $chosen = null;
    foreach (resolveAppRoleOptions((string)$ticket['uid']) as $m) {
        if ($m['community_id'] === $communityId && $m['role'] === $role) { $chosen = $m; break; }
    }
    if (!$chosen) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Community-Auswahl.']);
        return;
    }
    echo json_encode(appIssueSessionResponse($chosen, $deviceLabel));
});

/**
 * Listet alle Rollen-Optionen des eingeloggten Accounts (Mitgliedschaft(en) + Obmann-Zugänge)
 * -- Grundlage für einen Rollen-/Community-Umschalter INNERHALB der App, ohne Neuanmeldung
 * (Patrick, 19.08.2026: "als Obmann hab ich nur Mitglieder und Konto, wo wechselt man die
 * Rolle?"). Markiert zusätzlich, welche Option gerade aktiv ist (aus dem Access-Token im
 * Request), damit der Client sie in der Auswahl hervorheben kann.
 */
$router->get('/api/v1/roles', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $options = resolveAppRoleOptions($ctx['user_id']);
    echo json_encode(['roles' => array_map(fn($m) => [
        'role'           => $m['role'],
        'community_id'   => $m['community_id'],
        'community_name' => $m['community_name'],
        'name'           => $m['name'],
        'active'         => $m['role'] === $ctx['role'] && $m['community_id'] === $ctx['community_id'],
    ], $options)]);
});

/**
 * Wechselt die aktive Rolle/Community, OHNE dass sich der Nutzer neu anmelden muss (Web-Pendant:
 * POST /portal/switch-role, dort session-basiert) -- braucht dafür ein aktuell gültiges
 * Zugriffstoken statt eines separaten Auswahl-Tickets wie beim Login (dort ist die Identität ja
 * noch nicht bestätigt, hier schon). Liefert wie beim Login ein frisches Zugriffs-/
 * Refresh-Token-Paar für die NEUE Rolle zurück -- das alte Refresh-Token bleibt bis zu seinem
 * Ablauf gültig (kein Widerruf nötig, ein Rollenwechsel ist kein Sicherheitsvorfall, nur ein
 * zweites paralleles Gerätesitzungs-Paar, siehe app_sessions).
 */
$router->post('/api/v1/switch-role', function () {
    // allowDemoWrite=true: sonst könnte ein Demo-Login (siehe migrate_20260905.sql) über die App
    // nicht mal zwischen seinen vier vorgesehenen Rollen wechseln -- der Rollenwechsel selbst
    // verändert keine Plattform-Daten, ist also unbedenklich read-only-kompatibel.
    $ctx = AppApiAuth::requireAppAuth(true);
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    // Leerstring wie NULL behandeln (Admin-Option hat community_id=NULL).
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    $role = (string)($body['role'] ?? '');
    // member_id disambiguiert bei role='member', falls ein Login mehrere Mitglied-Identitäten in
    // derselben Community hat (Demo-Logins, siehe migrate_20260905.sql) -- bei allen anderen
    // Accounts leer, dann matcht der Vergleich unten wie bisher rein über community_id+role.
    $memberId = !empty($body['member_id']) ? (string)$body['member_id'] : null;
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;

    $chosen = null;
    foreach (resolveAppRoleOptions($ctx['user_id']) as $m) {
        if ($m['community_id'] === $communityId && $m['role'] === $role
            && ($m['member_id'] ?? null) === $memberId) { $chosen = $m; break; }
    }
    if (!$chosen) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Rollen-/Community-Auswahl.']);
        return;
    }
    echo json_encode(appIssueSessionResponse($chosen, $deviceLabel));
});

/**
 * Tauscht ein Refresh-Token gegen ein frisches Zugriffstoken -- soll die App still im
 * Hintergrund aufrufen, sobald das Zugriffstoken (15 Min gültig) abläuft, statt den Nutzer
 * erneut Passwort/2FA eingeben zu lassen. Das Refresh-Token wird dabei ROTIERT (siehe
 * AppApiAuth::verifyAndRotateRefreshToken()) -- die App MUSS das neu zurückgegebene
 * refresh_token speichern, das alte ist ab sofort ungültig.
 */
$router->post('/api/v1/token/refresh', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $result = AppApiAuth::verifyAndRotateRefreshToken((string)($body['refresh_token'] ?? ''));
    if (!$result) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh-Token ungültig oder abgelaufen. Bitte erneut anmelden.']);
        return;
    }
    echo json_encode([
        'access_token'  => AppApiAuth::issueAccessToken($result['community_id'], $result['role'], $result['member_id'], $result['user_id']),
        'refresh_token' => $result['refresh_token'],
        'expires_in'    => AppApiAuth::accessTokenTtl(),
    ]);
});

/** Meldet das aktuelle Gerät ab (widerruft dessen Refresh-Token). Immer "ok", auch wenn das
 *  Token schon ungültig war -- Logout soll nie mit einem Fehler abbrechen können. */
$router->post('/api/v1/logout', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    if (!empty($body['refresh_token'])) {
        AppApiAuth::revokeRefreshToken((string)$body['refresh_token']);
    }
    echo json_encode(['status' => 'ok']);
});

/**
 * Test-Endpoint der Smart-Home-API: prüft nur, ob ein API-Key gültig ist, und gibt Basisinfos
 * zurück -- keine Energiedaten (die liefert /api/v1/live, siehe unten). Dient zum Testen der
 * Authentifizierung z.B. mit Node-RED, bevor man die Live-Daten abruft.
 *
 * Kein DB::setCommunity() vorab -- der Key wird bewusst GLOBAL per Hash gesucht (die Community
 * ist ja erst das Ergebnis der Suche), member_api_keys hat deshalb auch keine Community-RLS
 * (siehe migrate_20260731.sql).
 */
$router->get('/api/v1/me', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Fehlender oder ungültiger Authorization-Header. Erwartet: "Bearer <API-Key>".']);
        return;
    }
    $hash = hash('sha256', trim($m[1]));
    // LEFT JOIN members statt JOIN: seit migrate_20260901.sql gibt es neben persönlichen
    // Mitglied-Keys (member_id gesetzt) auch Community-weite Obmann-/Platform-Admin-Keys
    // (member_id NULL, angelegt unter /portal/settings) -- ein JOIN hätte deren Zeile komplett
    // ausgefiltert.
    $key = DB::fetchOne(
        'SELECT k.*, m.first_name, m.last_name, c.name AS community_name
         FROM member_api_keys k
         LEFT JOIN members m ON m.id = k.member_id
         JOIN communities c ON c.id = k.community_id
         WHERE k.key_hash = ?',
        [$hash]
    );
    if (!$key || $key['revoked_at'] || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
        http_response_code(401);
        echo json_encode(['error' => 'API-Key ungültig, widerrufen oder abgelaufen.']);
        return;
    }
    DB::execute('UPDATE member_api_keys SET last_used_at = now() WHERE id = ?', [$key['id']]);
    echo json_encode([
        'status'    => 'ok',
        'scope'     => $key['member_id'] ? 'member' : 'community',
        'member'    => $key['member_id'] ? ($key['first_name'] . ' ' . $key['last_name']) : null,
        'community' => $key['community_name'],
        'note'      => 'Authentifizierung erfolgreich. Live-Energiedaten: GET /api/v1/live.',
    ]);
});

/**
 * Live-Energiedaten fürs Smart-Home (Node-RED etc., siehe docs/ESP_IDEEN.md Punkt 2). Zwei
 * Key-Arten seit migrate_20260901.sql:
 * - Mitglied-Key (member_id gesetzt, über /portal/my/api-keys angelegt): bezug_w/einspeisung_w
 *   sind wie bisher die EIGENE Leistung des Mitglieds.
 * - Community-Key (member_id NULL, über /portal/settings angelegt -- Obmann/Platform-Admin,
 *   Patrick 18.08.2026: "auch für den ganzen Verein, nicht nur ein einzelnes Mitglied"):
 *   bezug_w/einspeisung_w sind stattdessen die GESAMTE Community-Leistung (identisch zu
 *   community.bezug_w unten) -- ein Skript, das nur die Top-Level-Felder liest (bisheriges
 *   Verhalten), bekommt dann sinnvoll "die Zahl, die zu diesem Key gehört" statt 0.
 * community_autarkie_pct und das neue "community"-Objekt (inkl. aktiver/gesamter Zählpunkte)
 * sind bei BEIDEN Key-Arten identisch befüllt. Gibt 0/null-Werte zurück statt eines Fehlers,
 * wenn noch keine Ausleseeinheit(en) Daten liefern -- ein Smart-Home-Skript soll bei "noch
 * keine Daten" nicht mit einem HTTP-Fehler abbrechen müssen.
 */
$router->get('/api/v1/live', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Fehlender oder ungültiger Authorization-Header. Erwartet: "Bearer <API-Key>".']);
        return;
    }
    $hash = hash('sha256', trim($m[1]));
    $key = DB::fetchOne('SELECT * FROM member_api_keys WHERE key_hash = ?', [$hash]);
    if (!$key || $key['revoked_at'] || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
        http_response_code(401);
        echo json_encode(['error' => 'API-Key ungültig, widerrufen oder abgelaufen.']);
        return;
    }
    DB::execute('UPDATE member_api_keys SET last_used_at = now() WHERE id = ?', [$key['id']]);

    $own = null;
    if ($key['member_id']) {
        // Pro Zählpunkt nur den jeweils neuesten Messwert im Fenster nehmen, nicht alle Zeilen
        // aufsummieren (bei 5s-Sende-Intervall sonst Werte um ein Vielfaches zu hoch).
        //
        // Zwei-Ansichten-Erweiterung (Patrick, 01.11.2026): "einmal seine wirklichen
        // Zählerdaten, einmal die Daten über die Energiegemeinschaft" -- soll AUCH für einen
        // Kunden funktionieren, der nur einspeist (z.B. sein Vater, reiner Einspeiser ohne
        // Bezugsvertrag). mp_type wird pro Zählpunkt mitgelesen: "zaehlerdaten" unten ist
        // IMMER die echte, unveränderte Messung (unabhängig vom Vertrag -- ein bidirektionaler
        // Smart-Meter misst Bezug/Einspeisung ja physikalisch, egal was vertraglich vereinbart
        // ist). "gemeinschaft" wendet zusätzlich dieselbe Unterscheidung an wie
        // Billing::generateDrafts() und (seit demselben Tag) die Community-Live-Summen: Bezug
        // zählt nur für type IN ('consumer','prosumer'), Einspeisung nur für IN ('producer',
        // 'prosumer') -- bei einem reinen Einspeiser ist "gemeinschaft.bezug_w" also bewusst
        // immer 0, selbst wenn "zaehlerdaten.bezug_w" gerade echten privaten Netzbezug zeigt.
        $own = DB::fetchOne(
            "SELECT
                COALESCE(SUM(power_bezug_w), 0)        AS bezug_w,
                COALESCE(SUM(power_einspeisung_w), 0)  AS einspeisung_w,
                COALESCE(SUM(energy_bezug_wh), 0)       AS energy_bezug_wh,
                COALESCE(SUM(energy_einspeisung_wh), 0) AS energy_einspeisung_wh,
                COALESCE(SUM(CASE WHEN mp_type IN ('consumer','prosumer') THEN power_bezug_w ELSE 0 END), 0)        AS eeg_bezug_w,
                COALESCE(SUM(CASE WHEN mp_type IN ('producer','prosumer') THEN power_einspeisung_w ELSE 0 END), 0)  AS eeg_einspeisung_w,
                COALESCE(SUM(CASE WHEN mp_type IN ('consumer','prosumer') THEN energy_bezug_wh ELSE 0 END), 0)       AS eeg_energy_bezug_wh,
                COALESCE(SUM(CASE WHEN mp_type IN ('producer','prosumer') THEN energy_einspeisung_wh ELSE 0 END), 0) AS eeg_energy_einspeisung_wh
             FROM (
                SELECT DISTINCT ON (em.metering_point_id) em.power_bezug_w, em.power_einspeisung_w,
                       em.energy_bezug_wh, em.energy_einspeisung_wh, mp.type AS mp_type
                FROM esp_measurements em
                JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.member_id = ?
                WHERE em.community_id = ? AND em.time >= now() - INTERVAL '2 minutes'
                ORDER BY em.metering_point_id, em.time DESC
             ) latest",
            [$key['member_id'], $key['community_id']]
        );
    }

    $community = communityLivePower($key['community_id']);
    $autarkie = $community['bezug_w'] > 0 ? min(100, round($community['einsp_w'] / $community['bezug_w'] * 100)) : 0;

    echo json_encode([
        'status'                 => 'ok',
        'scope'                  => $key['member_id'] ? 'member' : 'community',
        'bezug_w'                => $own !== null ? (int)$own['bezug_w'] : $community['bezug_w'],
        'einspeisung_w'          => $own !== null ? (int)$own['einspeisung_w'] : $community['einsp_w'],
        'community_autarkie_pct' => $autarkie,
        // Nur bei einem persönlichen Mitglied-Key befüllt (member_id gesetzt) -- ein
        // Community-Key hat keine "eigenen" Zählerdaten eines einzelnen Mitglieds.
        'zaehlerdaten' => $own !== null ? [
            'bezug_w'                => (int)$own['bezug_w'],
            'einspeisung_w'          => (int)$own['einspeisung_w'],
            'energy_bezug_wh'        => (int)$own['energy_bezug_wh'],
            'energy_einspeisung_wh'  => (int)$own['energy_einspeisung_wh'],
        ] : null,
        'gemeinschaft' => $own !== null ? [
            'bezug_w'                => (int)$own['eeg_bezug_w'],
            'einspeisung_w'          => (int)$own['eeg_einspeisung_w'],
            'energy_bezug_wh'        => (int)$own['eeg_energy_bezug_wh'],
            'energy_einspeisung_wh'  => (int)$own['eeg_energy_einspeisung_wh'],
        ] : null,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
    ]);
});

// ─── Mitglieder-App: Daten-Endpunkte (Bearer-Zugriffstoken, siehe AppApiAuth) ────
// Zeigen jeweils nur Daten des Mitglieds/der Community aus dem Token -- DB::setCommunity()
// aktiviert dieselben RLS-Policies wie im Web-Portal, sodass ein Token niemals fremde
// Community-Daten sehen kann, selbst bei einem Bug im Handler.

/**
 * Übersicht für den App-Startbildschirm: aktuelle Leistung des Mitglieds, Live-Zahlen der
 * ganzen Community, aktueller Verbrauchsmonat, letzte versendete Rechnung -- inhaltlich das
 * JSON-Äquivalent der Kopfzeile von member_dashboard.php, reicht für einen ersten
 * Übersichtsbildschirm der App ohne einen zweiten Request.
 */
$router->get('/api/v1/dashboard', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $member = DB::fetchOne('SELECT first_name, last_name FROM members WHERE id = ?', [$ctx['member_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true", [$ctx['member_id']]),
        'id'
    );
    $currentPowerW = $mpIds ? memberCurrentNetPowerW($ctx['community_id'], $mpIds) : null;
    $community = communityLivePower($ctx['community_id']);

    $currentMonth = null;
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $row = DB::fetchOne(
            "SELECT date_trunc('month', time) AS monat,
                    COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme_kwh,
                    COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung_kwh
             FROM eda_measurements
             WHERE community_id = ? AND metering_point_id IN ($placeholders) AND quality IN ('L1','L2')
             GROUP BY monat ORDER BY monat DESC LIMIT 1",
            array_merge([$ctx['community_id']], $mpIds)
        );
        if ($row) {
            $currentMonth = [
                'label'          => monatsLabel((string)$row['monat']),
                'teilnahme_kwh'  => (float)$row['teilnahme_kwh'],
                'erzeugung_kwh'  => (float)$row['erzeugung_kwh'],
            ];
        }
    }

    $lastInvoice = DB::fetchOne(
        'SELECT id, rechnungsnummer, saldo_eur, created_at, sent_at FROM invoices
         WHERE member_id = ? AND sent_at IS NOT NULL ORDER BY created_at DESC LIMIT 1',
        [$ctx['member_id']]
    );

    echo json_encode([
        'member' => [
            'id'   => $ctx['member_id'],
            'name' => trim($member['first_name'] . ' ' . $member['last_name']),
        ],
        'current_power_w' => $currentPowerW,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
        'current_month' => $currentMonth,
        'last_invoice'  => $lastInvoice ? [
            'id'              => $lastInvoice['id'],
            'rechnungsnummer' => $lastInvoice['rechnungsnummer'],
            'saldo_eur'       => (float)$lastInvoice['saldo_eur'],
            'created_at'      => appDate($lastInvoice['created_at']),
        ] : null,
    ]);
});

/**
 * Leichtgewichtiger Polling-Endpunkt für die aktuelle Leistung -- zum Pollen alle paar Sekunden
 * (Patrick, 19.08.2026: "aktuelle Leistung automatisch aktualisieren, ohne die ganze Seite neu
 * zu laden"), OHNE bei jedem Aufruf die komplette /api/v1/dashboard-Antwort inkl. der
 * schwereren Monatsaggregation gegen eda_measurements neu zu berechnen. Web-Pendant:
 * /portal/api/current-power + /portal/api/live-power (dort zwei getrennte Endpunkte, hier zu
 * einem zusammengefasst -- eine App braucht für einen "Live"-Bildschirm ohnehin meist beides
 * gleichzeitig). Funktioniert für role=member (eigene Nettoleistung + Community) UND
 * role=manager (nur Community-Werte, current_power_w dann null -- ein reiner Obmann-Account
 * hat keine eigenen Zählpunkte).
 */
$router->get('/api/v1/current-power', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $currentPowerW = null;
    if ($ctx['member_id']) {
        $mpIds = array_column(
            DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ? AND active = true', [$ctx['member_id']]),
            'id'
        );
        $currentPowerW = $mpIds ? memberCurrentNetPowerW($ctx['community_id'], $mpIds) : null;
    }
    $community = communityLivePower($ctx['community_id']);

    echo json_encode([
        'current_power_w' => $currentPowerW,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
    ]);
});

/**
 * Monatlicher Verbrauchs-/Erzeugungsverlauf (für ein Balkendiagramm in der App) -- dieselbe
 * Datengrundlage (eda_measurements, nur L1/L2-Qualität) wie der Verlauf im Kundenportal.
 * ?months=N (Default 6, maximal 24) steuert, wie viele Monate zurück geliefert werden.
 */
$router->get('/api/v1/consumption', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ?", [$ctx['member_id']]),
        'id'
    );
    $rows = [];
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $rows = DB::fetchAll(
            "SELECT date_trunc('month', time) AS monat,
                    COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme_kwh,
                    COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung_kwh
             FROM eda_measurements
             WHERE community_id = ? AND metering_point_id IN ($placeholders) AND quality IN ('L1','L2')
             GROUP BY monat ORDER BY monat DESC LIMIT ?",
            array_merge([$ctx['community_id']], $mpIds, [$months])
        );
    }

    echo json_encode(['months' => array_map(fn($r) => [
        'month'         => date('Y-m', strtotime((string)$r['monat'])),
        'label'         => monatsLabel((string)$r['monat']),
        'teilnahme_kwh' => (float)$r['teilnahme_kwh'],
        'erzeugung_kwh' => (float)$r['erzeugung_kwh'],
    ], $rows)]);
});

/**
 * Viertelstündlicher Verbrauch vs. gemeinschaftliche Eigendeckung für EINEN Tag (App-Äquivalent
 * von /portal/my/verbrauch, siehe memberIntervalDayData() weiter oben für die geteilte Logik).
 * ?date=YYYY-MM-DD, Default: heute (anders als im Web-Portal kein automatisches Zurückfallen auf
 * den letzten Tag mit Daten -- die App zeigt bei fehlenden Werten stattdessen einen eigenen
 * Hinweis an, siehe app.md).
 */
$router->get('/api/v1/consumption/interval', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Datum, erwartet YYYY-MM-DD.']);
        return;
    }
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('consumer', 'prosumer')", [$ctx['member_id']]),
        'id'
    );
    $data = memberIntervalDayData($ctx['community_id'], $mpIds, $date);

    echo json_encode([
        'date'                   => $date,
        'has_data'               => $data['has_data'],
        'total_messung_kwh'      => $data['total_messung_kwh'],
        'total_gemeinschaft_kwh' => $data['total_gemeinschaft_kwh'],
        'intervals'              => array_map(fn($iv) => [
            'zeit'           => $iv['zeit'],
            'verbrauch_w'    => $iv['verbrauch_w'],
            'gemeinschaft_w' => $iv['gemeinschaft_w'],
        ], $data['intervals']),
    ]);
});

/**
 * Spiegelbild von GET /api/v1/consumption/interval, für die eigene Einspeisung (App-Äquivalent
 * von /portal/my/einspeisung) -- Patrick, 06.09.2026: "warum haben die Einspeiser nicht die
 * Möglichkeit, ihre eingespeiste Leistung in einem Diagramm einzusehen?". 'total_messung_kwh'
 * ist bei GENERATION die gemeinschaftsweite Gesamterzeugung (NICHT mitgliedsspezifisch, siehe
 * memberIntervalDayData()) -- bewusst trotzdem mitgeliefert (falls die App sie mal als
 * Kontext-Info zeigen will). Seit migrate_20260907.sql zusätzlich 'total_erzeugung_gesamt_kwh'/
 * 'erzeugung_gesamt_w' (eigene GESAMTE Erzeugung des Zählpunkts, Patrick, 06.09.2026
 * [Folgetermin]: "wie viel sie einspeisen und wie viel davon in der Energiegemeinschaft
 * verwendet wurde") -- 'has_erzeugung_gesamt' zeigt an, ob dieser Wert für den Tag vorliegt
 * (fehlt bei vor der Migration importierten Tagen, siehe memberIntervalDayData()).
 */
$router->get('/api/v1/production/interval', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Datum, erwartet YYYY-MM-DD.']);
        return;
    }
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('producer', 'prosumer')", [$ctx['member_id']]),
        'id'
    );
    $data = memberIntervalDayData($ctx['community_id'], $mpIds, $date, 'GENERATION');

    echo json_encode([
        'date'                       => $date,
        'has_data'                   => $data['has_data'],
        'has_erzeugung_gesamt'       => $data['has_erzeugung_gesamt'],
        'total_messung_kwh'          => $data['total_messung_kwh'],
        'total_gemeinschaft_kwh'     => $data['total_gemeinschaft_kwh'],
        'total_erzeugung_gesamt_kwh' => $data['total_erzeugung_gesamt_kwh'],
        'intervals'                  => array_map(fn($iv) => [
            'zeit'               => $iv['zeit'],
            'einspeisung_w'      => $iv['gemeinschaft_w'],
            'erzeugung_gesamt_w' => $iv['erzeugung_gesamt_w'],
        ], $data['intervals']),
    ]);
});

/** Rechnungsliste des Mitglieds (Metadaten -- die PDF selbst kommt über
 *  /api/v1/invoices/:id/pdf). */
$router->get('/api/v1/invoices', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $invoices = DB::fetchAll(
        'SELECT i.id, i.rechnungsnummer, i.saldo_eur, i.sent_at, i.created_at, br.quartal, br.period_from, br.period_to
         FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$ctx['member_id']]
    );

    echo json_encode(['invoices' => array_map(fn($i) => [
        'id'              => $i['id'],
        'rechnungsnummer' => $i['rechnungsnummer'],
        'saldo_eur'       => (float)$i['saldo_eur'],
        'quartal'         => $i['quartal'],
        'period_from'     => appDate($i['period_from']),
        'period_to'       => appDate($i['period_to']),
        'sent_at'         => appDate($i['sent_at']),
        'created_at'      => appDate($i['created_at']),
    ], $invoices)]);
});

/** Eigene Zählpunkte (Zählpunktnummer, Typ, aktiv/inaktiv) -- kein WLAN-Passwort/keine
 *  Diagnosedaten hier (die bleiben absichtlich Web-Portal-only, siehe member_detail.php). */
$router->get('/api/v1/metering-points', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $points = DB::fetchAll(
        'SELECT id, zaehlpunkt_nr, type, active, registered_at, (p1_key_enc IS NOT NULL) AS p1_key_hinterlegt
         FROM metering_points WHERE member_id = ? ORDER BY registered_at',
        [$ctx['member_id']]
    );

    echo json_encode(['metering_points' => array_map(fn($p) => [
        'id'             => $p['id'],
        'zaehlpunkt_nr'  => $p['zaehlpunkt_nr'],
        'type'           => $p['type'],
        'active'         => (bool)$p['active'],
        'registered_at'  => appDate($p['registered_at']),
        // Nur ja/nein -- der Schlüssel selbst ist ausschließlich für Vorstand/Admin.
        'p1_key_hinterlegt' => in_array($p['p1_key_hinterlegt'], [true, 't', '1', 1], true),
    ], $points)]);
});

// ─── Mitglieder-App: Verträge, Dokumente, DSGVO, Support, Profil/2FA ─────────
// JSON-Äquivalente der /portal/my/*- bzw. /portal/profile|password-Routen, jeweils per
// Bearer-Zugriffstoken statt Session (siehe AppApiAuth). Die reine Datenlogik (SQL, PDF-
// Vorlagen, Mail-Versand) wird 1:1 über dieselben Helferfunktionen wie im Web-Portal
// wiederverwendet -- nur Auth-Quelle (Token statt Session) und Antwortformat (JSON statt
// Redirect/HTML) unterscheiden sich.

/** Kein Mitgliedskonto in dieser EEG -> einheitliche 403-JSON-Antwort (reiner Obmann-Account
 *  ohne eigene Mitgliedschaft hat keinen Zugriff auf die eigenen Vertrags-/Dokument-Routen). */
function requireAppMemberId(array $ctx): ?string
{
    if (!$ctx['member_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return null;
    }
    return $ctx['member_id'];
}

/** Vertragsstatus (Bezug/Einspeisung) für die App-Startseite "Verträge". */
$router->get('/api/v1/contracts/status', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $mps = DB::fetchAll('SELECT type FROM metering_points WHERE member_id = ? AND active = true', [$member['id']]);
    $hasConsumer = !empty(array_filter($mps, fn($mp) => $mp['type'] === 'consumer'));
    $hasProducer = !empty(array_filter($mps, fn($mp) => $mp['type'] === 'producer'));

    echo json_encode([
        'contracts_enabled' => contractsEnabled($member['community_id']),
        'bezug' => $hasConsumer ? [
            'status'    => $member['contract_bezug_status'] ?? 'none',
            'signed_at' => appDate($member['contract_bezug_signed_at'] ?? null),
        ] : null,
        'einspeisung' => $hasProducer ? [
            'status'    => $member['contract_einspeisung_status'] ?? 'none',
            'signed_at' => appDate($member['contract_einspeisung_signed_at'] ?? null),
        ] : null,
    ]);
});

/** Vertrags-PDF (aktueller Stand, ob nur versendet oder bereits signiert). Gleiche Vorlagen wie
 *  /portal/my/contract/bezug|einspeisung. */
$router->get('/api/v1/contracts/:type/pdf', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (denyDemoApiFileDownload($ctx)) return;
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Vertragstyp.']);
        return;
    }
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    if (!contractsEnabled($member['community_id'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Verträge sind in dieser EEG deaktiviert.']);
        return;
    }

    $mpType = $type === 'einspeisung' ? 'producer' : 'consumer';
    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], $mpType]);
    if (empty($mps)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode(['error' => 'Kein ' . ($type === 'einspeisung' ? 'Einspeise' : 'Bezugs') . '-Zählpunkt registriert.']);
        return;
    }

    $tariff = contractTariff($member['community_id'], $member['contract_' . $type . '_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_' . $type . '_customer_signature'] ?? null, $member['community_id']);

    if ($type === 'einspeisung') {
        $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
        streamLatexPdf('einspeisevereinbarung', $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
    } else {
        $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
        streamLatexPdf('bezugsvereinbarung', $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
    }
});

/**
 * Digitale Unterschrift durch das Mitglied in der App -- JSON-Äquivalent von
 * POST /portal/my/contract/:type/sign. Erwartet im Body {"zustimmung": true,
 * "signature_image": "data:image/png;base64,..."} (Unterschriftsfeld als PNG, gleiche
 * Validierung wie im Web-Portal).
 */
$router->post('/api/v1/contracts/:type/sign', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) {
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Vertragstyp.']);
        return;
    }
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status !== 'created') {
        http_response_code(400);
        echo json_encode(['error' => 'Vertrag kann in diesem Status nicht unterschrieben werden.']);
        return;
    }
    $body = jsonBody();
    if (empty($body['zustimmung'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte die Zustimmung bestätigen.']);
        return;
    }
    $signature = (string)($body['signature_image'] ?? '');
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Unterschrift.']);
        return;
    }

    DB::execute(
        "UPDATE members SET
            contract_{$type}_status = 'signed',
            contract_{$type}_customer_signature = ?,
            contract_{$type}_signed_at = now(),
            contract_{$type}_signer_ip = ?
         WHERE id = ?",
        [$signature, clientIp(), $member['id']]
    );
    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $ctx['community_id'],
            'vertrag_unterschrieben',
            'Vertrag digital unterschrieben: ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . contractTypeLabel($type) . ')',
            'Die/der Netzbenutzer:in hat die ' . contractTypeLabel($type) . ' in der App digital unterschrieben. '
            . 'Der Vertrag ist ab sofort gültig und wird automatisch sicher archiviert.',
            'member',
            $member['id'],
        ]
    );
    echo json_encode(['status' => 'ok']);
});

/** Eigene hochgeladene/vom Obmann hochgeladene Dateien (Ausweis-Scan, Beitrittserklärung, ...). */
$router->get('/api/v1/documents', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $files = DB::fetchAll('SELECT id, name, pfad, mime, created_at FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$ctx['member_id']]);
    echo json_encode(['documents' => array_map(fn($f) => [
        'id'         => $f['id'],
        'name'       => filenameWithExtension($f['name'], $f['pfad']),
        'mime'       => $f['mime'],
        'created_at' => appDate($f['created_at']),
    ], $files)]);
});

$router->get('/api/v1/documents/:fileid/download', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (denyDemoApiFileDownload($ctx)) return;
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $file = DB::fetchOne('SELECT * FROM member_files WHERE id = ? AND member_id = ?', [$params['fileid'], $ctx['member_id']]);
    if (!$file || !is_file($file['pfad'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden.']);
        return;
    }
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
});

/** DSGVO-Selbstauskunft (Art. 15/20 DSGVO) als JSON-Download -- identisch zu
 *  /portal/my/dsgvo-export, nur per Bearer-Token statt Session. */
$router->get('/api/v1/dsgvo-export', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (denyDemoApiFileDownload($ctx)) return;
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    logAudit($member['community_id'], 'dsgvo.export.self', 'member', $member['id'], 'Mitglied hat DSGVO-Selbstauskunft über die App exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? 'mitglied') . '.json');
});

// ─── Mitglieder-App: Support-Tickets ─────────────────────────────────────────
// Auth, Mitglieds-Prüfung und RLS-Kontext hier, Logik in App\Http\Controllers\Api\SupportApiController.
$supportApi = function (callable $action): void {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);
    $action($ctx)->send();
};
$router->get('/api/v1/support', fn() => $supportApi(fn($ctx) => Services::supportApiController()->index($ctx)));
$router->post('/api/v1/support', fn() => $supportApi(fn($ctx) => Services::supportApiController()->create($ctx, jsonBody())));
$router->get('/api/v1/support/:id', fn($params) => $supportApi(fn($ctx) => Services::supportApiController()->show($ctx, $params['id'])));
$router->post('/api/v1/support/:id/reply', fn($params) => $supportApi(fn($ctx) => Services::supportApiController()->reply($ctx, $params['id'], jsonBody())));

// ─── Mitglieder-App: Profil, Passwort, 2FA (Konto-Endpunkte, users-Tabelle) ──────────────────
// Anders als die obigen Routen (member_id nötig) funktionieren diese für JEDES Token -- Mitglied
// UND reiner Obmann-Account, siehe Kommentar zu app_sessions.user_id (migrate_20260831.sql).
$router->get('/api/v1/profile', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path, totp_enabled FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $hasPhoto = !empty($user['photo_path']);
    if ($ctx['member_id']) {
        DB::setCommunity($ctx['community_id']);
        $member = DB::fetchOne('SELECT photo_path FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
        $hasPhoto = !empty($member['photo_path'] ?? null);
    }

    echo json_encode([
        'user' => [
            'id'           => $user['id'],
            'email'        => $user['email'],
            'first_name'   => $user['first_name'],
            'last_name'    => $user['last_name'],
            'totp_enabled' => (bool)$user['totp_enabled'],
        ],
        'role'      => $ctx['role'],
        'has_photo' => $hasPhoto,
    ]);
});

$router->post('/api/v1/profile', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $email     = trim((string)($body['email'] ?? ''));
    $firstName = trim((string)($body['first_name'] ?? ''));
    $lastName  = trim((string)($body['last_name'] ?? ''));
    if (!$email || !$firstName || !$lastName) {
        http_response_code(400);
        echo json_encode(['error' => 'Alle Felder sind Pflichtfelder.']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige E-Mail-Adresse.']);
        return;
    }
    DB::execute('UPDATE users SET email=?, first_name=?, last_name=? WHERE id=?', [$email, $firstName, $lastName, $ctx['user_id']]);
    echo json_encode(['status' => 'ok']);
});

/** Profilbild-Upload (multipart/form-data, Feld "photo") -- hängt am Mitgliedsdatensatz, wenn
 *  vorhanden, sonst direkt am Login-Account (reiner Obmann-/Platform-Admin-Zugang). */
$router->post('/api/v1/profile/photo', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Bild übermittelt.']);
        return;
    }
    if ($ctx['member_id']) {
        DB::setCommunity($ctx['community_id']);
        $err = saveMemberPhoto($ctx['member_id'], $_FILES['photo']);
    } else {
        $err = saveUserPhoto($ctx['user_id'], $_FILES['photo']);
    }
    if ($err !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'Profilbild konnte nicht gespeichert werden.']);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

$router->post('/api/v1/password', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $body    = jsonBody();
    $current = (string)($body['current_password'] ?? '');
    $new     = (string)($body['new_password'] ?? '');
    $confirm = (string)($body['confirm_password'] ?? $new);

    if (!password_verify($current, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Aktuelles Passwort ist falsch.']);
        return;
    }
    if (strlen($new) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.']);
        return;
    }
    if ($new !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'Die Passwörter stimmen nicht überein.']);
        return;
    }
    if (isPasswordBreached($new)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.']);
        return;
    }
    $hash = hashPassword($new);
    DB::execute('UPDATE users SET password_hash=? WHERE id=?', [$hash, $ctx['user_id']]);
    echo json_encode(['status' => 'ok']);
});

/**
 * 2FA-Einrichtung starten: erzeugt ein neues TOTP-Secret. Da die App KEINE Server-Session hält
 * (bewusst stateless, siehe AppApiAuth), wird das Secret -- anders als im Web-Portal
 * ($_SESSION['2fa_setup_secret']) -- in einem kurzlebigen, signierten Ticket an den Client
 * zurückgegeben (gleiches Prinzip wie beim Community-Auswahl-Ticket im Login-Flow) und muss bei
 * POST /api/v1/2fa/enable wieder mitgeschickt werden.
 */
$router->get('/api/v1/2fa/setup', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT email FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $secret = totpGenerateSecret();
    $setupTicket = AppApiAuth::issueTicket('app_2fa_setup', ['uid' => $ctx['user_id'], 'secret' => $secret]);
    echo json_encode([
        'secret'       => $secret,
        'otpauth_uri'  => totpProvisioningUri($secret, $user['email'], 'Strom für alle'),
        'setup_ticket' => $setupTicket,
    ]);
});

$router->post('/api/v1/2fa/enable', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('app_2fa_setup', (string)($body['setup_ticket'] ?? ''));
    if (!$ticket || ($ticket['uid'] ?? null) !== $ctx['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Setup-Ticket ungültig oder abgelaufen. Bitte 2FA-Einrichtung erneut starten.']);
        return;
    }
    $secret = (string)($ticket['secret'] ?? '');
    if ($secret === '' || !totpVerify($secret, (string)($body['code'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'Der Code stimmt nicht. Bitte den aktuellen 6-stelligen Code eingeben.']);
        return;
    }
    DB::execute('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?', [encryptSecret($secret), 'true', $ctx['user_id']]);
    logAudit(null, 'user.2fa.enable', 'user', $ctx['user_id'], 'Zwei-Faktor-Authentifizierung (TOTP) über die App aktiviert');
    echo json_encode(['status' => 'ok']);
});

$router->post('/api/v1/2fa/disable', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::execute("UPDATE users SET totp_enabled = 'false', totp_secret = NULL WHERE id = ?", [$ctx['user_id']]);
    logAudit(null, 'user.2fa.disable', 'user', $ctx['user_id'], 'Zwei-Faktor-Authentifizierung (TOTP) über die App deaktiviert');
    echo json_encode(['status' => 'ok']);
});

// ─── Push-Benachrichtigungen (APNs, siehe Push.php + database/migrate_20260903.sql) ──────────
// Token-Registrierung ist rollenunabhängig (jeder angemeldete App-Zugang -- Mitglied, Obmann,
// Admin -- kann ein Gerät für Push registrieren), die Einstellungen (Rechnungs-Push an/aus,
// Einspeisung-Schwelle) gelten nur für Mitglieder (member_id nur bei role='member' gesetzt).

/** Registriert/aktualisiert ein Gerät für Push. Ein Gerät (device_token) kann jeweils nur EINEM
 *  Account zugeordnet sein -- meldet sich ein anderer Account auf demselben Gerät an (Geräte-
 *  wechsel/-weitergabe), übernimmt dieser Account den Token (UPSERT über die UNIQUE-Spalte). */
$router->post('/api/v1/push/register', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $deviceToken = trim((string)($body['device_token'] ?? ''));
    if ($deviceToken === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte device_token angeben.']);
        return;
    }
    $deviceLabel = trim((string)($body['device_label'] ?? '')) ?: null;

    DB::execute(
        'INSERT INTO app_push_tokens (user_id, role, device_token, device_label)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (device_token) DO UPDATE
         SET user_id = EXCLUDED.user_id, role = EXCLUDED.role, device_label = EXCLUDED.device_label,
             last_seen_at = now(), revoked_at = NULL',
        [$ctx['user_id'], $ctx['role'], $deviceToken, $deviceLabel]
    );
    echo json_encode(['status' => 'ok']);
});

/** Meldet ein Gerät wieder ab (z.B. Logout/Deinstallation) -- nur der eigene Account darf sein
 *  eigenes Gerät abmelden, kein globaler Zugriff über die device_token allein. */
$router->post('/api/v1/push/unregister', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $deviceToken = trim((string)($body['device_token'] ?? ''));
    if ($deviceToken === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte device_token angeben.']);
        return;
    }
    DB::execute(
        'UPDATE app_push_tokens SET revoked_at = now() WHERE device_token = ? AND user_id = ?',
        [$deviceToken, $ctx['user_id']]
    );
    echo json_encode(['status' => 'ok']);
});

/** Eigene Benachrichtigungs-Einstellungen (nur Mitglied-Rolle -- Obmann/Admin haben aktuell
 *  keine eigenen Einstellungen, ihr Postfach-Push ist immer an). */
$router->get('/api/v1/notifications/settings', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ctx['member_id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $s = DB::fetchOne('SELECT * FROM member_notification_settings WHERE member_id = ?', [$ctx['member_id']]);
    echo json_encode([
        'notify_new_invoice'      => $s ? (bool)$s['notify_new_invoice'] : true,
        'einspeisung_threshold_w' => $s['einspeisung_threshold_w'] ?? null,
    ]);
});

/** Setzt die eigenen Benachrichtigungs-Einstellungen. einspeisung_threshold_w = null/0 im
 *  Request deaktiviert die Einspeisung-Push (siehe Trigger-Bedingung "IF v_threshold IS NULL"
 *  in migrate_20260903.sql) -- ein neu gesetzter Schwellenwert startet bewusst mit
 *  einspeisung_above_threshold = false (Hysterese-Ausgangszustand), damit ein Wert, der schon
 *  jetzt über der neuen Schwelle liegt, beim nächsten Messwert normal auslöst statt als
 *  "schon oberhalb, keine erneute Benachrichtigung" übersprungen zu werden. */
$router->post('/api/v1/notifications/settings', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ctx['member_id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);

    $body = jsonBody();
    $notifyInvoice = array_key_exists('notify_new_invoice', $body) ? (bool)$body['notify_new_invoice'] : true;
    $thresholdRaw = $body['einspeisung_threshold_w'] ?? null;
    $threshold = ($thresholdRaw !== null && (int)$thresholdRaw > 0) ? (int)$thresholdRaw : null;

    DB::execute(
        'INSERT INTO member_notification_settings (member_id, notify_new_invoice, einspeisung_threshold_w, einspeisung_above_threshold)
         VALUES (?, ?, ?, false)
         ON CONFLICT (member_id) DO UPDATE
         SET notify_new_invoice = EXCLUDED.notify_new_invoice,
             einspeisung_threshold_w = EXCLUDED.einspeisung_threshold_w,
             einspeisung_above_threshold = false,
             updated_at = now()',
        [$ctx['member_id'], $notifyInvoice, $threshold]
    );
    echo json_encode(['status' => 'ok']);
});

// ─── Mitglieder-App: Obmann-Endpunkte (Mitgliederverwaltung von unterwegs) ───────────────────
// Nur mit role='manager'-Token (AppApiAuth::requireManagerAuth()). Anders als
// requireMemberAccess() im Web-Portal ist hier KEIN Platform-Admin-"jede Community
// durchprobieren"-Fall nötig: der Manager-Token trägt die Community bereits fest (bei mehreren
// EEGs wählt der Login-Flow eine davon aus, siehe /api/v1/login/select-community) -- entspricht
// exakt dem "sonst"-Zweig von requireMemberAccess().
function requireAppManagedMember(string $communityId, string $memberId): ?array
{
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return null;
    }
    return $member;
}

/** Mitgliederliste der eigenen EEG -- leichtere Variante der /portal/members-Tabelle (ohne
 *  ESP-Fehlerstatus/Sidebar-Badges, die reichen fürs Web-Portal, nicht für eine App-Liste). */
$router->get('/api/v1/manager/members', function () {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $members = DB::fetchAll(
        "SELECT m.id, m.kundennummer, m.salutation, m.titel_vor, m.first_name, m.last_name, m.company_name,
                m.email, m.phone, m.city, m.member_since, m.member_until,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                COALESCE(
                    (SELECT SUM(i.saldo_eur) FROM invoices i
                     WHERE i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL),
                    0
                ) AS open_amount
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$ctx['community_id']]
    );

    echo json_encode(['members' => array_map(fn($m) => [
        'id'                    => $m['id'],
        'kundennummer'          => $m['kundennummer'],
        'name'                  => trim($m['first_name'] . ' ' . $m['last_name']),
        'company_name'          => $m['company_name'],
        'email'                 => $m['email'],
        'phone'                 => $m['phone'],
        'city'                  => $m['city'],
        'member_since'          => appDate($m['member_since']),
        'member_until'          => appDate($m['member_until']),
        'metering_point_count'  => (int)$m['metering_point_count'],
        'open_amount_eur'       => (float)$m['open_amount'],
    ], $members)]);
});

/** Mitglied-Detail inkl. Zählpunkten und Dateien (Obmann-Ansicht in der App). */
$router->get('/api/v1/manager/members/:id', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $meteringPoints = DB::fetchAll('SELECT id, zaehlpunkt_nr, type, active, registered_at FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$member['id']]);
    $files = DB::fetchAll('SELECT id, name, pfad, mime, created_at FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$member['id']]);

    echo json_encode([
        'member' => [
            'id'               => $member['id'],
            'kundennummer'     => $member['kundennummer'],
            'salutation'       => $member['salutation'],
            'titel_vor'        => $member['titel_vor'],
            'titel_nach'       => $member['titel_nach'],
            'first_name'       => $member['first_name'],
            'last_name'        => $member['last_name'],
            'company_name'     => $member['company_name'],
            'address'          => $member['address'],
            'zip'              => $member['zip'],
            'city'             => $member['city'],
            'email'            => $member['email'],
            'phone'            => $member['phone'],
            'invoice_uid'      => $member['invoice_uid'],
            'member_iban'      => $member['member_iban'],
            'member_bic'       => $member['member_bic'],
            'member_since'     => appDate($member['member_since']),
            'member_until'     => appDate($member['member_until']),
            'geburtsdatum'     => appDate($member['geburtsdatum']),
            'stromlieferant'   => $member['stromlieferant'],
        ],
        'metering_points' => array_map(fn($p) => [
            'id'            => $p['id'],
            'zaehlpunkt_nr' => $p['zaehlpunkt_nr'],
            'type'          => $p['type'],
            'active'        => (bool)$p['active'],
            'registered_at' => appDate($p['registered_at']),
        ], $meteringPoints),
        'files' => array_map(fn($f) => [
            'id'         => $f['id'],
            'name'       => filenameWithExtension($f['name'], $f['pfad']),
            'mime'       => $f['mime'],
            'created_at' => appDate($f['created_at']),
        ], $files),
    ]);
});

/**
 * Legt ein neues Mitglied an -- JSON-Äquivalent von POST /portal/members. Erwartet dieselben
 * Pflichtfelder (first_name, last_name, email, address, zip, city) sowie die sechs rechtlichen
 * Zustimmungen (zustimmung_*, siehe $consentFields unten) im JSON-Body; optionale
 * Zählpunkt-Felder wie im Web-Formular (add_bezug_zp/add_einspeisung_zp + *_zaehlpunkt_nr).
 * Nutzt dieselbe createMemberRecord()-Logik wie das Web-Portal (KdNr-Vergabe, Erstlogin-Mail).
 */
$router->post('/api/v1/manager/members', function () {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);
    $communityId = $ctx['community_id'];

    $body = jsonBody();

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim((string)($body[$f] ?? '')))) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle Pflichtfelder ausfüllen.']);
            return;
        }
    }

    $iban = trim((string)($body['member_iban'] ?? ''));
    if ($iban !== '' && !validateIban($iban)) {
        http_response_code(400);
        echo json_encode(['error' => 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).']);
        return;
    }

    $znrBezugNew = null;
    $znrEinspNew = null;
    if (!empty($body['add_bezug_zp'])) {
        $znrBezugNew = strtoupper(trim((string)($body['bezug_zaehlpunkt_nr'] ?? '')));
        if ($znrBezugNew === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte die Zählpunktnummer für den Bezugs-Zählpunkt angeben (oder add_bezug_zp weglassen).']);
            return;
        }
    }
    if (!empty($body['add_einspeisung_zp'])) {
        $znrEinspNew = strtoupper(trim((string)($body['einspeisung_zaehlpunkt_nr'] ?? '')));
        if ($znrEinspNew === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte die Zählpunktnummer für den Einspeise-Zählpunkt angeben (oder add_einspeisung_zp weglassen).']);
            return;
        }
    }
    if ($znrBezugNew && $znrEinspNew && $znrBezugNew === $znrEinspNew) {
        http_response_code(400);
        echo json_encode(['error' => 'Bezugs- und Einspeise-Zählpunkt dürfen nicht dieselbe Zählpunktnummer haben.']);
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
            http_response_code(400);
            echo json_encode(['error' => 'Die Zählpunktnummer ' . $znrToCheck . ' ist bereits vergeben — an '
                . $znrOwner['first_name'] . ' ' . $znrOwner['last_name'] . ' (KdNr ' . ($znrOwner['kundennummer'] ?? '—') . ').']);
            return;
        }
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($body[$cf])) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle sechs rechtlichen Zustimmungen bestätigen, bevor das Mitglied angelegt wird.']);
            return;
        }
    }

    $result = createMemberRecord($communityId, array_merge($body, ['andere_eeg' => !empty($body['andere_eeg'])]));
    logAudit($communityId, 'member.create', 'member', $result['member_id'],
        'Mitglied ' . trim((string)$body['first_name']) . ' ' . trim((string)$body['last_name']) . ' über die App angelegt (KdNr ' . $result['kundennummer'] . ')');

    if ($znrBezugNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrBezugNew, 'consumer', [
            'meter_code'          => trim((string)($body['bezug_meter_code'] ?? '')),
            'jahresverbrauch_kwh' => $body['bezug_jahresverbrauch_kwh'] ?? '',
        ]);
    }
    if ($znrEinspNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrEinspNew, 'producer', [
            'meter_code'               => trim((string)($body['einspeisung_meter_code'] ?? '')),
            'engpassleistung_kw'       => $body['einspeisung_engpassleistung_kw'] ?? '',
            'geplante_einspeisung_kwh' => $body['einspeisung_geplante_einspeisung_kwh'] ?? '',
        ]);
    }

    echo json_encode([
        'status'        => 'ok',
        'member_id'     => $result['member_id'],
        'kundennummer'  => $result['kundennummer'],
        'invite_sent'   => $result['invite_sent'],
        'temp_password' => $result['temp_password'],
    ]);
});

/** Bearbeitet Stammdaten eines Mitglieds -- JSON-Äquivalent von POST /portal/members/:id/edit. */
$router->post('/api/v1/manager/members/:id', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $body = jsonBody();
    $required = ['first_name', 'last_name', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim((string)($body[$f] ?? '')))) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle Pflichtfelder ausfüllen.']);
            return;
        }
    }

    $iban = trim((string)($body['member_iban'] ?? ''));
    if ($iban !== '' && !validateIban($iban)) {
        http_response_code(400);
        echo json_encode(['error' => 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).']);
        return;
    }

    $mandatsreferenz = $member['mandatsreferenz'];
    if ($iban !== '' && empty($mandatsreferenz)) {
        $mandatsreferenz = 'S00000F' . date('Y') . 'A' . $member['kundennummer'];
    }

    DB::execute(
        'UPDATE members SET salutation=?, titel_vor=?, titel_nach=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, kontoinhaber=?, konto_adresse=?, mandatsreferenz=?,
         member_since=?, member_until=?,
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?,
         email_anrede_mode=?
         WHERE id=?',
        [
            $body['salutation'] ?? null,
            trim((string)($body['titel_vor'] ?? '')) ?: null,
            trim((string)($body['titel_nach'] ?? '')) ?: null,
            trim((string)$body['first_name']),
            trim((string)$body['last_name']),
            trim((string)($body['company_name'] ?? '')) ?: null,
            trim((string)$body['address']),
            trim((string)$body['zip']),
            trim((string)$body['city']),
            trim((string)($body['phone'] ?? '')) ?: null,
            trim((string)($body['invoice_uid'] ?? '')) ?: null,
            $iban ?: null,
            trim((string)($body['member_bic'] ?? '')) ?: null,
            trim((string)($body['kontoinhaber'] ?? '')) ?: null,
            trim((string)($body['konto_adresse'] ?? '')) ?: null,
            $mandatsreferenz,
            $body['member_since'] ?: date('Y-m-d'),
            ($body['member_until'] ?? '') ?: '2099-12-31',
            ($body['geburtsdatum'] ?? '') ?: null,
            trim((string)($body['stromlieferant'] ?? '')) ?: null,
            ($body['speicher_status'] ?? '') ?: null,
            ($body['speicher_kwh'] ?? '') !== '' ? (float)$body['speicher_kwh'] : null,
            !empty($body['andere_eeg']) ? 'true' : 'false',
            trim((string)($body['andere_eeg_name'] ?? '')) ?: null,
            in_array($body['email_anrede_mode'] ?? 'auto', ['auto', 'herr', 'frau', 'familie'], true) ? ($body['email_anrede_mode'] ?? 'auto') : 'auto',
            $params['id'],
        ]
    );
    $memberAfter = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    $memberChanges = auditDiff($member, $memberAfter ?? [], [
        'salutation' => 'Anrede', 'titel_vor' => 'Titel (vorgestellt)', 'titel_nach' => 'Titel (nachgestellt)',
        'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'company_name' => 'Firma', 'address' => 'Adresse', 'zip' => 'PLZ', 'city' => 'Ort', 'phone' => 'Telefon',
        'invoice_uid' => 'UID', 'member_iban' => 'IBAN', 'member_bic' => 'BIC', 'kontoinhaber' => 'Kontoinhaber',
        'konto_adresse' => 'Konto-Adresse', 'mandatsreferenz' => 'Mandatsreferenz', 'member_since' => 'Mitglied seit',
        'member_until' => 'Mitglied bis', 'geburtsdatum' => 'Geburtsdatum', 'stromlieferant' => 'Stromlieferant',
        'speicher_status' => 'Speicher', 'speicher_kwh' => 'Speicher kWh', 'andere_eeg' => 'Andere EEG',
        'andere_eeg_name' => 'Andere-EEG-Name', 'email_anrede_mode' => 'E-Mail-Anrede-Modus',
    ]);
    $memberName = trim(($memberAfter['first_name'] ?? '') . ' ' . ($memberAfter['last_name'] ?? ''));
    if (!empty($memberChanges)) {
        logAuditDiff($member['community_id'], 'member.update', 'member', $params['id'], $memberChanges,
            'Mitglied ' . $memberName . ' (App):');
    } else {
        logAudit($member['community_id'], 'member.update', 'member', $params['id'],
            'Mitglied ' . $memberName . ' über die App gespeichert (keine Änderung)');
    }
    echo json_encode(['status' => 'ok']);
});

/**
 * Datei-Upload für ein Mitglied (Ausweis-Scan, Beitrittserklärung, ...) -- multipart/form-data
 * mit Feld "file" (+ optionalem Feld "name" für die Anzeige-Bezeichnung), JSON-Äquivalent von
 * POST /portal/members/:id/files. Standard-multipart statt Base64-in-JSON, damit sich iOS'
 * URLSession-Multipart-Upload direkt nutzen lässt (kein 33% Base64-Overhead bei z.B. einem
 * mehrere MB großen Ausweis-Scan-Foto).
 */
$router->post('/api/v1/manager/members/:id/files', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Datei-Upload gefunden.']);
        return;
    }

    $displayName = trim((string)($_POST['name'] ?? '')) ?: basename($_FILES['file']['name']);
    $origExt = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $dir = '/var/www/html/storage/uploads/members/' . $params['id'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $storedName = bin2hex(random_bytes(16)) . ($origExt ? '.' . strtolower($origExt) : '');
    $destPath = $dir . '/' . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht gespeichert werden.']);
        return;
    }

    try {
        $sha256 = hash_file('sha256', $destPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Datei konnte nach dem Upload nicht gelesen werden (sha256 fehlgeschlagen).');
        }
        $file = DB::fetchOne(
            'INSERT INTO member_files (community_id, member_id, name, pfad, mime, sha256, hochgeladen_von)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $member['community_id'],
                $params['id'],
                $displayName,
                $destPath,
                $_FILES['file']['type'] ?: null,
                $sha256,
                $ctx['user_id'],
            ]
        );
    } catch (\Throwable $e) {
        unlink($destPath);
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht in der Datenbank gespeichert werden.']);
        return;
    }

    echo json_encode(['status' => 'ok', 'id' => $file['id']]);
});

$router->get('/api/v1/manager/members/:id/files/:fileid/download', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    if (denyDemoApiFileDownload($ctx)) return;
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ? AND community_id = ?',
        [$params['fileid'], $params['id'], $member['community_id']]
    );
    if (!$file || !is_file($file['pfad'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden.']);
        return;
    }
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
});

/** Profilbild eines Mitglieds setzen (Obmann-Aktion, multipart-Feld "photo"). */
$router->post('/api/v1/manager/members/:id/photo', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    if (!isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Bild übermittelt.']);
        return;
    }
    $err = saveMemberPhoto($params['id'], $_FILES['photo']);
    if ($err !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'Profilbild konnte nicht gespeichert werden.']);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

// ─── Mitglieder-App: Plattform-Admin-Endpunkte (role: admin, seit migrate_20260902.sql) ──────
// JSON-Äquivalente der /admin/*-Web-Routen -- wirken plattformweit über ALLE EEGs hinweg, nicht
// auf eine einzelne Community beschränkt (der Token trägt deshalb kein community_id). Sensible
// Werte (Client-Secret, MQTT-Passwort, EDA-Portal-Passwort) werden wie im Web NIE im Klartext
// zurückgegeben (nur ob gesetzt), Update-Felder folgen demselben "leer lassen = unverändert"-
// Prinzip wie /admin/mail-settings im Web (siehe $keep() dort).

/** Übersicht: alle EEGs + Nutzerzahl -- JSON-Äquivalent von GET /admin. */
$router->get('/api/v1/admin/overview', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $communities = DB::fetchAll('SELECT id, name, active, marktpartner_id FROM communities ORDER BY name');
    $userCount = (int)DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];

    echo json_encode([
        'communities' => array_map(fn($c) => [
            'id'              => $c['id'],
            'name'            => $c['name'],
            'active'          => (bool)$c['active'],
            'marktpartner_id' => $c['marktpartner_id'],
        ], $communities),
        'user_count' => $userCount,
    ]);
});

/** Alle Nutzer + ihre Rollen -- JSON-Äquivalent des Nutzer-Teils von GET /admin. */
$router->get('/api/v1/admin/users', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $users = DB::fetchAll('SELECT id, email, first_name, last_name, active FROM users ORDER BY last_name, first_name');
    $allRoles = DB::fetchAll('SELECT ur.user_id, ur.role, ur.community_id, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }

    echo json_encode(['users' => array_map(fn($u) => [
        'id'         => $u['id'],
        'email'      => $u['email'],
        'name'       => trim($u['first_name'] . ' ' . $u['last_name']),
        'active'     => (bool)$u['active'],
        'roles'      => array_map(fn($r) => [
            'role'           => $r['role'],
            'community_id'   => $r['community_id'],
            'community_name' => $r['community_name'],
        ], $roleMap[$u['id']] ?? []),
    ], $users)]);
});

/** Detail eines Nutzers inkl. Rollen -- JSON-Äquivalent von GET /admin/users/:id. */
$router->get('/api/v1/admin/users/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT id, email, first_name, last_name, active FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Nutzer nicht gefunden.']); return; }
    $roles = DB::fetchAll('SELECT ur.id, ur.role, ur.community_id, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);

    echo json_encode([
        'user' => [
            'id'         => $user['id'],
            'email'      => $user['email'],
            'name'       => trim($user['first_name'] . ' ' . $user['last_name']),
            'active'     => (bool)$user['active'],
        ],
        'roles' => array_map(fn($r) => [
            'role_id'        => $r['id'],
            'role'           => $r['role'],
            'community_id'   => $r['community_id'],
            'community_name' => $r['community_name'],
        ], $roles),
    ]);
});

/** Fügt einem Nutzer eine Rolle hinzu -- JSON-Äquivalent von POST /admin/users/:id/roles. */
$router->post('/api/v1/admin/users/:id/roles', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $role = (string)($body['role'] ?? '');
    if (!in_array($role, ['platform_admin', 'manager', 'member'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Rolle.']);
        return;
    }
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role]
    );
    logAudit($communityId, 'user_role.add', 'user', $params['id'], "Rolle \"$role\" hinzugefügt.");
    echo json_encode(['status' => 'ok']);
});

/** Entfernt eine Rollenzuweisung -- JSON-Äquivalent von POST /admin/users/:id/roles/delete. */
$router->post('/api/v1/admin/users/:id/roles/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $roleId = (string)($body['role_id'] ?? '');
    // Es muss immer mindestens eine platform_admin-Rolle übrig bleiben, sonst kann sich niemand
    // mehr als Admin einloggen (weder web noch app) -- exakt dieselbe Schutzregel wie im Web.
    $isLastPlatformAdminRole = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$roleId]
    );
    if ($isLastPlatformAdminRole) {
        http_response_code(400);
        echo json_encode(['error' => 'Dies ist die letzte verbleibende Plattform-Admin-Rolle und kann nicht entfernt werden.']);
        return;
    }
    DB::execute('DELETE FROM user_roles WHERE id = ?', [$roleId]);
    logAudit(null, 'user_role.remove', 'user', $params['id'], 'Rollenzuweisung entfernt.');
    echo json_encode(['status' => 'ok']);
});

/** Löscht einen Login-Account -- JSON-Äquivalent von POST /admin/users/:id/delete. */
$router->post('/api/v1/admin/users/:id/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    if ($params['id'] === $ctx['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Der eigene Account kann nicht gelöscht werden.']);
        return;
    }
    $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Nutzer nicht gefunden.']); return; }

    $isLastPlatformAdmin = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$params['id']]
    );
    if ($isLastPlatformAdmin) {
        http_response_code(400);
        echo json_encode(['error' => 'Dieser Account ist der letzte verbleibende Plattform-Admin und kann nicht gelöscht werden.']);
        return;
    }
    DB::execute('DELETE FROM users WHERE id = ?', [$params['id']]);
    logAudit(null, 'user.delete', 'user', $params['id'], 'Login-Account gelöscht.');
    echo json_encode(['status' => 'ok']);
});

/** Detail einer EEG inkl. Mitgliederliste -- JSON-Äquivalent von GET /admin/communities/:id. */
$router->get('/api/v1/admin/communities/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    // members hat Row-Level Security -- Ziel-Community ist hier schon aus der URL bekannt, kein
    // Henne-Ei-Problem wie z.B. bei requireMemberAccess(), einfach direkt setzen.
    DB::setCommunity($params['id']);
    $members = DB::fetchAll(
        'SELECT m.id, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email, m.status
         FROM members m WHERE m.community_id = ?
         ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name',
        [$params['id']]
    );

    echo json_encode([
        'community' => [
            'id'                 => $community['id'],
            'name'               => $community['name'],
            'slug'               => $community['slug'],
            'marktpartner_id'    => $community['marktpartner_id'],
            'zvr_number'         => $community['zvr_number'],
            'address'            => $community['address'],
            'iban'               => $community['iban'],
            'bic'                => $community['bic'],
            'active'             => (bool)$community['active'],
            'eda_login_email'    => $community['eda_login_email'],
            'eda_login_password_set' => !empty($community['eda_login_password_enc']),
            'uses_ausleseeinheit' => communityUsesAusleseeinheit($community),
        ],
        'members' => array_map(fn($m) => [
            'id'            => $m['id'],
            'kundennummer'  => $m['kundennummer'],
            'name'          => trim($m['first_name'] . ' ' . $m['last_name']),
            'company_name'  => $m['company_name'],
            'email'         => $m['email'],
            'status'        => $m['status'],
        ], $members),
    ]);
});

/** Legt eine neue EEG an -- JSON-Äquivalent von POST /admin/communities. */
$router->post('/api/v1/admin/communities', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte einen Namen angeben.']);
        return;
    }
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $community = DB::fetchOne(
        'INSERT INTO communities (name, slug, marktpartner_id, address, uses_ausleseeinheit) VALUES (?, ?, ?, ?, ?) RETURNING id',
        [$name, $slug, $body['marktpartner_id'] ?? null, $body['address'] ?? null,
         array_key_exists('uses_ausleseeinheit', $body) && !$body['uses_ausleseeinheit'] ? 'false' : 'true']
    );
    logAudit(null, 'community.create', 'community', $community['id'], 'EEG "' . $name . '" angelegt.');
    echo json_encode(['status' => 'ok', 'id' => $community['id']]);
});

/** Bearbeitet eine EEG -- JSON-Äquivalent von POST /admin/communities/:id. */
$router->post('/api/v1/admin/communities/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT eda_login_password_enc, uses_ausleseeinheit FROM communities WHERE id = ?', [$params['id']]);
    if (!$current) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    // EDA-Portal-Passwort nur überschreiben, wenn tatsächlich ein neues mitgeschickt wurde --
    // gleiches "leer = unverändert"-Prinzip wie im Web (siehe /admin/communities/:id).
    $newEdaPassword = trim((string)($body['eda_login_password'] ?? ''));
    $edaPasswordEnc = $newEdaPassword !== '' ? encryptSecret($newEdaPassword) : ($current['eda_login_password_enc'] ?? null);

    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=?,
             eda_login_email=?, eda_login_password_enc=?, uses_ausleseeinheit=? WHERE id=?',
        [
            trim((string)($body['name'] ?? '')),
            trim((string)($body['marktpartner_id'] ?? '')) ?: null,
            trim((string)($body['zvr_number'] ?? '')) ?: null,
            trim((string)($body['address'] ?? '')) ?: null,
            trim((string)($body['iban'] ?? '')) ?: null,
            trim((string)($body['bic'] ?? '')) ?: null,
            !empty($body['active']) ? 'true' : 'false',
            trim((string)($body['eda_login_email'] ?? '')) ?: null,
            $edaPasswordEnc,
            // Feld fehlt (ältere App-Version) = bisherigen Wert behalten
            array_key_exists('uses_ausleseeinheit', $body)
                ? (!empty($body['uses_ausleseeinheit']) ? 'true' : 'false')
                : (communityUsesAusleseeinheit($current) ? 'true' : 'false'),
            $params['id'],
        ]
    );
    logAudit($params['id'], 'community.update', 'community', $params['id'], 'EEG "' . trim((string)($body['name'] ?? '')) . '" bearbeitet.');
    echo json_encode(['status' => 'ok']);
});

/**
 * Löscht eine EEG UNWIDERRUFLICH inkl. aller Mitglieder/Verträge/Zählpunkte/Rechnungen
 * (ON DELETE CASCADE) -- JSON-Äquivalent von POST /admin/communities/:id/delete. Die App MUSS
 * vor diesem Aufruf eine eigene, deutliche Bestätigung einholen (z. B. Name der EEG erneut
 * eintippen lassen), genau wie destruktive Aktionen im Web-Portal ein JS-confirm() haben.
 */
$router->post('/api/v1/admin/communities/:id/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $community = DB::fetchOne('SELECT id, name FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    DB::execute('DELETE FROM communities WHERE id = ?', [$params['id']]);
    logAudit(null, 'community.delete', 'community', $params['id'],
        'EEG "' . $community['name'] . '" (ID ' . $community['id'] . ') endgültig gelöscht inkl. aller Mitglieder, Verträge, Zählpunkte und Rechnungen.');
    echo json_encode(['status' => 'ok']);
});

/** Aktivitätslog, optional gefiltert nach einer EEG -- JSON-Äquivalent von GET /admin/log
 *  (ohne das Markdown-Export-Pendant -- siehe APP_PARITY_BACKLOG.md). */
$router->get('/api/v1/admin/log', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $filterCommunity = $_GET['community_id'] ?? '';
    $params = []; $where = '1=1';
    if ($filterCommunity !== '') { $where .= ' AND al.community_id = ?'; $params[] = $filterCommunity; }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where ORDER BY al.created_at DESC LIMIT 500",
        $params
    );
    $entries = demoMaskAuditLog($entries, $ctx['is_demo'] ?? false);
    echo json_encode(['entries' => array_map(fn($e) => [
        'id'             => $e['id'],
        'created_at'     => appDate($e['created_at']),
        'user_name'      => trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['email'] ?? 'System'),
        'community_name' => $e['community_name'],
        'aktion'         => $e['aktion'],
        'entity_typ'     => $e['entity_typ'],
        'entity_id'      => $e['entity_id'],
        'beschreibung'   => $e['beschreibung'],
        'ist_fehler'     => (bool)$e['ist_fehler'],
    ], $entries)]);
});

/**
 * Gesammelte Plattform-Einstellungen (Mail/Microsoft Graph, Mail-Vorlagen, MQTT, Plattform-
 * Technik) in EINER Antwort -- JSON-Äquivalent der kombinierten Seite GET /admin/mail-settings.
 * Secrets (client_secret, mqtt_password, eda-Passwort) werden NIE im Klartext zurückgegeben,
 * nur als "..._set": true/false, exakt wie das Web-Formular sie nie vorbefüllt.
 */
$router->get('/api/v1/admin/settings', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $mail = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $templates = DB::fetchAll('SELECT key, subject, body_html, updated_at FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    try { $mqtt = DB::fetchOne('SELECT * FROM platform_mqtt_config WHERE id = 1'); } catch (\Throwable $e) { $mqtt = null; }
    try { $apns = DB::fetchOne('SELECT * FROM platform_apns_config WHERE id = 1'); } catch (\Throwable $e) { $apns = null; }

    echo json_encode([
        'mail' => $mail ? [
            'tenant_id'                   => $mail['tenant_id'],
            'client_id'                   => $mail['client_id'],
            'client_secret_set'           => !empty($mail['client_secret']),
            'sender_address'              => $mail['sender_address'],
            'reply_to'                    => $mail['reply_to'],
            'signature_html'              => $mail['signature_html'],
            'signature_logo_set'          => !empty($mail['signature_logo_base64']),
            'backup_alert_email_1'        => $mail['backup_alert_email_1'],
            'backup_alert_email_2'        => $mail['backup_alert_email_2'],
            'support_notification_email'  => $mail['support_notification_email'],
            'eda_import_mailbox_address'  => $mail['eda_import_mailbox_address'],
        ] : null,
        'mail_templates' => array_map(fn($t) => [
            'key'        => $t['key'],
            'subject'    => $t['subject'],
            'body_html'  => $t['body_html'],
            'updated_at' => appDate($t['updated_at']),
        ], $templates),
        'platform' => $platformSettings ? [
            'test_mode'                  => !empty($platformSettings['test_mode']),
            'esp_offline_after_minutes'  => (int)($platformSettings['esp_offline_after_minutes'] ?? 5),
            'portal_design'              => $platformSettings['portal_design'] ?? 'klassisch',
        ] : null,
        'mqtt' => $mqtt ? [
            'mqtt_user'          => $mqtt['mqtt_user'],
            'mqtt_password_set'  => !empty($mqtt['mqtt_password']),
            'pending_apply'      => !empty($mqtt['pending_apply']),
            'applied_at'         => appDate($mqtt['applied_at'] ?? null),
        ] : null,
        'apns' => $apns ? [
            'team_id'         => $apns['team_id'],
            'key_id'          => $apns['key_id'],
            'bundle_id'       => $apns['bundle_id'],
            'private_key_set' => !empty($apns['private_key_enc']),
            'sandbox'         => (bool)$apns['sandbox'],
            'configured'      => Push::isConfigured(),
        ] : null,
    ]);
});

/** Aktualisiert die Mail-/Microsoft-Graph-Konfiguration -- JSON-Äquivalent von
 *  POST /admin/mail-settings (Logo-Upload bewusst NICHT enthalten, siehe APP_PARITY_BACKLOG.md). */
$router->post('/api/v1/admin/settings/mail', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $newSecret = trim((string)($body['client_secret'] ?? ''));
    $clientSecret = $newSecret !== '' ? $newSecret : ($current['client_secret'] ?? null);
    // "Feld war im Request gar nicht dabei" (unverändert) von "Feld war dabei, aber leer"
    // (wirklich löschen) unterscheiden -- exakt dasselbe Prinzip wie $keep() im Web (siehe
    // Kommentar dort zum Vorfall 13.08.2026, als das Fehlen dieser Unterscheidung
    // Zugangsdaten/Signatur bei jedem Speichern gelöscht hat).
    $keep = function (string $key) use ($body, $current) {
        return array_key_exists($key, $body) ? (trim((string)$body[$key]) ?: null) : ($current[$key] ?? null);
    };
    $supportEmail = array_key_exists('support_notification_email', $body)
        ? (trim((string)$body['support_notification_email']) ?: AppConfig::get()->contactEmail)
        : ($current['support_notification_email'] ?? AppConfig::get()->contactEmail);

    DB::execute(
        'UPDATE platform_mail_config
         SET tenant_id = ?, client_id = ?, client_secret = ?, sender_address = ?, reply_to = ?, signature_html = ?,
             backup_alert_email_1 = ?, backup_alert_email_2 = ?, support_notification_email = ?,
             eda_import_mailbox_address = ?, updated_at = now()
         WHERE id = 1',
        [
            $keep('tenant_id'), $keep('client_id'), $clientSecret, $keep('sender_address'), $keep('reply_to'),
            $keep('signature_html'), $keep('backup_alert_email_1'), $keep('backup_alert_email_2'),
            $supportEmail, $keep('eda_import_mailbox_address'),
        ]
    );
    logAudit(null, 'mail_config.update', 'platform_mail_config', '1', 'Mail-Konfiguration über die App geändert.');
    echo json_encode(['status' => 'ok']);
});

/** Sendet eine Test-Mail mit der aktuellen Konfiguration -- JSON-Äquivalent von
 *  POST /admin/mail-settings/test. */
$router->post('/api/v1/admin/settings/mail/test', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $to = trim((string)($body['to'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        return;
    }
    try {
        Mailer::send($to, 'Test-Mail von Strom für alle', '<p>Diese Test-Mail bestätigt, dass die Mail-Konfiguration funktioniert.</p>');
        logAudit(null, 'mail_config.test', null, null, 'Test-Mail an ' . $to . ' über die App versendet.');
        echo json_encode(['status' => 'ok']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen: ' . $e->getMessage()]);
    }
});

/** Bearbeitet eine einzelne E-Mail-Vorlage -- JSON-Äquivalent von POST /admin/mail-templates. */
$router->post('/api/v1/admin/settings/mail-templates', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $key = (string)($body['key'] ?? '');
    if (!in_array($key, ['password_reset', 'invite', 'member_deactivated', 'contract_bezug', 'contract_einspeisung', 'contract_both', 'sepa_prenotification', 'mahnung', 'invoice_released'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Vorlage.']);
        return;
    }
    DB::execute(
        'UPDATE platform_mail_templates SET subject = ?, body_html = ?, updated_at = now() WHERE key = ?',
        [trim((string)($body['subject'] ?? '')), (string)($body['body_html'] ?? ''), $key]
    );
    logAudit(null, 'mail_template.update', 'platform_mail_templates', $key, 'E-Mail-Vorlage „' . $key . '" über die App bearbeitet.');
    echo json_encode(['status' => 'ok']);
});

/** Setzt die MQTT-Zugangsdaten (Anwendung auf den Broker läuft asynchron über den Host-Cron,
 *  siehe scripts/mqtt_apply_pending.sh) -- JSON-Äquivalent von POST /admin/mqtt-settings. */
$router->post('/api/v1/admin/settings/mqtt', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $mqttUser = trim((string)($body['mqtt_user'] ?? '')) ?: 'eeg-device';
    $mqttPassword = trim((string)($body['mqtt_password'] ?? ''));
    if ($mqttPassword === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte ein Passwort angeben.']);
        return;
    }
    DB::execute(
        'UPDATE platform_mqtt_config SET mqtt_user = ?, mqtt_password = ?, pending_apply = true, updated_at = now() WHERE id = 1',
        [$mqttUser, $mqttPassword]
    );
    logAudit(null, 'mqtt_config.update', 'platform_mqtt_config', '1', 'MQTT-Zugangsdaten über die App geändert (Benutzer: ' . $mqttUser . ').');
    echo json_encode(['status' => 'ok']);
});

/** Broadcastet neue MQTT-Zugangsdaten an ALLE Geräte im Feld -- JSON-Äquivalent von
 *  POST /admin/mqtt-device-reconfig. */
$router->post('/api/v1/admin/settings/mqtt-device-reconfig', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $host = trim((string)($body['device_mqtt_host'] ?? ''));
    $port = (int)($body['device_mqtt_port'] ?? 0);
    $user = trim((string)($body['device_mqtt_user'] ?? ''));
    $pass = (string)($body['device_mqtt_pass'] ?? '');
    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte Host, Port, Benutzername und Passwort angeben.']);
        return;
    }
    $payload = json_encode(['mqtt_host' => $host, 'mqtt_port' => $port, 'mqtt_user' => $user, 'mqtt_pass' => $pass]);
    DB::execute(
        "UPDATE platform_mqtt_config SET device_reconfig_payload = ?, device_reconfig_requested_at = now() WHERE id = 1",
        [$payload]
    );
    logAudit(null, 'mqtt_config.device_reconfig', 'platform_mqtt_config', '1',
        "MQTT-Fernkonfiguration über die App an alle Geräte angestoßen (Host: $host:$port, Benutzer: $user).");
    echo json_encode(['status' => 'ok']);
});

/**
 * Setzt die APNs-Zugangsdaten für Push-Benachrichtigungen (Team-ID/Key-ID/Bundle-ID/.p8-Auth-Key
 * aus Patricks Apple-Developer-Account -- ohne diese echten Zugangsdaten bleibt
 * push_notifications_queue liegen, siehe Push::sendPending()). Kein Web-Portal-Äquivalent
 * (bewusst nur über die App/API eingerichtet, kein Formular in admin_mail_settings.php).
 * private_key erwartet den kompletten .p8-Dateiinhalt (PEM, inkl. BEGIN/END-Zeilen) und wird wie
 * andere Secrets in diesem Projekt (WLAN-Passwörter, EDA-Zugangsdaten) mit encryptSecret()
 * verschlüsselt abgelegt, nie im Klartext.
 */
$router->post('/api/v1/admin/settings/apns', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT * FROM platform_apns_config WHERE id = 1');
    $newKey = trim((string)($body['private_key'] ?? ''));
    $privateKeyEnc = $newKey !== '' ? encryptSecret($newKey) : ($current['private_key_enc'] ?? null);
    $keep = function (string $key) use ($body, $current) {
        return array_key_exists($key, $body) ? (trim((string)$body[$key]) ?: null) : ($current[$key] ?? null);
    };

    DB::execute(
        'UPDATE platform_apns_config SET team_id = ?, key_id = ?, bundle_id = ?, private_key_enc = ?, sandbox = ?, updated_at = now() WHERE id = 1',
        [$keep('team_id'), $keep('key_id'), $keep('bundle_id'), $privateKeyEnc, !empty($body['sandbox'])]
    );
    logAudit(null, 'apns_config.update', 'platform_apns_config', '1', 'APNs-Konfiguration (Push-Benachrichtigungen) über die App geändert.');
    echo json_encode(['status' => 'ok']);
});

/** Schickt eine Test-Push an alle eigenen (des aufrufenden Admin-Accounts) registrierten Geräte
 *  -- JSON-Äquivalent von POST /admin/mail-settings/test, nur für Push statt Mail. Erfordert,
 *  dass der Admin zuvor in der App selbst (Einstellungen -> Push aktivieren) sein Gerät über
 *  POST /api/v1/push/register registriert hat, sonst "Kein registriertes Gerät." */
$router->post('/api/v1/admin/settings/apns/test', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    if (!Push::isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'APNs ist noch nicht konfiguriert (Team-ID/Key-ID/Bundle-ID/Auth-Key fehlen).']);
        return;
    }
    DB::execute(
        'INSERT INTO push_notifications_queue (user_id, role, title, body, data)
         VALUES (?, ?, ?, ?, ?)',
        [$ctx['user_id'], 'admin', 'Test-Benachrichtigung', 'Push-Benachrichtigungen funktionieren.', json_encode(['type' => 'test'])]
    );
    try {
        $result = Push::sendPending();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen: ' . $e->getMessage()]);
        return;
    }
    if (($result['sent'] ?? 0) > 0) {
        logAudit(null, 'apns_config.test', null, null, 'Test-Push über die App versendet.');
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen oder kein registriertes Gerät. Details im Aktivitätslog/Server-Log.']);
    }
});

/** Testmodus/Echtbetrieb umschalten -- JSON-Äquivalent von POST /admin/settings/test-mode. */
$router->post('/api/v1/admin/settings/test-mode', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $testMode = !empty($body['test_mode']);
    DB::execute('UPDATE platform_settings SET test_mode = ?, updated_at = now() WHERE id = 1', [$testMode ? 'true' : 'false']);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'Plattform über die App auf ' . ($testMode ? 'Testmodus' : 'Echtbetrieb') . ' umgestellt.');
    echo json_encode(['status' => 'ok']);
});

/** Portal-Design umstellen -- JSON-Äquivalent von POST /admin/settings/design. */
$router->post('/api/v1/admin/settings/design', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    if (!savePlatformDesign((string)($body['portal_design'] ?? ''), ' über die App')) {
        http_response_code(400);
        echo json_encode(['error' => "portal_design muss 'klassisch' oder 'glas' sein."]);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

/** ESP-Offline-Schwelle setzen -- JSON-Äquivalent von POST /admin/settings/esp. */
$router->post('/api/v1/admin/settings/esp', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $minutes = (int)($body['esp_offline_after_minutes'] ?? 5);
    if ($minutes < 1) $minutes = 5;
    DB::execute('UPDATE platform_settings SET esp_offline_after_minutes = ?, updated_at = now() WHERE id = 1', [$minutes]);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'ESP-Offline-Schwelle über die App auf ' . $minutes . ' Minuten gesetzt.');
    echo json_encode(['status' => 'ok']);
});

/**
 * Backup-Status (Alter/Größe der letzten Sicherungen) -- JSON-Äquivalent von GET /admin/backups.
 * Rein lesend, wie im Web (Backup-Verzeichnis ist :ro gemountet).
 */
$router->get('/api/v1/admin/backups', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $dir = '/var/www/html/backups';
    $status = null;
    if (is_readable($dir . '/last_backup.json')) {
        $status = json_decode((string)file_get_contents($dir . '/last_backup.json'), true) ?: null;
    }
    $arten = [
        'stamm' => ['label' => 'Stammdaten (Mitglieder, Rechnungen, Verträge)', 'glob' => 'eeg_stamm_*.dump'],
        'voll'  => ['label' => 'Datenbank vollständig (inkl. Messwerte)',        'glob' => 'eeg_2*.dump'],
        'full'  => ['label' => 'Komplettsicherung (Datenbank + Dateien)',        'glob' => 'eeg_full_*.tar.gz'],
    ];
    $result = [];
    $dirLesbar = is_dir($dir) && is_readable($dir);
    foreach ($arten as $key => $art) {
        $dateien = [];
        if ($dirLesbar) {
            foreach (glob($dir . '/' . $art['glob']) ?: [] as $pfad) {
                $dateien[] = ['name' => basename($pfad), 'bytes' => filesize($pfad) ?: 0, 'zeit' => appDate(date('c', filemtime($pfad) ?: 0))];
            }
            usort($dateien, fn($a, $b) => strcmp($b['zeit'] ?? '', $a['zeit'] ?? ''));
        }
        $result[$key] = ['label' => $art['label'], 'dateien' => $dateien];
    }
    echo json_encode(['status' => $status, 'arten' => $result, 'verzeichnis_lesbar' => $dirLesbar]);
});
