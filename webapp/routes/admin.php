<?php

declare(strict_types=1);

/**
 * Platform-Admin-Routen (/admin/...).
 *
 * Wird von public/index.php eingebunden (gleicher globaler Gültigkeitsbereich: $router, ROOT,
 * Hilfsfunktionen). Reihenfolge der Dateien dort beachten: die erste passende Route gewinnt.
 *
 * @var Router $router
 */

// ─── Admin-Bereich ──────────────────────────────────────
$router->get('/admin', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT * FROM communities ORDER BY name');
    $userCount   = DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];
    $rawUsers    = DB::fetchAll('SELECT id, email, first_name, last_name, active, is_demo, founder_admin FROM users ORDER BY last_name, first_name');
    $allRoles    = DB::fetchAll('SELECT ur.user_id, ur.role, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }
    // Demo-Login: echte Login-Accounts hier NIE unmaskiert zeigen (siehe demoMaskUser()).
    $rawUsers = demoMaskUsers($rawUsers, Auth::isDemo());
    $users = array_map(fn($u) => array_merge($u, ['roles' => $roleMap[$u['id']] ?? []]), $rawUsers);

    // Plattform-Übersicht (Patrick, 14.11.2026: beim Wechsel auf "Admin" ein nützliches
    // Admin-Dashboard statt des Obmann-Dashboards einer EEG): je EEG die offenen Aufgaben und
    // der Datenstand. Die Tabellen haben Row-Level Security -> je EEG einzeln abfragen.
    $espOfflineMinutes = espOfflineAfterMinutes();
    $eegStats = [];
    foreach ($communities as $c) {
        $st = ['id' => $c['id'], 'name' => $c['name'], 'members' => 0, 'meters' => 0, 'live' => 0,
               'applications' => 0, 'notifications' => 0, 'support' => 0, 'unassigned' => 0,
               'last_import' => null, 'last_interval_import' => null];
        try {
            DB::setCommunity($c['id']);
            $row = DB::fetchOne(
                "SELECT
                    (SELECT COUNT(*) FROM members WHERE community_id = ? AND status = 'active' AND is_demo = false) AS members,
                    (SELECT COUNT(*) FROM metering_points WHERE community_id = ? AND active = true AND member_id IS NOT NULL) AS meters,
                    (SELECT COUNT(*) FROM metering_points WHERE community_id = ? AND active = true
                        AND esp_last_seen_at > now() - (? || ' minutes')::interval) AS live,
                    (SELECT COUNT(*) FROM membership_applications WHERE community_id = ? AND status = 'pending') AS applications,
                    (SELECT COUNT(*) FROM notifications WHERE community_id = ? AND status = 'offen') AS notifications,
                    (SELECT COUNT(*) FROM metering_points WHERE community_id = ? AND member_id IS NULL) AS unassigned,
                    (SELECT MAX(imported_at) FROM eda_imports WHERE community_id = ?) AS last_import",
                [$c['id'], $c['id'], $c['id'], $espOfflineMinutes, $c['id'], $c['id'], $c['id'], $c['id']]
            );
            $st = array_merge($st, $row ?: []);
            $st['support'] = (int)(DB::fetchOne(
                "SELECT COUNT(*) AS cnt FROM support_ticket_messages sm JOIN support_tickets st ON st.id = sm.ticket_id
                 WHERE st.community_id = ? AND sm.is_staff = false
                   AND sm.created_at > COALESCE(st.manager_read_at, '-infinity'::timestamptz)",
                [$c['id']]
            )['cnt'] ?? 0);
            $st['last_interval_import'] = DB::fetchOne(
                'SELECT MAX(imported_at) AS t FROM eda_interval_imports WHERE community_id = ?', [$c['id']]
            )['t'] ?? null;
        } catch (\Throwable $e) {
            error_log('[admin] Übersicht für EEG ' . $c['id'] . ': ' . $e->getMessage());
        }
        $eegStats[] = $st;
    }
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1') ?: [];
    $backupStatus = null;
    if (is_readable('/var/www/html/backups/last_backup.json')) {
        $backupStatus = json_decode((string)file_get_contents('/var/www/html/backups/last_backup.json'), true) ?: null;
    }
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1') ?: []; } catch (\Throwable $e) { $platformSettings = []; }
    require ROOT . '/src/views/pages/admin.php';
});

/**
 * Alle Mitglieder aller EEGs mit Suche nach Name, Geburtsdatum, Adresse, KdNr, E-Mail oder
 * Zählpunkt (Patrick, 14.11.2026). Klick öffnet die Mitglied-Details (dort u.a. "Ins Portal
 * des Kunden"). members hat Row-Level Security -> je EEG einzeln laden.
 */
$router->get('/admin/members', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    $isDemo = Auth::isDemo();
    $members = [];
    foreach ($communities as $c) {
        DB::setCommunity($c['id']);
        $rows = DB::fetchAll(
            "SELECT m.id, m.kundennummer, m.salutation, m.first_name, m.last_name, m.company_name, m.email,
                    m.phone, m.address, m.zip, m.city, m.geburtsdatum, m.status, m.is_demo,
                    (SELECT string_agg(mp.zaehlpunkt_nr, ' ') FROM metering_points mp
                      WHERE mp.member_id = m.id AND mp.active = true) AS zaehlpunkte
             FROM members m WHERE m.community_id = ?
             ORDER BY m.last_name, m.first_name",
            [$c['id']]
        );
        foreach (demoMaskMembers($rows, $isDemo) as $r) {
            if ($isDemo && empty($r['is_demo']) && !empty($r['zaehlpunkte'])) { $r['zaehlpunkte'] = demoMaskFull((string)$r['zaehlpunkte']); }
            $r['community_name'] = $c['name'];
            $members[] = $r;
        }
    }
    usort($members, fn($a, $b) => strcasecmp($a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name']));
    $q = trim((string)($_GET['q'] ?? ''));
    require ROOT . '/src/views/pages/admin_members.php';
});

/**
 * Neuen Platform-Admin ("Unteradmin") einladen -- Patrick, 09.10.2026: "ich möchte nicht meinen
 * Zugang denen geben, sondern die sollen einen eigenen bekommen [...] Meine ist die
 * Ersteller-Admin-Adresse, und dann gibt es noch Unteradmins [...] Bis halt auf die
 * Rollenverteilung" -- deshalb Auth::isFounderAdmin() statt nur isPlatformAdmin(): nur der
 * Ersteller-Admin darf die Platform-Admin-Rollenverteilung selbst verändern, ein Unteradmin hat
 * sonst dieselben Rechte wie jeder andere Platform-Admin.
 *
 * Existiert bereits ein Login mit dieser E-Mail (z.B. ein bestehender Obmann/Kassier-Account),
 * bekommt genau dieser Account zusätzlich die platform_admin-Rolle -- kein zweiter, paralleler
 * Account nötig. Nur bei einer komplett neuen E-Mail wird ein neuer Account angelegt (mit
 * zufälligem, niemandem bekannten Platzhalter-Passwort) und ein Link zum Passwort-Setzen
 * verschickt, gültig 48 Stunden.
 */
$router->post('/admin/platform-admins/invite', function () {
    Auth::requireLogin();
    if (!Auth::isFounderAdmin()) { http_response_code(403); echo 'Nur der Ersteller-Admin darf neue Platform-Admins einladen.'; return; }

    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: /admin?error=' . urlencode('Bitte eine gültige E-Mail-Adresse angeben.'));
        exit;
    }

    $existing = DB::fetchOne('SELECT id, first_name FROM users WHERE email = ?', [$email]);

    if ($existing) {
        $userId = $existing['id'];
        try {
            $mail = [
                'subject' => 'Platform-Admin-Zugang – Strom für alle',
                'body' => '<p>Hallo ' . htmlspecialchars($existing['first_name']) . ',</p>'
                    . '<p>du hast mit deinem bestehenden Login (<strong>' . htmlspecialchars($email) . '</strong>)'
                    . ' jetzt zusätzlich Platform-Admin-Zugang zur Strom-für-alle-Plattform bekommen.</p>'
                    . '<p>Einfach wie gewohnt einloggen -- oben rechts lässt sich zwischen deinen Rollen wechseln.</p>',
            ];
            Mailer::send($email, $mail['subject'], $mail['body']);
        } catch (\Throwable $e) {
            error_log('[platform_admin_invite_mail] ' . $e->getMessage());
        }
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        if ($firstName === '' || $lastName === '') {
            header('Location: /admin?error=' . urlencode('Für einen neuen Account bitte Vor- und Nachname angeben.'));
            exit;
        }
        // Zufälliges, niemandem bekanntes Platzhalter-Passwort -- der eingeladene Unteradmin
        // vergibt sein eigenes über den Reset-Link in der Einladungs-Mail (siehe unten).
        $placeholderHash = hashPassword(bin2hex(random_bytes(32)));
        DB::execute(
            'INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)',
            [$email, $placeholderHash, $firstName, $lastName]
        );
        $existing = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
        $userId = $existing['id'];

        $token = Auth::createResetToken($email, 172800); // 48 Stunden
        try {
            $link = htmlspecialchars(passwordResetLink($token));
            $mail = [
                'subject' => 'Einladung als Platform-Admin – Strom für alle',
                'body' => '<p>Hallo ' . htmlspecialchars($firstName) . ',</p>'
                    . '<p>du wurdest als Platform-Admin zur Strom-für-alle-Plattform eingeladen.</p>'
                    . '<p>Über folgenden Link kannst du innerhalb der nächsten 48 Stunden dein Passwort vergeben'
                    . ' und dich danach einloggen:</p>'
                    . '<p><a href="' . $link . '">' . $link . '</a></p>',
            ];
            Mailer::send($email, $mail['subject'], $mail['body']);
        } catch (\Throwable $e) {
            error_log('[platform_admin_invite_mail] ' . $e->getMessage());
        }
    }

    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (NULL, ?, ?) ON CONFLICT DO NOTHING',
        [$userId, 'platform_admin']
    );
    logAudit(null, 'platform_admin.invite', 'user', $userId, 'Als Platform-Admin (Unteradmin) eingeladen: ' . $email);
    header('Location: /admin?success=' . urlencode('Platform-Admin-Zugang für ' . $email . ' eingerichtet.'));
    exit;
});

$router->post('/admin/communities', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $name = trim($_POST['name'] ?? '');
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    DB::execute(
        'INSERT INTO communities (name, slug, marktpartner_id, address, uses_ausleseeinheit) VALUES (?, ?, ?, ?, ?)',
        [$name, $slug, $_POST['marktpartner_id'] ?? null, $_POST['address'] ?? null, isset($_POST['uses_ausleseeinheit']) ? 'true' : 'false']
    );
    header('Location: /admin');
    exit;
});

$router->get('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); return; }
    // Entschlüsselt fürs Formular: der Platform-Admin muss das EDA-Portal-Passwort tatsächlich
    // ablesen können (z.B. um sich selbst einzuloggen), anders als z.B. das Graph-Client-Secret,
    // das nur die App selbst braucht -- siehe encryptSecret()/decryptSecret() in functions.php.
    $community['eda_login_password'] = decryptSecret($community['eda_login_password_enc'] ?? null);
    // Demo-Login: das entschlüsselte EDA-Portal-Passwort ist ein ECHTES Zugangsdatum zum
    // Netzbetreiber-Portal -- niemals im Klartext zeigen (Patrick, 06.09.2026).
    if (Auth::isDemo()) {
        if (!empty($community['eda_login_password'])) { $community['eda_login_password'] = demoMaskFull((string)$community['eda_login_password']); }
        if (!empty($community['eda_login_email'])) { $community['eda_login_email'] = demoMaskFull((string)$community['eda_login_email']); }
    }
    // Gleiche Stammdaten-Maskierung wie auf /portal/settings (Marktpartner-ID etc. sind hier
    // ebenfalls editierbar) -- ZVR/Name bleiben bewusst sichtbar, siehe dort.
    $community = demoMaskCommunitySettings($community, Auth::isDemo());
    // members hat Row-Level Security (migrate_20260822.sql) -- OHNE DB::setCommunity() liefert
    // die eingeschränkte Laufzeit-Rolle grundsätzlich GAR KEINE Zeile, auch bei korrektem WHERE
    // (Patrick, 19.08.2026 beim App-API-Nachbau entdeckt: diese Seite zeigte seit dem RLS-Fix
    // leer, obwohl Mitglieder vorhanden sind). Anders als z.B. bei requireMemberAccess() gibt es
    // hier KEIN Henne-Ei-Problem -- die Ziel-Community ist ja schon aus der URL bekannt
    // (:id), also einfach direkt setzen, kein "jede Community durchprobieren" nötig. Der
    // Platform-Admin sieht dadurch weiterhin die Mitglieder JEDER EEG (nicht nur der eigenen
    // aktiven Rolle) -- der ursprüngliche Kommentar hier meinte richtig "keine Bindung an die
    // eigene aktive Rolle", nicht "gar keine Community setzen".
    DB::setCommunity($params['id']);
    $members = DB::fetchAll(
        'SELECT m.id, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email, m.status,
                m.user_id, m.is_demo, u.email AS login_email
         FROM members m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.community_id = ?
         ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name',
        [$params['id']]
    );
    // Demo-Login: echte Mitgliederdaten hier NIE unmaskiert zeigen (siehe /portal/members).
    // login_email (echter Login-Account, falls vorhanden) separat maskieren -- demoMaskMember()
    // kennt nur die members-eigenen Spalten.
    $isDemo = Auth::isDemo();
    $members = demoMaskMembers($members, $isDemo);
    if ($isDemo) {
        foreach ($members as &$m) {
            if (empty($m['is_demo']) && !empty($m['login_email'])) { $m['login_email'] = demoMaskFull((string)$m['login_email']); }
        }
        unset($m);
    }
    require ROOT . '/src/views/pages/admin_community.php';
});

$router->get('/admin/users/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user        = DB::fetchOne('SELECT id, email, first_name, last_name, active, is_demo, founder_admin FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }
    $roles       = DB::fetchAll('SELECT ur.*, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    // members hat RLS -- je Rolle einzeln mit korrekt gesetzter Community nachladen (gleiches
    // Muster wie Auth::attachMemberNames()), damit bei role='member' mit gesetzter member_id
    // (Demo-Logins mit mehreren Mitglied-Identitäten, siehe migrate_20260905.sql) der Name der
    // jeweiligen Identität in der Rollen-Tabelle angezeigt werden kann. Ebenso werden ALLE
    // Mitglieder jeder EEG geladen fürs Auswahlfeld beim Hinzufügen einer 'member'-Rolle.
    $isDemo = Auth::isDemo();
    $membersByCommunity = [];
    foreach ($communities as $c) {
        DB::setCommunity($c['id']);
        $membersByCommunity[$c['id']] = demoMaskMembers(DB::fetchAll(
            "SELECT id, first_name, last_name, is_demo FROM members WHERE community_id = ? ORDER BY last_name, first_name",
            [$c['id']]
        ), $isDemo);
    }
    $user = demoMaskUser($user, $isDemo);
    foreach ($roles as &$r) {
        if (empty($r['member_id']) || empty($r['community_id'])) continue;
        foreach ($membersByCommunity[$r['community_id']] ?? [] as $m) {
            if ($m['id'] === $r['member_id']) {
                $r['member_name'] = trim($m['first_name'] . ' ' . $m['last_name']);
                break;
            }
        }
    }
    unset($r);
    require ROOT . '/src/views/pages/admin_user.php';
});

$router->post('/admin/users/:id/roles', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $communityId = $_POST['community_id'] ?? null;
    $role = $_POST['role'] ?? '';
    if (!in_array($role, ['platform_admin', 'manager', 'member'])) { http_response_code(400); return; }
    // Platform-Admin-Rollenverteilung ist Ersteller-Admin-exklusiv (Patrick, 09.10.2026) --
    // Unteradmins dürfen zwar z.B. manager-/member-Rollen anderer Accounts verwalten (normale
    // Support-Arbeit), aber keine neuen Platform-Admins ernennen.
    if ($role === 'platform_admin' && !Auth::isFounderAdmin()) {
        http_response_code(403);
        echo 'Nur der Ersteller-Admin darf die Platform-Admin-Rolle vergeben.';
        return;
    }
    // member_id nur bei role='member' relevant -- disambiguiert mehrere Mitglied-Identitäten
    // desselben Logins in derselben Community (Demo-Logins, siehe migrate_20260905.sql). Für den
    // normalen Fall (ein Mitglied hat genau einen members-Datensatz je Community) leer lassen --
    // currentMemberFull() fällt dann weiterhin auf die alte user_id-Suche zurück.
    $memberId = ($role === 'member' && !empty($_POST['member_id'])) ? (string)$_POST['member_id'] : null;
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role, member_id) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role, $memberId]
    );
    if ($params['id'] === Auth::userId()) { Auth::refreshRoles(); }
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/roles/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    $targetRole = DB::fetchOne('SELECT ur.role, u.founder_admin FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.id = ?', [$_POST['role_id']]);
    if ($targetRole && $targetRole['role'] === 'platform_admin') {
        // Platform-Admin-Rollenverteilung ist Ersteller-Admin-exklusiv (siehe .../roles POST
        // oben). Die eigene Platform-Admin-Rolle des Ersteller-Admins lässt sich darüber hinaus
        // NIE entfernen, auch nicht durch den Ersteller-Admin selbst -- sonst könnte sich
        // niemand mehr aussperren-sicher ins Backoffice einloggen (Patrick, 09.10.2026: "Sie
        // können mir nicht die Berechtigung wegnehmen").
        if (!Auth::isFounderAdmin()) {
            http_response_code(403);
            echo 'Nur der Ersteller-Admin darf eine Platform-Admin-Rolle entfernen.';
            return;
        }
        if ($targetRole['founder_admin']) {
            http_response_code(400);
            echo 'Die Platform-Admin-Rolle des Ersteller-Admins kann nicht entfernt werden.';
            return;
        }
    }

    // Es muss immer mindestens eine platform_admin-Rolle übrig bleiben, sonst kann sich
    // niemand mehr ins Admin-Backoffice einloggen.
    $isLastPlatformAdminRole = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$_POST['role_id']]
    );
    if ($isLastPlatformAdminRole) {
        http_response_code(400);
        echo 'Dies ist die letzte verbleibende Plattform-Admin-Rolle und kann nicht entfernt werden.';
        return;
    }

    DB::execute('DELETE FROM user_roles WHERE id = ?', [$_POST['role_id']]);
    if ($params['id'] === Auth::userId()) { Auth::refreshRoles(); }
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    if ($params['id'] === Auth::userId()) { http_response_code(400); echo 'Der eigene Account kann nicht gelöscht werden.'; return; }
    $user = DB::fetchOne('SELECT id, founder_admin FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }
    // Der Ersteller-Admin-Account selbst ist nie löschbar (siehe .../roles/delete oben --
    // dieselbe Begründung gilt erst recht fürs komplette Konto).
    if ($user['founder_admin']) {
        http_response_code(400);
        echo 'Der Ersteller-Admin-Account kann nicht gelöscht werden.';
        return;
    }

    // Es muss immer mindestens ein platform_admin übrig bleiben, sonst kann sich niemand mehr
    // ins Admin-Backoffice einloggen -- keine hartkodierte E-Mail, sondern generisch "letzter
    // verbleibender platform_admin darf nicht gelöscht werden".
    $isLastPlatformAdmin = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$params['id']]
    );
    if ($isLastPlatformAdmin) {
        http_response_code(400);
        echo 'Dieser Account ist der letzte verbleibende Plattform-Admin und kann nicht gelöscht werden.';
        return;
    }

    // Löscht kaskadierend Rollenzuweisungen (user_roles); verknüpfte Mitglieder bleiben erhalten,
    // verlieren nur die Login-Verknüpfung (siehe migrate_20260715.sql).
    DB::execute('DELETE FROM users WHERE id = ?', [$params['id']]);
    header('Location: /admin?success=1');
    exit;
});

$router->post('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // EDA-Portal-Passwort nur überschreiben, wenn tatsächlich ein neues eingegeben wurde --
    // das Feld wird beim Laden nie im Klartext vorbefüllt (siehe admin_community.php), ein
    // leeres Absenden darf das gespeicherte Passwort also nicht versehentlich löschen.
    $current = DB::fetchOne('SELECT eda_login_password_enc FROM communities WHERE id = ?', [$params['id']]);
    $newEdaPassword = trim($_POST['eda_login_password'] ?? '');
    $edaPasswordEnc = $newEdaPassword !== '' ? encryptSecret($newEdaPassword) : ($current['eda_login_password_enc'] ?? null);

    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=?,
             eda_login_email=?, eda_login_password_enc=?, uses_ausleseeinheit=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            isset($_POST['active']) ? 'true' : 'false',
            trim($_POST['eda_login_email'] ?? '') ?: null,
            $edaPasswordEnc,
            isset($_POST['uses_ausleseeinheit']) ? 'true' : 'false',
            $params['id'],
        ]
    );
    // Eigene Anweisung: fehlt die Spalte noch (Migration migrate_20261121b.sql nicht gelaufen), bleibt der Rest gespeichert.
    try {
        DB::execute('UPDATE communities SET supplier_duties_enabled = ? WHERE id = ?',
            [isset($_POST['supplier_duties_enabled']) ? 'true' : 'false', $params['id']]);
    } catch (\Throwable $e) {
        error_log('[admin] supplier_duties_enabled nicht gespeichert: ' . $e->getMessage());
    }
    logAudit($params['id'], 'community.update', 'community', $params['id'], 'EEG "' . trim($_POST['name'] ?? '') . '" bearbeitet');
    header('Location: /admin?success=1');
    exit;
});

$router->post('/admin/communities/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $community = DB::fetchOne('SELECT id, name FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); return; }

    // Kaskadiert über ON DELETE CASCADE auf ALLE community-gebundenen Daten (Mitglieder,
    // Zählpunkte, Verträge, Rechnungen, Rollenzuweisungen, ...) -- siehe init.sql/migrate_*.sql,
    // dort hat jede Referenz auf communities(id) ON DELETE CASCADE. Login-Accounts (users)
    // bleiben bestehen, verlieren nur ihre Rolle(n) in dieser EEG.
    // Audit-Log bewusst mit community_id=NULL, sonst würde der Eintrag durch dieselbe Kaskade
    // sofort wieder mitgelöscht.
    DB::execute('DELETE FROM communities WHERE id = ?', [$params['id']]);
    logAudit(null, 'community.delete', 'community', $params['id'],
        'EEG "' . $community['name'] . '" (ID ' . $community['id'] . ') endgültig gelöscht inkl. aller Mitglieder, Verträge, Zählpunkte und Rechnungen');
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
    $entries = demoMaskAuditLog($entries, Auth::isDemo());
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    require ROOT . '/src/views/pages/admin_log.php';
});

/**
 * Audit-Log als Markdown exportieren (Download) -- zum Archivieren oder späteren Auswerten
 * (auch per KI): wer hat wann was geändert. Respektiert den EEG-Filter, exportiert aber ohne
 * das 500-Zeilen-Limit der Ansicht.
 */
$router->get('/admin/log/export', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    denyDemoFileDownload();
    $filterCommunity = $_GET['community_id'] ?? '';
    $params = []; $where = '1=1';
    if ($filterCommunity !== '') { $where .= ' AND al.community_id = ?'; $params[] = $filterCommunity; }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where ORDER BY al.created_at DESC",
        $params
    );
    $esc = fn($s) => str_replace(['|', "\r", "\n"], ['\\|', '', ' '], (string)$s);
    $md  = "# Audit-Log – " . AppConfig::get()->domain . "\n\n";
    $md .= 'Exportiert am ' . date('d.m.Y H:i') . ' · ' . count($entries) . " Einträge"
         . ($filterCommunity !== '' ? ' · gefiltert nach einer EEG' : '') . "\n\n";
    $md .= "| Datum/Zeit | Benutzer | EEG | Aktion | Objekt | Beschreibung | Fehler |\n";
    $md .= "|---|---|---|---|---|---|---|\n";
    foreach ($entries as $e) {
        $user = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['email'] ?? 'System');
        $obj  = trim(($e['entity_typ'] ?? '') . ' ' . ($e['entity_id'] ?? ''));
        $md .= '| ' . date('d.m.Y H:i:s', strtotime($e['created_at']))
             . ' | ' . $esc($user)
             . ' | ' . $esc($e['community_name'] ?? '—')
             . ' | ' . $esc($e['aktion'])
             . ' | ' . $esc($obj)
             . ' | ' . $esc($e['beschreibung'])
             . ' | ' . (!empty($e['ist_fehler']) ? '⚠️ Fehler' : '')
             . " |\n";
    }
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd_His') . '.md"');
    echo $md;
    exit;
});

// ─── Admin: Einstellungen (Plattform-Technik + E-Mail/Microsoft Graph) ──────
$router->get('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $mailTemplates = DB::fetchAll('SELECT * FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    try { $mqttConfig = DB::fetchOne('SELECT * FROM platform_mqtt_config WHERE id = 1'); } catch (\Throwable $e) { $mqttConfig = null; }
    // JSONB-Spalte kommt über PDO als Rohtext zurück, nicht automatisch dekodiert (anders als
    // z.B. psycopg2 in mqtt-subscriber) -- für die Formular-Vorbelegung in der View als Array
    // gebraucht, siehe admin_mail_settings.php ("MQTT-Fernkonfiguration (Geräte)").
    if ($mqttConfig && !empty($mqttConfig['device_reconfig_payload'])) {
        $mqttConfig['device_reconfig_payload'] = json_decode($mqttConfig['device_reconfig_payload'], true) ?: [];
    }
    // Demo-Login: diese Seite zeigt normalerweise mehrere ECHTE Zugangsdaten im Klartext (MQTT-
    // Passwort, Microsoft-Graph Tenant-/Client-ID) -- die Read-only-Sperre verhindert zwar jede
    // Änderung, aber NICHT das bloße Ansehen. Patrick, 06.09.2026: "die ganzen E-Mail-
    // Einstellungen, Sachen wie die Graph API von Microsoft [...] verpixelt oder mit Sternchen".
    // Das Client-Secret selbst wird ohnehin nie im Klartext angezeigt (siehe admin_mail_settings.php).
    if (Auth::isDemo()) {
        if ($mqttConfig) {
            if (!empty($mqttConfig['mqtt_password'])) { $mqttConfig['mqtt_password'] = demoMaskFull((string)$mqttConfig['mqtt_password']); }
            if (!empty($mqttConfig['device_reconfig_payload']['mqtt_pass'])) {
                $mqttConfig['device_reconfig_payload']['mqtt_pass'] = demoMaskFull((string)$mqttConfig['device_reconfig_payload']['mqtt_pass']);
            }
        }
        if ($mailConfig) {
            if (!empty($mailConfig['tenant_id'])) { $mailConfig['tenant_id'] = demoMaskFull((string)$mailConfig['tenant_id']); }
            if (!empty($mailConfig['client_id'])) { $mailConfig['client_id'] = demoMaskFull((string)$mailConfig['client_id']); }
        }
    }
    require ROOT . '/src/views/pages/admin_mail_settings.php';
});

/**
 * Speichert den GEWÜNSCHTEN MQTT-Benutzernamen/Passwort und setzt pending_apply -- die Webapp
 * kann weder Docker noch Dateien auf dem Host direkt anfassen, das eigentliche Anwenden auf den
 * echten Broker übernimmt scripts/mqtt_apply_pending.sh als Host-Cron (siehe migrate_20260827.sql
 * und CLAUDE.md), sobald pending_apply=true dort ankommt -- typischerweise binnen einer Minute.
 * Manueller Fallback (falls der Cron noch nicht eingerichtet ist): `mqtt_secure_setup.sh --apply`.
 */
$router->post('/admin/mqtt-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $mqttUser = trim($_POST['mqtt_user'] ?? '') ?: 'eeg-device';
    $mqttPassword = trim($_POST['mqtt_password'] ?? '');
    DB::execute(
        'UPDATE platform_mqtt_config SET mqtt_user = ?, mqtt_password = ?, pending_apply = true, updated_at = now() WHERE id = 1',
        [$mqttUser, $mqttPassword]
    );
    logAudit(null, 'mqtt_config.update', 'platform_mqtt_config', '1', 'MQTT-Zugangsdaten geändert (Benutzer: ' . $mqttUser . '), Anwendung auf den Broker angestoßen.');
    header('Location: /admin/mail-settings?mqtt_success=1');
    exit;
});

/**
 * MQTT-Fernkonfiguration der ESP-Geräte: schickt Host/Port/Benutzer/Passwort an ALLE bereits
 * im Feld laufenden Geräte, statt jedes einzeln über sein eigenes /config-Formular umstellen zu
 * müssen (Patrick, 12.08.2026, nachdem geklärt war, dass dafür keine offenen Ports beim
 * Mitglied nötig sind -- jedes Gerät baut die MQTT-Verbindung selbst ausgehend auf, der Befehl
 * kommt über genau diese Verbindung zurück). Die Webapp selbst hat keinen MQTT-Client -- speichert
 * hier nur die Anfrage (migrate_20260829.sql), mqtt-subscriber holt sie über die bestehende
 * DB-Verbindung ab und published sie (siehe reconfig_broadcast_loop() in main.py). Nur Geräte
 * mit einer Firmware ab dieser Funktion (onMqttMessage() im Sketch) reagieren darauf -- ältere
 * Geräte ignorieren die für sie unbekannte Nachricht einfach.
 */
$router->post('/admin/mqtt-device-reconfig', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $host = trim($_POST['device_mqtt_host'] ?? '');
    $port = (int)($_POST['device_mqtt_port'] ?? 0);
    $user = trim($_POST['device_mqtt_user'] ?? '');
    $pass = (string)($_POST['device_mqtt_pass'] ?? '');
    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        header('Location: /admin/mail-settings?error=' . urlencode('Für die Geräte-Fernkonfiguration bitte Host, Port, Benutzername und Passwort ausfüllen.'));
        exit;
    }
    $payload = json_encode(['mqtt_host' => $host, 'mqtt_port' => $port, 'mqtt_user' => $user, 'mqtt_pass' => $pass]);
    DB::execute(
        "UPDATE platform_mqtt_config SET device_reconfig_payload = ?, device_reconfig_requested_at = now() WHERE id = 1",
        [$payload]
    );
    logAudit(null, 'mqtt_config.device_reconfig', 'platform_mqtt_config', '1',
        "MQTT-Fernkonfiguration an alle Geräte angestoßen (Host: $host:$port, Benutzer: $user).");
    header('Location: /admin/mail-settings?mqtt_device_success=1');
    exit;
});

/**
 * Backup-Übersicht: zeigt, ob die nächtliche Sicherung wirklich läuft (Alter/Größe der letzten
 * Dumps) und welche Sicherungen zum Wiederherstellen bereitliegen. Liest das Backup-Verzeichnis
 * NUR LESEND (Mount `:ro` in docker-compose.yml) -- die Webapp kann Backups also anzeigen, aber
 * nie verändern oder löschen.
 */
$router->get('/admin/backups', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }

    $dir = '/var/www/html/backups';
    $status = null;
    if (is_readable($dir . '/last_backup.json')) {
        $status = json_decode((string)file_get_contents($dir . '/last_backup.json'), true) ?: null;
    }
    // Sicherungen einsammeln und nach Art gruppieren.
    $arten = [
        'stamm' => ['label' => 'Stammdaten (Mitglieder, Rechnungen, Verträge)', 'glob' => 'eeg_stamm_*.dump', 'dateien' => []],
        'voll'  => ['label' => 'Datenbank vollständig (inkl. Messwerte)',        'glob' => 'eeg_2*.dump',      'dateien' => []],
        'full'  => ['label' => 'Komplettsicherung (Datenbank + Dateien)',        'glob' => 'eeg_full_*.tar.gz','dateien' => []],
    ];
    $dirLesbar = is_dir($dir) && is_readable($dir);
    if ($dirLesbar) {
        foreach ($arten as $key => $art) {
            foreach (glob($dir . '/' . $art['glob']) ?: [] as $pfad) {
                $arten[$key]['dateien'][] = [
                    'name'  => basename($pfad),
                    'bytes' => filesize($pfad) ?: 0,
                    'zeit'  => filemtime($pfad) ?: 0,
                ];
            }
            usort($arten[$key]['dateien'], fn($a, $b) => $b['zeit'] <=> $a['zeit']);
        }
    }
    require ROOT . '/src/views/pages/admin_backups.php';
});

/**
 * Testmodus/Echtbetrieb: steuert nur, ob die Kundennummern-Vergabe Lücken von gelöschten/
 * deaktivierten Mitgliedern auffüllen darf (siehe createMemberRecord()) -- im Echtbetrieb
 * wird eine einmal vergebene Nummer nie wieder verwendet.
 */
$router->post('/admin/settings/design', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    if (!savePlatformDesign((string)($_POST['portal_design'] ?? ''), '')) {
        header('Location: /admin/mail-settings?error=' . urlencode('Unbekanntes Design.') . '#design');
        exit;
    }
    header('Location: /admin/mail-settings?success=1#design');
    exit;
});

$router->post('/admin/settings/test-mode', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $testMode = !empty($_POST['test_mode']);
    // PDO gibt einen rohen PHP-Bool ohne expliziten Typ standardmäßig als String weiter --
    // "true" wird von Postgres noch akzeptiert, ein rohes "false" aber als leerer String (''),
    // was am boolean-Spaltentyp scheitert (SQLSTATE 22P02). Deshalb wie im Rest der Codebase
    // durchgehend 'true'/'false' als Literal übergeben statt des PHP-Bools direkt.
    DB::execute('UPDATE platform_settings SET test_mode = ?, updated_at = now() WHERE id = 1', [$testMode ? 'true' : 'false']);
    // entity_id ist in audit_log als UUID typisiert -- platform_settings.id ist aber ein
    // simpler Integer (immer 1), passt dort nicht rein.
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'Plattform auf ' . ($testMode ? 'Testmodus' : 'Echtbetrieb') . ' umgestellt');
    header('Location: /admin/mail-settings?success=1');
    exit;
});

/**
 * Betreiber-Anschrift/Rechtsform (Impressum, § 5 ECG), Hosting-Anbieter (Datenschutzerklärung) und
 * Netzgebühr-Hinweis (Marketing) -- bewusst nicht im Code (öffentliches Repo). Siehe migrate_20261121b.sql.
 */
$router->post('/admin/settings/betreiber', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $text = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $percentRaw = str_replace(',', '.', trim((string)($_POST['netz_savings_percent'] ?? '')));
    $percent = null;
    if ($percentRaw !== '') {
        if (!is_numeric($percentRaw) || (float)$percentRaw < 0 || (float)$percentRaw > 100) {
            header('Location: /admin/mail-settings?error=' . urlencode('Netzgebühr-Ersparnis: bitte eine Zahl zwischen 0 und 100 eingeben.') . '#tab-allgemein');
            exit;
        }
        $percent = round((float)$percentRaw, 1);
    }
    $stand = trim((string)($_POST['netz_tariff_stand'] ?? ''));
    if ($stand !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $stand)) { $stand = ''; }
    DB::execute(
        'UPDATE platform_settings SET operator_legal_form = ?, operator_address = ?, hosting_provider = ?,
                hosting_provider_note = ?, netz_savings_percent = ?, netz_tariff_stand = ?, updated_at = now() WHERE id = 1',
        [$text('operator_legal_form', 200), $text('operator_address', 500), $text('hosting_provider', 200),
         $text('hosting_provider_note', 500), $percent, $stand !== '' ? $stand : null]
    );
    logAudit(null, 'platform_settings.update', 'platform_settings', null, 'Betreiber-/Hosting-/Netzgebühr-Angaben aktualisiert');
    header('Location: /admin/mail-settings?success=1#tab-allgemein');
    exit;
});

/** Siehe migrate_20260823.sql / espOfflineAfterMinutes(). */
$router->post('/admin/settings/esp', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $minutes = (int)($_POST['esp_offline_after_minutes'] ?? 5);
    if ($minutes < 1) $minutes = 5;
    DB::execute('UPDATE platform_settings SET esp_offline_after_minutes = ?, updated_at = now() WHERE id = 1', [$minutes]);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'ESP-Offline-Schwelle auf ' . $minutes . ' Minuten gesetzt');
    header('Location: /admin/mail-settings?success=1');
    exit;
});

$router->post('/admin/mail-templates', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $key = $_POST['key'] ?? '';
    if (!in_array($key, ['password_reset', 'invite', 'member_deactivated', 'contract_bezug', 'contract_einspeisung', 'contract_both', 'sepa_prenotification', 'mahnung', 'invoice_released'], true)) { http_response_code(400); return; }
    $tplBefore = DB::fetchOne('SELECT subject, body_html FROM platform_mail_templates WHERE key = ?', [$key]);
    DB::execute(
        'UPDATE platform_mail_templates SET subject = ?, body_html = ?, updated_at = now() WHERE key = ?',
        [trim($_POST['subject'] ?? ''), $_POST['body_html'] ?? '', $key]
    );
    $tplAfter = DB::fetchOne('SELECT subject, body_html FROM platform_mail_templates WHERE key = ?', [$key]);
    $tplChanges = auditDiff($tplBefore ?? [], $tplAfter ?? [], ['subject' => 'Betreff', 'body_html' => 'Text (HTML)']);
    if (!empty($tplChanges)) {
        logAuditDiff(null, 'mail_template.update', 'platform_mail_templates', $key, $tplChanges, 'E-Mail-Vorlage „' . $key . '":');
    } else {
        logAudit(null, 'mail_template.update', 'platform_mail_templates', $key, 'E-Mail-Vorlage "' . $key . '" gespeichert (keine Änderung)');
    }
    header('Location: /admin/mail-settings?success=1');
    exit;
});

$router->post('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // Client-Secret nur überschreiben, wenn tatsächlich ein neuer Wert eingegeben wurde --
    // das Feld wird beim Laden nie im Klartext vorbefüllt, ein leeres Absenden darf das
    // gespeicherte Secret also nicht versehentlich löschen.
    $current = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $newSecret = trim($_POST['client_secret'] ?? '');
    $clientSecret = $newSecret !== '' ? $newSecret : ($current['client_secret'] ?? null);

    // Signatur-Logo: bestehendes behalten, per Checkbox entfernen oder durch Upload ersetzen.
    $logoBase64 = $current['signature_logo_base64'] ?? null;
    $logoType   = $current['signature_logo_type'] ?? null;
    if (!empty($_POST['signature_logo_remove'])) {
        $logoBase64 = null;
        $logoType   = null;
    }
    if (!empty($_FILES['signature_logo']) && $_FILES['signature_logo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['signature_logo']['size'] > 2 * 1024 * 1024) {
            header('Location: /admin/mail-settings?error=' . urlencode('Logo zu groß (max. 2 MB).')); exit;
        }
        $info = @getimagesize($_FILES['signature_logo']['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) {
            header('Location: /admin/mail-settings?error=' . urlencode('Datei ist kein gültiges Bild (PNG/JPG/GIF).')); exit;
        }
        $logoBase64 = base64_encode((string)file_get_contents($_FILES['signature_logo']['tmp_name']));
        $logoType   = $info['mime'];
    }
    // Logo-Größe (px). Leer/0/ungültig -> NULL (Standardgröße). Max. 1000 px als Sanity-Grenze.
    // isset()-Prüfung aus demselben Grund wie bei $keep() unten: nur die große Form hat diese
    // Felder überhaupt, ein Request vom kleinen EDA-Postfach-Formular darf sie nicht auf NULL
    // zurücksetzen.
    $clampPx = function ($v): ?int {
        $n = (int)$v;
        return ($n > 0 && $n <= 1000) ? $n : null;
    };
    $logoWidth  = isset($_POST['signature_logo_width'])  ? $clampPx($_POST['signature_logo_width'])  : ($current['signature_logo_width']  ?? null);
    $logoHeight = isset($_POST['signature_logo_height']) ? $clampPx($_POST['signature_logo_height']) : ($current['signature_logo_height'] ?? null);

    // Mehrere <form>-Elemente auf dieser Seite posten hierher (das große Formular mit ALLEN
    // Feldern, aber z.B. auch das kleine "EDA-Automatik"-Postfach-Formular weiter unten, das
    // NUR eda_import_mailbox_address enthält). Ohne diese isset()-Prüfung würde jedes Feld, das
    // im gerade abgeschickten Formular schlicht nicht vorkommt, mit trim(null ?? '') ?: null zu
    // NULL -- die kleine Postfach-Form hätte damit bei JEDEM Speichern Tenant-ID/Client-ID/
    // Signatur/Alarm-Adressen gelöscht (Patrick, 13.08.2026: "Er haut mir immer die azure App
    // daten raus. und die signatur ist auch wieder weg."). $keep() unterscheidet "Feld war in
    // DIESEM Request gar nicht dabei -> alten Wert behalten" von "Feld war dabei, aber leer
    // abgeschickt -> wirklich löschen" (isset() ist true auch bei einem leer übermittelten Feld).
    $keep = function (string $key) use ($current) {
        return isset($_POST[$key]) ? (trim($_POST[$key]) ?: null) : ($current[$key] ?? null);
    };
    $supportEmail = isset($_POST['support_notification_email'])
        ? (trim($_POST['support_notification_email']) ?: AppConfig::get()->contactEmail)
        : ($current['support_notification_email'] ?? AppConfig::get()->contactEmail);

    DB::execute(
        'UPDATE platform_mail_config
         SET tenant_id = ?, client_id = ?, client_secret = ?, sender_address = ?, reply_to = ?, signature_html = ?,
             signature_logo_base64 = ?, signature_logo_type = ?,
             signature_logo_width = ?, signature_logo_height = ?,
             backup_alert_email_1 = ?, backup_alert_email_2 = ?, support_notification_email = ?,
             eda_import_mailbox_address = ?, updated_at = now()
         WHERE id = 1',
        [
            $keep('tenant_id'),
            $keep('client_id'),
            $clientSecret,
            $keep('sender_address'),
            $keep('reply_to'),
            $keep('signature_html'),
            $logoBase64,
            $logoType,
            $logoWidth,
            $logoHeight,
            $keep('backup_alert_email_1'),
            $keep('backup_alert_email_2'),
            $supportEmail,
            $keep('eda_import_mailbox_address'),
        ]
    );
    $mailAfter = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    // Sensible Werte (Secret, Logo-Rohdaten) werden NICHT im Klartext protokolliert -- nur, DASS
    // sie geändert wurden. Deshalb secret/logo aus dem eigentlichen Diff heraushalten und separat
    // als Ja/Nein-Änderung behandeln.
    $mailChanges = auditDiff($current ?? [], $mailAfter ?? [], [
        'tenant_id' => 'Tenant-ID', 'client_id' => 'Client-ID', 'sender_address' => 'Absenderadresse',
        'reply_to' => 'Antwort-an', 'signature_html' => 'Signatur',
        'signature_logo_width' => 'Logo-Breite', 'signature_logo_height' => 'Logo-Höhe',
        'backup_alert_email_1' => 'Alarm-E-Mail 1', 'backup_alert_email_2' => 'Alarm-E-Mail 2',
        'support_notification_email' => 'Support-Ticket-Benachrichtigung an',
        'eda_import_mailbox_address' => 'EDA-Import-Postfach',
    ]);
    if (($current['client_secret'] ?? null) !== ($mailAfter['client_secret'] ?? null)) {
        $mailChanges['client_secret'] = ['label' => 'Client-Secret', 'von' => '(verborgen)', 'auf' => '(geändert)'];
    }
    if (($current['signature_logo_base64'] ?? null) !== ($mailAfter['signature_logo_base64'] ?? null)) {
        $mailChanges['signature_logo'] = ['label' => 'Signatur-Logo', 'von' => (empty($current['signature_logo_base64']) ? 'kein' : 'vorhanden'), 'auf' => (empty($mailAfter['signature_logo_base64']) ? 'entfernt' : 'gesetzt')];
    }
    if (!empty($mailChanges)) {
        logAuditDiff(null, 'mail_config.update', 'platform_mail_config', '1', $mailChanges, 'Mail-Konfiguration:');
    } else {
        logAudit(null, 'mail_config.update', 'platform_mail_config', '1', 'Mail-Konfiguration gespeichert (keine Änderung)');
    }
    header('Location: /admin/mail-settings?success=1');
    exit;
});

/**
 * Manueller Testlauf des EDA-Postfach-Imports (siehe EdaAutoImporter.php) -- normalerweise
 * läuft das per Cron (scripts/eda_auto_import.php), dieser Button ist zum Testen/Nachstoßen,
 * ohne auf den nächsten Cron-Durchlauf warten zu müssen.
 */
$router->post('/admin/mail-settings/eda-import-run', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    try {
        $lines = EdaAutoImporter::run();
        logAudit(null, 'eda.auto_import.manual_run', null, null, 'EDA-Postfach-Import manuell angestoßen: ' . implode(' | ', $lines));
        header('Location: /admin/mail-settings?eda_run=' . urlencode(implode("\n", $lines)));
    } catch (\Throwable $e) {
        header('Location: /admin/mail-settings?eda_run_error=' . urlencode($e->getMessage()));
    }
    exit;
});

$router->post('/admin/mail-settings/test', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $to = trim($_POST['test_to'] ?? '');
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $mailTemplates = DB::fetchAll('SELECT * FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $testError = 'Bitte eine gültige E-Mail-Adresse angeben.';
        require ROOT . '/src/views/pages/admin_mail_settings.php';
        return;
    }
    try {
        Mailer::send($to, 'Test-E-Mail von Strom für alle', '<p>Das ist eine Test-E-Mail aus dem Platform-Admin-Bereich von ' . htmlspecialchars(AppConfig::get()->domain) . '.</p><p>Wenn Sie das lesen, funktioniert der Microsoft-Graph-Mailversand.</p>');
        $testSuccess = 'Test-E-Mail an ' . htmlspecialchars($to) . ' wurde verschickt.';
    } catch (\Throwable $e) {
        $testError = $e->getMessage();
    }
    require ROOT . '/src/views/pages/admin_mail_settings.php';
});

/**
 * Whitelist der über /admin/templates verwaltbaren Dateien (Dateiname => Anzeigename + Typ).
 * Bewusst eine feste Liste statt freier Dateinamen -- verhindert Path-Traversal. Umfasst die
 * LaTeX-Vorlagen (von latex-service live pro Anfrage gerendert) UND das Infoblatt: das ist
 * KEINE Vorlage, sondern eine fertige, statische PDF für die Marketing-Seite (kein
 * personalisierter Inhalt, daher kein Live-Rendering) -- hier lädt der Platform-Admin direkt
 * eine fertige PDF hoch, kein .tex.
 */
function adminFileRegistry(): array
{
    return [
        'bezugsvereinbarung.tex'           => ['label' => 'Bezugsvereinbarung', 'type' => 'tex'],
        'einspeisevereinbarung.tex'        => ['label' => 'Einspeisevereinbarung', 'type' => 'tex'],
        'rechnung.tex'                     => ['label' => 'Rechnung', 'type' => 'tex'],
        'beitrittserklaerung_formular.tex' => ['label' => 'Beitrittserklärung (Online-Ausdruck)', 'type' => 'tex'],
        'beitrittserklaerung_leer.tex'     => ['label' => 'Beitrittserklärung (Papierformular)', 'type' => 'tex'],
        'infoblatt.pdf'                    => ['label' => 'Infoblatt (Website)', 'type' => 'pdf'],
        'logo-light.png'                   => ['label' => 'Logo (Light-Mode)', 'type' => 'image'],
        'logo-dark.png'                    => ['label' => 'Logo (Dark-Mode)', 'type' => 'image'],
        'hero-banner.jpg'                   => ['label' => 'Hero-Banner (Startseite)', 'type' => 'image'],
        'favicon-source.png'               => ['label' => 'Favicon / Browser-Tab-Icon', 'type' => 'image'],
    ];
}

/**
 * Verfügbare <<<VARIABLE>>>-Platzhalter je LaTeX-Vorlage, rein zu Anzeigezwecken in
 * /admin/templates -- Quelle der Wahrheit sind die .tex-Dateien selbst (latex-service
 * ersetzt <<<NAME>>> durch den jeweiligen Wert, siehe latex-service/service.js). Ein
 * mit RAW_ beginnender Variablenname wird NICHT für LaTeX escaped (dort steht bereits
 * fertiges LaTeX drin, z.B. eine eingebettete Unterschrift-Grafik oder eine Liste) --
 * beim Bearbeiten der Vorlage also nicht versehentlich Text hineinschreiben, der wie
 * ein RAW_-Platzhalter aussieht.
 */
function adminFileVariables(): array
{
    return [
        'rechnung.tex' => [
            'RECHNUNGSNUMMER' => 'Fortlaufende Rechnungsnummer',
            'RECHNUNGSDATUM' => 'Ausstellungsdatum',
            'ABRECHNUNGSZEITRAUM' => 'Zeitraum, für den abgerechnet wird',
            'ZAHLUNGSZIEL' => 'Fälligkeitsdatum',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft (einzeilig)',
            'EEG_STRASSE' => 'Straße/Hausnummer der Energiegemeinschaft (erster Teil von EEG_ADRESSE, für den Footer)',
            'EEG_PLZ_ORT' => 'PLZ und Ort der Energiegemeinschaft (zweiter Teil von EEG_ADRESSE, für den Footer)',
            'EEG_UID' => 'UID-Nummer der Energiegemeinschaft',
            'EEG_ZVR' => 'Zentralvereinsregisternummer (ZVR) der Energiegemeinschaft',
            'EEG_OBMANN_TELEFON' => 'Kontakt-Telefonnummer (Obmann/Obfrau) der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_KONTAKT_EMAIL' => 'Kontakt-E-Mail der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_BANKNAME' => 'Name der Bank der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_KONTOINHABER' => 'Kontoinhaber (falls abweichend vom EEG-Namen), für den Rechnungs-Footer -- leer = Vorlage fällt auf EEG_NAME zurück',
            'MITGLIED_ANREDE' => 'Anrede des Mitglieds (Herr/Frau)',
            'MITGLIED_NAME' => 'Anzeigename: abweichender Rechnungsname > Firma > Titel + Vor-/Nachname',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds (einzeilig, Fallback falls Vorlage keine getrennten Zeilen nutzt)',
            'MITGLIED_STRASSE' => 'Straße/Hausnummer des Mitglieds (für getrennte Adresszeilen)',
            'MITGLIED_PLZ_ORT' => 'PLZ und Ort des Mitglieds (für getrennte Adresszeilen)',
            'MITGLIED_UID' => 'UID-Nummer des Mitglieds (falls Firma)',
            'KUNDENNUMMER' => 'Plattformweit eindeutige Kundennummer des Mitglieds',
            'MITGLIED_SEPA_MANDATSREFERENZ' => 'SEPA-Mandatsreferenz des Mitglieds, falls vorhanden',
            'BEZUG_KWH' => 'Bezogene Energiemenge in kWh (Summe; Fallback-Einzeiler, wenn keine RAW-Bezugsliste)',
            'BEZUG_TARIF' => 'Bezugstarif (ct/kWh) für den Fallback-Einzeiler',
            'BEZUG_BETRAG' => 'Betrag für den Bezug (Summe) für den Fallback-Einzeiler',
            'BEZUG_ZAEHLPUNKT' => 'Einzelne Bezugs-Zählpunktnummer für den Fallback-Einzeiler (nur bei genau einem Zählpunkt)',
            'EINSPEISUNG_KWH' => 'Eingespeiste Energiemenge in kWh (Summe; Fallback-Einzeiler)',
            'EINSPEISUNG_TARIF' => 'Einspeisetarif (ct/kWh) für den Fallback-Einzeiler',
            'EINSPEISUNG_BETRAG' => 'Betrag für die Einspeisung (Summe) für den Fallback-Einzeiler',
            'EINSPEISUNG_ZAEHLPUNKT' => 'Einzelne Einspeise-Zählpunktnummer für den Fallback-Einzeiler (nur bei genau einem Zählpunkt)',
            'RAW_BEZUG_POSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für den Bezug -- eine Zeile pro Zählpunkt, jeweils mit Zählpunktnummer (RAW, nicht escapen). Leer = Fallback auf BEZUG_KWH-Einzeiler',
            'RAW_EINSPEISUNG_POSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für die Einspeisung -- eine Zeile pro Zählpunkt (RAW, nicht escapen). Leer = Fallback auf EINSPEISUNG_KWH-Einzeiler',
            'MITGLIEDSBEITRAG_LABEL' => 'Beschriftung der Mitgliedsbeitrag-Zeile, z.B. "Mitgliedsbeitrag (anteilig, 2 Monate)" bei unterjährigem Beitritt, sonst nur "Mitgliedsbeitrag"',
            'MITGLIEDSBEITRAG' => 'Mitgliedsbeitrag laut Preisliste (anteilig bei unterjährigem Beitritt)',
            'SUMME_TABELLE_LABEL' => '"Summe" (Kleinunternehmer) bzw. "Summe netto" (Standard-Steuermodell) für die Summenzeile in der Positionstabelle',
            'RAW_SUMME_NETTO' => 'Gesamtsumme netto, bereits über rechnungBetragUnicode() formatiert (RAW, nicht escapen -- enthält bei negativem Betrag echtes LaTeX-Minuszeichen). Aktueller Platzhalter -- bitte in der Vorlage verwenden.',
            'SUMME_NETTO' => 'Gesamtsumme netto als reine Zahl (z.B. "-28,70", einfacher Bindestrich). Nur als Absicherung für ältere Vorlagen vorhanden -- neue Vorlagen verwenden RAW_SUMME_NETTO.',
            'RAW_SUMME_BRUTTO' => 'Gesamtsumme brutto, bereits über rechnungBetragUnicode() formatiert (RAW, nicht escapen) -- bei einer Gutschrift (negativer Saldo) bewusst als positiver Betrag befüllt, siehe RAW_SUMME_LABEL. Aktueller Platzhalter -- bitte in der Vorlage verwenden.',
            'SUMME_BRUTTO' => 'Gesamtsumme brutto als reine Zahl, immer positiv. Nur als Absicherung für ältere Vorlagen vorhanden -- neue Vorlagen verwenden RAW_SUMME_BRUTTO.',
            'IBAN' => 'IBAN der Energiegemeinschaft (für die Zahlung)',
            'BIC' => 'BIC der Energiegemeinschaft',
            'RAW_STEUER_ZEILE' => 'Vorformatierte USt-Zeile (RAW, nicht escapen)',
            'RAW_STEUER_TEXT' => 'Vorformatierter Steuerhinweis-Text (RAW, nicht escapen)',
            'RAW_ZUSATZPOSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für manuelle Zusatzpositionen, z.B. ein Rabatt (RAW, nicht escapen) -- siehe /portal/billing',
            'RAW_ZAHLUNG_TEXT' => 'Vorformatierter Zahlungstext: SEPA-Lastschrift-Vorabankündigung, Gutschrift-Hinweis oder leer (Vorlage zeigt dann die Standard-Überweisungsbitte) -- RAW, nicht escapen',
            'RAW_SUMME_LABEL' => 'Beschriftung der Summe, z.B. "Ihre Gutschrift" bei einer Gutschrift statt "Gesamtbetrag" (RAW, nicht escapen)',
            'RAW_LIEFERANTENBLOCK' => 'Kasten nach § 69 Abs 3 ElWG mit den verwendeten Energiewerten je Zählpunkt und dem Vertragspartner -- nur bei aktiven Lieferantenpflichten der Gemeinschaft, sonst leer (RAW, nicht escapen)',
        ],
        'bezugsvereinbarung.tex' => [
            'ERSTELLT_AM' => 'Erstellungsdatum des Dokuments',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ORT' => 'Ort der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'EEG_IBAN' => 'IBAN der Energiegemeinschaft',
            'EEG_MARKTPARTNER_ID' => 'Marktpartner-ID der Energiegemeinschaft',
            'EEG_DASHBOARD_URL' => 'Link zum öffentlichen Live-Dashboard',
            'MITGLIED_NAME' => 'Name des Mitglieds (inkl. Titel)',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds',
            'MITGLIED_IBAN' => 'IBAN des Mitglieds',
            'MITGLIED_UID_ZEILE' => 'UID-Zeile des Mitglieds, falls vorhanden',
            'MITGLIED_SEPA_MANDATSREFERENZ' => 'SEPA-Mandatsreferenz des Mitglieds',
            'BEZUG_TARIF' => 'Vereinbarter Bezugstarif (€/kWh)',
            'TARIF_GUELTIG_AB' => 'Gültig-ab-Datum des Tarifs',
            'MITGLIEDSBEITRAG' => 'Mitgliedsbeitrag laut Preisliste',
            'RAW_ZAEHLPUNKTE_LISTE' => 'Vorformatierte Liste der Bezugs-Zählpunkte (RAW, nicht escapen)',
            'RAW_EEG_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik der EEG (RAW, nicht escapen)',
            'RAW_MITGLIED_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik des Mitglieds (RAW, nicht escapen)',
            'RAW_MITGLIED_ORT_DATUM' => 'Vorformatierter Ort/Datum-Text bei der Unterschrift (RAW, nicht escapen)',
        ],
        'einspeisevereinbarung.tex' => [
            'ERSTELLT_AM' => 'Erstellungsdatum des Dokuments',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ORT' => 'Ort der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'EEG_MARKTPARTNER_ID' => 'Marktpartner-ID der Energiegemeinschaft',
            'MITGLIED_NAME' => 'Name des Mitglieds (inkl. Titel)',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds',
            'MITGLIED_IBAN' => 'IBAN des Mitglieds',
            'MITGLIED_UID_ZEILE' => 'UID-Zeile des Mitglieds, falls vorhanden',
            'MITGLIED_SEIT' => 'Mitglied-seit-Datum',
            'ANLAGENBESCHREIBUNG' => 'Beschreibung der Einspeiseanlage (z.B. PV-Leistung)',
            'EINSPEISUNG_TARIF' => 'Vereinbarter Einspeisetarif (€/kWh)',
            'TARIF_GUELTIG_AB' => 'Gültig-ab-Datum des Tarifs',
            'RAW_ZAEHLPUNKTE_LISTE' => 'Vorformatierte Liste der Einspeise-Zählpunkte (RAW, nicht escapen)',
            'RAW_EEG_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik der EEG (RAW, nicht escapen)',
            'RAW_MITGLIED_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik des Mitglieds (RAW, nicht escapen)',
            'RAW_MITGLIED_ORT_DATUM' => 'Vorformatierter Ort/Datum-Text bei der Unterschrift (RAW, nicht escapen)',
        ],
        'beitrittserklaerung_formular.tex' => [
            'EINGEREICHT_AM' => 'Einreichdatum des Formulars',
            'TITEL' => 'Titel (z.B. akademischer Grad)',
            'VORNAME' => 'Vorname',
            'NACHNAME' => 'Nachname',
            'GEBURTSDATUM' => 'Geburtsdatum',
            'ADRESSE' => 'Adresse',
            'EMAIL' => 'E-Mail-Adresse',
            'TELEFON' => 'Telefonnummer',
            'STROMLIEFERANT' => 'Bisheriger Stromlieferant',
            'BEZUG_JAHRESVERBRAUCH' => 'Jährlicher Stromverbrauch (kWh)',
            'EINSPEISUNG_GEPLANT' => 'Ob eine Einspeiseanlage geplant ist',
            'EINSPEISUNG_KWP' => 'Geplante PV-Leistung (kWp)',
            'SPEICHER_KWH' => 'Geplante Speicherkapazität (kWh)',
            'ANDERE_EEG_NAME' => 'Name einer evtl. anderen EEG-Mitgliedschaft',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'SIGNER_IP' => 'IP-Adresse bei der Online-Unterschrift',
            'UNTERSCHRIEBEN_AM' => 'Zeitpunkt der Unterschrift',
            'UNTERSCHRIEBEN_DATUM' => 'Datum der Unterschrift',
            'RAW_ANREDE_HERR' => 'Ankreuzfeld „Herr" (RAW, nicht escapen)',
            'RAW_ANREDE_FRAU' => 'Ankreuzfeld „Frau" (RAW, nicht escapen)',
            'RAW_BEZUG_CB' => 'Ankreuzfeld Bezug (RAW, nicht escapen)',
            'RAW_EINSPEISUNG_CB' => 'Ankreuzfeld Einspeisung (RAW, nicht escapen)',
            'RAW_SPEICHER_JA' => 'Ankreuzfeld Speicher „Ja" (RAW, nicht escapen)',
            'RAW_SPEICHER_NEIN' => 'Ankreuzfeld Speicher „Nein" (RAW, nicht escapen)',
            'RAW_SPEICHER_GEPLANT' => 'Vorformatierter Speicher-Block (RAW, nicht escapen)',
            'RAW_ANDERE_EEG_JA' => 'Ankreuzfeld andere EEG „Ja" (RAW, nicht escapen)',
            'RAW_ANDERE_EEG_NEIN' => 'Ankreuzfeld andere EEG „Nein" (RAW, nicht escapen)',
            'RAW_ZP_BEZUG_GRID' => 'Vorformatiertes Raster der Bezugs-Zählpunkte (RAW, nicht escapen)',
            'RAW_ZP_EINSPEISUNG_GRID' => 'Vorformatiertes Raster der Einspeise-Zählpunkte (RAW, nicht escapen)',
            'RAW_ZUSTIMMUNGEN_LISTE' => 'Kurzfassung der Zustimmungen in der akzeptierten Fassung (RAW, nicht escapen)',
            'RAW_ZUSTIMMUNGEN_HINWEIS' => 'Versionshinweis + Links auf den vollständigen Wortlaut (RAW, nicht escapen)',
            'RAW_SEPA_BLOCK' => 'Vorformatierter SEPA-Block (RAW, nicht escapen)',
            'RAW_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik (RAW, nicht escapen)',
        ],
        'beitrittserklaerung_leer.tex' => [
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'EEG_RC' => 'RC-Nummer ohne "RC"-Präfix (z.B. 108175)',
            'EEG_ADRESSE' => 'Adresse/Sitz der Energiegemeinschaft',
            'KONTAKT_EMAIL' => 'Kontakt-E-Mail (Fallback ' . AppConfig::get()->contactEmail . ')',
            'RAW_KONTAKT' => 'Kontaktzeile E-Mail + Telefon (RAW, nicht escapen)',
            'RAW_SEPA_TEXT' => 'Wortlaut des SEPA-Mandats aus consent_texts.php (RAW, nicht escapen)',
            'RAW_ZUSTIMMUNGEN_LISTE' => 'Langfassung der sechs Zustimmungen aus consent_texts.php (RAW, nicht escapen)',
            'RAW_ZUSTIMMUNGEN_HINWEIS' => 'Fassung/Version + Link auf alle Fassungen (RAW, nicht escapen)',
        ],
    ];
}

/**
 * Bild-Assets (keine Text-Platzhalter, sondern eingebettete Dateien) je Vorlage --
 * eingebunden in LaTeX über \includegraphics{dateiname} (benötigt \usepackage{graphicx}).
 */
function adminFileAssets(): array
{
    return [
        'rechnung.tex' => [
            'logo.png' => 'Logo der EEG. Eigenes hochgeladenes Logo (Manager-Einstellungen -> Logo für Rechnungen/Verträge) oder ersatzweise das Website-Logo -- immer vorhanden, \includegraphics{logo.png} funktioniert also immer.',
        ],
    ];
}

/**
 * Vollständige Liste aller Felder aus members/communities/tariff_config/tax_config, die
 * grundsätzlich für neue Platzhalter zur Verfügung stünden -- unabhängig davon, ob sie
 * aktuell schon in einer Vorlage verwendet werden (siehe adminFileVariables()). Gedacht als
 * Nachschlagewerk beim Erweitern einer Vorlage bzw. zum Export für eine externe KI, die beim
 * Schreiben einer neuen .tex-Datei hilft. Ist ein Feld hier noch nicht als <<<VAR>>> in einer
 * bestehenden Vorlage verdrahtet, muss es zuerst im jeweiligen PHP-Code (z.B. der
 * /portal/invoices/:id/pdf-Route) ergänzt werden, bevor es dort tatsächlich ersetzt wird --
 * diese Liste beschreibt die verfügbaren Rohdaten, nicht automatisch fertige Platzhalter.
 */
function availableDataFields(): array
{
    return [
        'Mitglied (members)' => [
            'salutation' => 'Anrede (Herr/Frau)',
            'titel_vor' => 'Akademischer Titel (vorgestellt, z.B. Dr., Mag., Ing.)',
            'titel_nach' => 'Akademischer Titel (nachgestellt, z.B. BA, MSc)',
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'company_name' => 'Firmenname, falls Firmenmitglied',
            'address' => 'Straße und Hausnummer',
            'zip' => 'Postleitzahl',
            'city' => 'Ort',
            'email' => 'E-Mail-Adresse',
            'phone' => 'Telefonnummer',
            'invoice_name' => 'Abweichender Name für Rechnungen (falls gesetzt)',
            'invoice_uid' => 'UID-Nummer für Rechnungen (falls Firma)',
            'member_iban' => 'IBAN des Mitglieds',
            'member_bic' => 'BIC des Mitglieds',
            'kundennummer' => 'Plattformweit eindeutige Kundennummer',
            'mandatsreferenz' => 'SEPA-Mandatsreferenz',
            'member_since' => 'Mitglied seit (Datum)',
            'member_until' => 'Mitglied bis (Datum, falls beendet)',
            'status' => 'Mitgliedsstatus (pending/active/inactive)',
        ],
        'Zählpunkt (metering_points)' => [
            'zaehlpunkt_nr' => 'Zählpunktnummer (AT...)',
            'type' => 'Zählpunkt-Typ (consumer/producer/prosumer)',
            'active' => 'Ob der Zählpunkt aktiv ist',
        ],
        'Energiegemeinschaft (communities)' => [
            'name' => 'Name der EEG',
            'marktpartner_id' => 'Marktpartner-ID (z.B. RC108175)',
            'zvr_number' => 'ZVR-Zahl',
            'address' => 'Adresse der EEG',
            'dashboard_url' => 'Link zum Mitgliederportal',
            'iban' => 'IBAN der EEG',
            'bic' => 'BIC der EEG',
            'payment_days' => 'Zahlungsziel in Tagen',
        ],
        'Tarif (tariff_config, historisiert)' => [
            'valid_from' => 'Gültig ab (Datum)',
            'bezug_ct_kwh' => 'Bezugstarif in ct/kWh',
            'einspeisung_ct_kwh' => 'Einspeisevergütung in ct/kWh',
            'mitgliedsbeitrag_eur' => 'Jahresbeitrag in EUR',
        ],
        'Steuer (tax_config, historisiert)' => [
            'tax_model' => 'Steuermodell (kleinunternehmer/standard)',
            'tax_rate_percent' => 'USt-Satz in %, falls "standard"',
            'uid_number' => 'UID-Nummer der EEG (falls USt-pflichtig)',
        ],
        'Abrechnung (invoices/invoice_items/billing_runs)' => [
            'rechnungsnummer' => 'Fortlaufende Rechnungsnummer',
            'saldo_eur' => 'Gesamtsumme der Rechnung',
            'quartal' => 'Abgerechnetes Quartal (z.B. 2026-Q1)',
            'period_from / period_to' => 'Abrechnungszeitraum',
            'invoice_items.label/quantity/unit/amount_eur' => 'Manuelle Zusatzposition (z.B. Rabatt) -- siehe /portal/billing',
        ],
    ];
}

/**
 * Pfad zur aktuell wirksamen Fassung einer verwalteten Datei: das persistente Volume
 * (/var/www/html/latex-templates, geteilt mit latex-service) hat Vorrang, sonst die im
 * Image mitgelieferte Standard-Fassung als Rückfallebene (z.B. direkt nach einem frischen
 * Deploy, bevor latex-service beim ersten Start das Volume befüllt hat, oder bevor überhaupt
 * einmal etwas hochgeladen wurde).
 */
function adminFilePath(string $filename): ?string
{
    $live = '/var/www/html/latex-templates/' . $filename;
    if (is_file($live)) { return $live; }
    $default = '/var/www/html/latex-templates-default/' . $filename;
    return is_file($default) ? $default : null;
}

/**
 * Skaliert $src zentriert/seitenverhältnis-erhaltend ("contain") auf ein $size x $size
 * Quadrat, mit transparentem Rand falls $src nicht schon quadratisch ist. Transparenz der
 * Quelle bleibt dabei erhalten (imagealphablending(false) + imagesavealpha(true) -- ohne
 * diese beiden Zeilen würde imagecopyresampled() den Alphakanal falsch mit Schwarz
 * vermischen statt ihn zu übernehmen).
 */
function resizeContainTransparent($src, int $size)
{
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $transparent);
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min($size / $srcW, $size / $srcH);
    $newW = (int) round($srcW * $scale);
    $newH = (int) round($srcH * $scale);
    $offX = (int) round(($size - $newW) / 2);
    $offY = (int) round(($size - $newH) / 2);
    imagecopyresampled($out, $src, $offX, $offY, 0, 0, $newW, $newH, $srcW, $srcH);
    return $out;
}

/**
 * Erzeugt aus einer Favicon-Quellgrafik (idealerweise PNG mit transparentem Hintergrund --
 * Patrick, 29.10.2026: "wäre es fast besser, wenn man ein Bild ohne Hintergrund nimmt", weil
 * ein weißer Kasten im Browser-Tab bei Dark Mode unschön absticht) alle gebräuchlichen
 * Tab-Icon-/PWA-Icon-Größen und schreibt sie als fertige Dateien nach $outDir. Läuft EINMALIG
 * beim Hochladen (siehe /admin/templates/favicon-source.png/upload), nicht bei jedem
 * Seitenaufruf -- die Ergebnisdateien werden danach ganz normal wie jede andere
 * admin-verwaltete Datei über adminFilePath() ausgeliefert.
 *
 * apple-touch-icon.png bekommt bewusst KEINE Transparenz: iOS füllt transparente Bereiche
 * beim "Zum Home-Bildschirm hinzufügen" selbst mit Schwarz statt sie durchscheinen zu lassen
 * (Apple-eigene Konvention/Einschränkung, anders als Browser-Tabs) -- deshalb hier geflättet,
 * aber NICHT auf Weiß (Patrick, 02.11.2026: in Safaris Adressleisten-Vorschlägen/Favoriten --
 * die dort IMMER das größere apple-touch-icon statt des kleinen favicon.ico verwenden,
 * unabhängig von dessen eigener Transparenz -- wirkte ein reines Weiß im Dark Mode wie ein
 * kaputter/transparenter Platzhalter). Komplett transparent lassen geht NICHT (siehe oben,
 * würde auf iOS schwarz werden). Die naheliegende Alternative, die Marken-Grünfarbe
 * (var(--green) #16a34a), wurde VERWORFEN -- das Logo selbst ist ein ähnlicher Grünton
 * (~ rgb(18,160,145)), Kontrast zu #16a34a nur 1,01:1, also quasi unsichtbar. Stattdessen
 * dasselbe dunkle Neutral, das auch das übrige Dark-Theme der Seite für Eingabefelder/Code
 * verwendet (#0f172a, siehe app.css) -- 5,49:1 Kontrast zum Logo, passt zur Markenpalette und
 * wirkt in Safaris Vorschlag-Kacheln (hell wie dunkel) wie ein bewusst gestaltetes App-Icon
 * statt wie ein Platzhalter.
 */
function generateFaviconAssets(string $sourcePath, string $outDir): void
{
    $raw = file_get_contents($sourcePath);
    $src = $raw !== false ? imagecreatefromstring($raw) : false;
    if ($src === false) {
        throw new RuntimeException('Ungültiges Bildformat für Favicon-Quelle.');
    }

    $pngBytesForIco = [];
    foreach ([16 => 'favicon-16.png', 32 => 'favicon-32.png', 192 => 'icon-192.png', 512 => 'icon-512.png'] as $size => $filename) {
        $img = resizeContainTransparent($src, $size);
        imagepng($img, $outDir . '/' . $filename);
        if ($size === 16 || $size === 32) {
            ob_start();
            imagepng($img);
            $pngBytesForIco[$size] = ob_get_clean();
        }
        imagedestroy($img);
    }

    $appleIcon = resizeContainTransparent($src, 180);
    $appleCanvas = imagecreatetruecolor(180, 180);
    $darkSlate = imagecolorallocate($appleCanvas, 0x0f, 0x17, 0x2a); // Dark-Theme-Neutral, siehe app.css
    imagefill($appleCanvas, 0, 0, $darkSlate);
    imagecopy($appleCanvas, $appleIcon, 0, 0, 0, 0, 180, 180);
    imagepng($appleCanvas, $outDir . '/apple-touch-icon.png');
    imagedestroy($appleIcon);
    imagedestroy($appleCanvas);
    imagedestroy($src);

    file_put_contents($outDir . '/favicon.ico', buildIcoFromPngs($pngBytesForIco));
}

/**
 * Baut eine minimale, gültige .ico-Datei aus PNG-Rohdaten (PNG-in-ICO-Einbettung, kein
 * klassisches BMP -- seit Windows Vista von jedem modernen Browser unterstützt). GD hat keine
 * eigene ICO-Export-Funktion, daher von Hand nach der Microsoft-ICONDIR-Struktur zusammengebaut
 * (6-Byte-Header + ein 16-Byte-Directory-Eintrag je Größe + die rohen PNG-Bytes am Ende).
 */
function buildIcoFromPngs(array $pngBytesBySize): string
{
    ksort($pngBytesBySize);
    $count = count($pngBytesBySize);
    $header = pack('vvv', 0, 1, $count); // reserved=0, type=1 (Icon), Anzahl Bilder
    $dirEntries = '';
    $imageData = '';
    $offset = 6 + $count * 16; // 6 Byte Header + 16 Byte je Directory-Eintrag
    foreach ($pngBytesBySize as $size => $bytes) {
        $dim = $size >= 256 ? 0 : $size; // 0 bedeutet laut Spezifikation 256px
        $dirEntries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($bytes), $offset);
        $imageData .= $bytes;
        $offset += strlen($bytes);
    }
    return $header . $dirEntries . $imageData;
}

/**
 * URL für /logo-light.png bzw. /logo-dark.png inkl. Cache-Busting-Parameter. Die Route dahinter
 * setzt "Cache-Control: public, max-age=3600" -- ohne einen sich ändernden Query-Parameter
 * bleibt nach einem Logo-Upload über /admin/templates bis zu eine Stunde lang (oder je nach
 * Browser/Proxy auch länger) weiterhin die ALTE Datei unter derselben URL im Cache, das neue
 * Logo erscheint dann scheinbar gar nicht bzw. es sieht so aus, als würde z.B. im Dark-Mode
 * weiterhin das Light-Mode-Logo angezeigt. filemtime() der tatsächlich wirksamen Datei (Upload
 * oder mitgelieferter Standard) als Version -- ändert sich garantiert bei jedem neuen Upload,
 * bleibt sonst stabil (kein Cache-Bust ohne echte Änderung). Gleiches Muster wie app.css oben
 * in base.php/portal.php.
 */
function logoAssetUrl(string $variant): string
{
    $path = adminFilePath('logo-' . $variant . '.png');
    $v = $path ? filemtime($path) : time();
    return '/logo-' . $variant . '.png?v=' . $v;
}

/**
 * Pfad zum individuellen Logo einer einzelnen EEG für Rechnungen/Verträge (anders als
 * logo-light.png/logo-dark.png in adminFileRegistry(), die für die ganze Website gelten --
 * jede Community kann hier ein eigenes Logo hinterlegen). Kein DB-Feld nötig, reine
 * Dateikonvention nach community_id, gleiches geteiltes Volume wie die LaTeX-Vorlagen.
 */
function communityLogoPath(string $communityId): ?string
{
    $path = '/var/www/html/latex-templates/community-logos/' . $communityId . '.png';
    return is_file($path) ? $path : null;
}

/**
 * Logo-Bild als LaTeX-Asset für die Rechnungserstellung: eigenes Logo der EEG falls
 * hochgeladen, sonst das Website-Logo (Light-Variante) als Rückfallebene -- so findet
 * \includegraphics{logo.png} in rechnung.tex immer eine Datei, auch wenn diese EEG noch
 * keins hochgeladen hat.
 */
function communityLogoAsset(string $communityId): array
{
    $path = communityLogoPath($communityId)
        ?? adminFilePath('logo-light.png')
        ?? ROOT . '/public/assets/images/logo.png';
    return is_file($path) ? ['logo.png' => base64_encode(file_get_contents($path))] : [];
}

// rechnungExtraItemsLatex / rechnungPositionenLatex nach src/functions.php ausgelagert (testbar).

$router->get('/admin/templates', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $templates = [];
    foreach (adminFileRegistry() as $filename => $info) {
        $path = adminFilePath($filename);
        $templates[] = [
            'filename'  => $filename,
            'label'     => $info['label'],
            'type'      => $info['type'],
            'exists'    => $path !== null,
            'is_custom' => $path !== null && str_starts_with($path, '/var/www/html/latex-templates/'),
            'size'      => $path ? filesize($path) : null,
            'mtime'     => $path ? filemtime($path) : null,
            'variables' => adminFileVariables()[$filename] ?? null,
            'assets'    => adminFileAssets()[$filename] ?? null,
        ];
    }
    require ROOT . '/src/views/pages/admin_templates.php';
});

/**
 * Export der Variablen-Referenz (alle Vorlagen) als Markdown bzw. CSV -- gedacht zum
 * Weitergeben an eine KI, die beim Schreiben/Anpassen einer .tex-Datei hilft, ohne dass man
 * die Platzhalternamen händisch abtippen muss.
 */
$router->get('/admin/templates/variablen.md', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $md = "# Variablen-Referenz -- Strom für alle\n\n";
    $md .= "Platzhalter der Form `<<<NAME>>>` in den LaTeX-Vorlagen. Namen mit `RAW_`-Präfix ";
    $md .= "enthalten bereits fertiges LaTeX und werden NICHT escaped.\n\n";
    foreach (adminFileRegistry() as $filename => $info) {
        if ($info['type'] !== 'tex') continue;
        $md .= "## " . $info['label'] . " (`{$filename}`)\n\n";
        $vars = adminFileVariables()[$filename] ?? [];
        if ($vars) {
            $md .= "| Variable | Beschreibung |\n|---|---|\n";
            foreach ($vars as $name => $desc) { $md .= "| `<<<{$name}>>>` | {$desc} |\n"; }
            $md .= "\n";
        }
        $assets = adminFileAssets()[$filename] ?? [];
        if ($assets) {
            $md .= "**Bild-Assets** (über `\\includegraphics{dateiname}`, benötigt `\\usepackage{graphicx}`):\n\n";
            $md .= "| Datei | Beschreibung |\n|---|---|\n";
            foreach ($assets as $name => $desc) { $md .= "| `{$name}` | {$desc} |\n"; }
            $md .= "\n";
        }
    }
    $md .= "# Verfügbare Rohdaten (noch nicht zwingend als Platzhalter verdrahtet)\n\n";
    $md .= "Diese Felder existieren in der Datenbank und könnten bei Bedarf als zusätzliche ";
    $md .= "`<<<VARIABLE>>>` in eine Vorlage aufgenommen werden -- dafür muss der jeweilige ";
    $md .= "PHP-Code (z.B. die Rechnungs-/Vertrags-Route) noch angepasst werden, damit der Wert ";
    $md .= "tatsächlich mitgeschickt wird.\n\n";
    foreach (availableDataFields() as $group => $fields) {
        $md .= "## {$group}\n\n| Feld | Beschreibung |\n|---|---|\n";
        foreach ($fields as $name => $desc) { $md .= "| `{$name}` | {$desc} |\n"; }
        $md .= "\n";
    }
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="variablen-referenz.md"');
    echo $md;
});

$router->get('/admin/templates/variablen.csv', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="variablen-referenz.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kategorie', 'Vorlage/Gruppe', 'Name', 'Beschreibung']);
    foreach (adminFileRegistry() as $filename => $info) {
        if ($info['type'] !== 'tex') continue;
        foreach (adminFileVariables()[$filename] ?? [] as $name => $desc) {
            fputcsv($out, ['Platzhalter', $info['label'], "<<<{$name}>>>", $desc]);
        }
        foreach (adminFileAssets()[$filename] ?? [] as $name => $desc) {
            fputcsv($out, ['Bild-Asset', $info['label'], $name, $desc]);
        }
    }
    foreach (availableDataFields() as $group => $fields) {
        foreach ($fields as $name => $desc) {
            fputcsv($out, ['Verfügbares Rohdatenfeld', $group, $name, $desc]);
        }
    }
    fclose($out);
});

/**
 * Sicherung aller verwalteten Dateien als ZIP (Patrick, 08.11.2026): LaTeX-Vorlagen, Infoblatt-PDF,
 * Logos, Hero-Banner, Favicon-Quelle -- jeweils die gerade WIRKSAME Fassung (individuell
 * hochgeladen oder Standard), dazu die Statuten als PDF. Ordner und Dateiname tragen das
 * heutige Datum, damit mehrere Sicherungen nebeneinander im OneDrive liegen können. Jede Datei
 * lässt sich später unter /admin/templates einzeln wieder hochladen (siehe LIESMICH.txt im ZIP).
 */
$router->get('/admin/templates/sicherung.zip', function () {
    Auth::requireLogin();
    denyDemoFileDownload();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }

    $date = date('Y-m-d');
    $folder = 'stromfueralle-sicherung-' . $date;
    $files = [];
    $lines = [];
    foreach (adminFileRegistry() as $filename => $info) {
        $path = adminFilePath($filename);
        if (!$path) {
            $lines[] = sprintf('- %-34s %s -- NICHT VORHANDEN', $filename, $info['label']);
            continue;
        }
        $files[$filename] = $path;
        $custom = str_starts_with($path, '/var/www/html/latex-templates/');
        $lines[] = sprintf('- %-34s %s -- %s, Stand %s', $filename, $info['label'],
            $custom ? 'individuell hochgeladen' : 'Standard-Fassung', date('d.m.Y H:i', filemtime($path)));
    }
    $statuten = ROOT . '/public/assets/docs/statuten-eeg-strompool-feldkirchen-suedwest.pdf';
    if (is_file($statuten)) {
        $files['statuten-eeg-strompool-feldkirchen-suedwest.pdf'] = $statuten;
        $lines[] = sprintf('- %-34s %s', 'statuten-...pdf', 'Statuten (nur zur Ablage, nicht über /admin/templates hochladbar)');
    }

    $readme = 'Sicherung Strom für alle -- erstellt am ' . date('d.m.Y, H:i') . " Uhr\r\n\r\n"
        . "Enthalten (jeweils die zum Zeitpunkt der Sicherung wirksame Fassung):\r\n"
        . implode("\r\n", $lines) . "\r\n\r\n"
        . "Wiederherstellen: im Portal unter Administration -> Dateien (/admin/templates) bei der\r\n"
        . "jeweiligen Datei die Fassung aus diesem Ordner hochladen. Der Dateiname muss dabei nicht\r\n"
        . "stimmen, die Zuordnung erfolgt über das Feld, in das hochgeladen wird.\r\n";

    $tmp = tempnam(sys_get_temp_dir(), 'sicherung');
    try {
        $count = buildBackupZip($tmp, $folder, $files, $readme);
    } catch (\Throwable $e) {
        @unlink($tmp);
        http_response_code(500); echo 'Sicherung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()); return;
    }
    logAudit(null, 'template.backup', 'admin_file', null, 'Sicherung aller Vorlagen/Dateien als ZIP heruntergeladen (' . $count . ' Dateien)');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $folder . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
});

$router->get('/admin/templates/:name/download', function ($params) {
    Auth::requireLogin();
    denyDemoFileDownload();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $registry = adminFileRegistry();
    if (!array_key_exists($params['name'], $registry)) { http_response_code(404); echo 'Unbekannte Datei'; return; }
    $path = adminFilePath($params['name']);
    if (!$path) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    // Content-Type anhand der Dateiendung (nicht pauschal nach "type"=image) -- seit dem
    // Hero-Banner-JPEG-Fix (09.10.2026) sind "image"-Dateien nicht mehr einheitlich PNG
    // (logo-*.png bleiben PNG, hero-banner.jpg ist JPEG).
    $extToContentType = ['pdf' => 'application/pdf', 'tex' => 'text/plain; charset=UTF-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $ext = strtolower(pathinfo($params['name'], PATHINFO_EXTENSION));
    header('Content-Type: ' . ($extToContentType[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $params['name'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

$router->post('/admin/templates/:name/upload', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $registry = adminFileRegistry();
    if (!array_key_exists($params['name'], $registry)) { http_response_code(404); echo 'Unbekannte Datei'; return; }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        uploadRedirect('/admin/templates?error=' . urlencode('Datei-Upload fehlgeschlagen.'));
    }
    // Grobe Plausibilitätsprüfung -- keine strikte LaTeX-/PDF-Validierung, ein Fehler zeigt
    // sich ohnehin sofort beim nächsten Aufruf (streamLatexPdf() liefert dann die
    // pdflatex-Fehlermeldung statt eines PDFs; ein kaputtes Infoblatt-PDF zeigt der Browser an).
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
        uploadRedirect('/admin/templates?error=' . urlencode('Datei zu groß (max. 10 MB).'));
    }
    // Logos werden direkt (ohne pdflatex/PDF-Viewer als "Fehler zeigt sich später von selbst")
    // in jede Seiten-Kopfzeile eingebettet -- hier lohnt sich eine echte Bildvalidierung, damit
    // keine beliebige Datei mit .png-Namen ausgeliefert wird.
    if ($registry[$params['name']]['type'] === 'image' && @getimagesize($_FILES['file']['tmp_name']) === false) {
        uploadRedirect('/admin/templates?error=' . urlencode('Datei ist kein gültiges Bild.'));
    }

    @mkdir('/var/www/html/latex-templates', 0775, true);
    $target = '/var/www/html/latex-templates/' . $params['name'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        uploadRedirect('/admin/templates?error=' . urlencode('Datei konnte nicht gespeichert werden.'));
    }
    // favicon-source.png ist nur die Ausgangsgrafik -- die tatsächlich ausgelieferten Dateien
    // (favicon.ico/-16/-32px, apple-touch-icon, icon-192/512) müssen aus ihr neu erzeugt
    // werden, einmalig hier beim Upload (siehe generateFaviconAssets()), nicht bei jedem
    // Seitenaufruf. Schlägt das fehl (z.B. korruptes Bild trotz bestandener getimagesize()-
    // Prüfung oben), bleiben die bisherigen Favicon-Dateien unverändert bestehen statt kaputt
    // zu werden -- die neue Quelldatei ist dann zwar gespeichert, aber ohne sichtbaren Effekt.
    // Neue Papierformular-Vorlage: zwischengespeicherte PDFs (/:rc/beitrittserklaerung.pdf)
    // sofort verwerfen, sonst bliebe bis zu einen Tag die alte Fassung online.
    if ($params['name'] === 'beitrittserklaerung_leer.tex') {
        foreach (glob('/var/www/html/storage/cache/beitrittserklaerung/*.pdf') ?: [] as $cached) { @unlink($cached); }
    }
    if ($params['name'] === 'favicon-source.png') {
        try {
            generateFaviconAssets($target, '/var/www/html/latex-templates');
        } catch (Throwable $e) {
            error_log('generateFaviconAssets() fehlgeschlagen: ' . $e->getMessage());
            uploadRedirect('/admin/templates?error=' . urlencode('Favicon-Quelldatei gespeichert, aber die Icon-Varianten konnten nicht erzeugt werden.'));
        }
    }
    // entity_id ist in audit_log als UUID typisiert -- der Dateiname passt dort nicht rein,
    // steht stattdessen in der Beschreibung.
    logAudit(null, 'template.upload', 'admin_file', null,
        'Datei "' . $registry[$params['name']]['label'] . '" (' . $params['name'] . ') hochgeladen/ersetzt');
    uploadRedirect('/admin/templates?success=' . urlencode($registry[$params['name']]['label'] . ' wurde aktualisiert.'));
});
