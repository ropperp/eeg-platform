<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Portal') ?> – Strom für alle</title>
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(ROOT . '/public/assets/css/app.css') ?: time() ?>">
  <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');})()</script>
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <div style="display:flex;align-items:center;gap:1rem">
      <button id="sidebar-toggle" onclick="toggleSidebar()" title="Menü ein-/ausklappen"
              style="background:none;border:none;cursor:pointer;padding:.25rem .4rem;border-radius:6px;color:var(--gray-600);line-height:1"><?= icon('list') ?></button>
      <a href="<?= htmlspecialchars(marketingUrl('/')) ?>" class="logo">
        <img src="<?= htmlspecialchars(logoAssetUrl('light')) ?>" alt="Strom für alle" class="logo-img logo-img-light">
        <img src="<?= htmlspecialchars(logoAssetUrl('dark')) ?>" alt="Strom für alle" class="logo-img logo-img-dark">
      </a>
    </div>

    <nav style="display:flex;align-items:center;gap:1rem">
      <?php
        $ar    = Auth::activeRole();
        $roles = $_SESSION['roles'] ?? [];
        $isPlatformAdmin = Auth::isPlatformAdmin();
        $isManager = Auth::isManager();
        $activeRoleName = $ar['role'] ?? '';
        $currentUserEmail = $_SESSION['user_email'] ?? '';
        // Pre-Launch-Hinweis-Popup nur für die Mitglieder-Ansicht (Obmänner/Platform-Admins
        // wissen ohnehin, dass wir uns in der Vorbereitungsphase befinden), einmal pro Login
        // (Flag wird bei jedem establishSession() zurückgesetzt, siehe Auth.php) -- Patrick,
        // 30.07.2026. Für Demo-Logins IMMER unterdrückt (Patrick, 06.09.2026, per Screenshot):
        // der Hinweistext richtet sich an echte, neue Mitglieder und ist für eine Präsentation
        // irrelevant -- schlimmer noch, der "Gelesen"-Button dahinter ist ein POST und wäre für
        // einen read-only Demo-Login gar nicht klickbar (siehe Router.php), der Zugang saß also
        // bisher hinter diesem Banner fest.
        $showPrelaunchNotice = !$isPlatformAdmin && !$isManager && !Auth::isDemo() && empty($_SESSION['prelaunch_ack']);
      ?>

      <?php if ($ar && $activeRoleName !== 'platform_admin'): ?>
        <span style="font-size:.85rem;color:var(--gray-600)"><?= htmlspecialchars($ar['community_name'] ?? '') ?></span>
      <?php elseif ($isPlatformAdmin): ?>
        <span style="font-size:.85rem;color:#16a34a;font-weight:600">Plattform-Admin</span>
      <?php endif; ?>

      <?php if (count($roles) > 1): ?>
        <?php
          // Wer wie Patrick gleichzeitig Platform-Admin UND Obmann ist, sieht sonst nicht, dass in
          // der gerade NICHT aktiven Rolle etwas Neues wartet (z.B. ein Support-Ticket beim
          // Obmann, während man gerade als Platform-Admin unterwegs ist) -- Patrick, 05.08.2026.
          // Geprüft werden dieselben "roten" Kategorien wie im Obmann-Sidebar selbst (offene
          // Postfach-Benachrichtigungen + ungelesene Support-Nachrichten); reine Info-Zähler wie
          // Neuanmeldungen (gelb) bleiben bewusst außen vor.
          $anyOtherRoleHasNews = false;
          foreach ($roles as $r) {
              if ($r === $ar || $r['role'] !== 'manager' || empty($r['community_id'])) { continue; }
              DB::setCommunity($r['community_id']);
              $hasNews = (bool)DB::fetchOne(
                  "SELECT (
                      EXISTS(SELECT 1 FROM notifications WHERE community_id = ? AND status = 'offen')
                      OR EXISTS(
                          SELECT 1 FROM support_ticket_messages sm JOIN support_tickets st ON st.id = sm.ticket_id
                          WHERE st.community_id = ? AND sm.is_staff = false
                            AND sm.created_at > COALESCE(st.manager_read_at, '-infinity'::timestamptz)
                      )
                  ) AS x",
                  [$r['community_id'], $r['community_id']]
              )['x'];
              if ($hasNews) { $anyOtherRoleHasNews = true; }
          }
          // Community-Kontext wieder auf die tatsächlich aktive Rolle zurücksetzen -- sonst
          // würden nachfolgende Abfragen dieser Anfrage (z.B. die Sidebar-Badges weiter unten)
          // versehentlich in der zuletzt in der Schleife geprüften Community laufen.
          if ($ar['community_id'] ?? null) { DB::setCommunity($ar['community_id']); }
        ?>
        <div style="position:relative;display:inline-block">
        <select onchange="switchRole(this)" style="padding:.3rem .6rem;border-radius:6px;border:1px solid #e5e7eb;font-size:.85rem">
          <?php foreach ($roles as $r): ?>
            <?php
              // member_id nur bei Demo-Logins mit mehreren Mitglied-Identitäten in derselben
              // Community relevant (siehe migrate_20260905.sql) -- bei allen echten Accounts ist
              // es leer und der Wert bleibt effektiv "community_id|role".
              $isActive = $r['community_id'] === (Auth::activeRole()['community_id'] ?? null)
                       && $r['role'] === (Auth::activeRole()['role'] ?? null)
                       && ($r['member_id'] ?? null) === (Auth::activeRole()['member_id'] ?? null);
            ?>
            <option value="<?= $r['community_id'] ?? '' ?>|<?= $r['role'] ?>|<?= $r['member_id'] ?? '' ?>"
              <?= $isActive ? 'selected' : '' ?>>
              <?php // Kein Emoji-Präfix hier -- dessen Farbe lässt sich innerhalb einer <option>
                    // nicht per CSS auf ein sattes Rot setzen (Patrick, 05.08.2026: "richtiges
                    // Kirschrot" gewünscht, das 🔴-Emoji wirkte blass/falsch). Der verlässliche,
                    // farblich steuerbare Hinweis ist der kleine Punkt direkt am Dropdown unten. ?>
              <?php if ($r['role'] === 'platform_admin'): ?>
                Plattform-Admin
              <?php elseif ($r['role'] === 'member' && !empty($r['member_first_name'])): ?>
                <?= htmlspecialchars($r['community_name'] ?? '') ?> – <?= htmlspecialchars(trim($r['member_first_name'] . ' ' . $r['member_last_name'])) ?>
              <?php else: ?>
                <?= htmlspecialchars($r['community_name'] ?? '') ?> (<?= $r['role'] ?>)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($anyOtherRoleHasNews): ?>
          <span title="Eine deiner anderen Rollen hat Neuigkeiten (Postfach/Support)"
                style="position:absolute;top:-3px;right:-3px;width:10px;height:10px;border-radius:50%;background:#dc2626;border:2px solid var(--white)"></span>
        <?php endif; ?>
        </div>
        <form id="switch-form" method="post" action="/portal/switch-role" style="display:none">
          <input type="hidden" name="community_id" id="sw-community">
          <input type="hidden" name="role" id="sw-role">
          <input type="hidden" name="member_id" id="sw-member">
        </form>
        <script>
          function switchRole(sel) {
            const [cid, role, memberId] = sel.value.split('|');
            document.getElementById('sw-community').value = cid;
            document.getElementById('sw-role').value = role;
            document.getElementById('sw-member').value = memberId;
            document.getElementById('switch-form').submit();
          }
        </script>
      <?php endif; ?>

      <!-- Dark-Mode-Toggle -->
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten" class="theme-toggle-btn">
        <?= icon('moon', 'theme-icon-moon') ?><?= icon('sun', 'theme-icon-sun') ?>
      </button>

      <!-- Profil-Dropdown -->
      <?php
        $navMember = null;
        if (Auth::activeCommunityId()) {
            $navMemberId = activeMemberId(Auth::activeCommunityId());
            if ($navMemberId) {
                $navMember = DB::fetchOne('SELECT id, photo_path, salutation FROM members WHERE id = ?', [$navMemberId]);
            }
        }
        if ($navMember && $navMember['photo_path']) {
            $navAvatarUrl = memberAvatarUrl($navMember['id'], $navMember['photo_path'], $navMember['salutation']);
        } else {
            // Kein eigenes Mitglieds-Foto (oder gar kein Mitgliedsdatensatz, z.B. reiner
            // Manager/Platform-Admin) -- Bild am Login-Account probieren, sonst Default-Avatar
            // passend zur Anrede (falls als Mitglied bekannt).
            $navUser = DB::fetchOne('SELECT photo_path FROM users WHERE id = ?', [Auth::userId()]);
            $navAvatarUrl = !empty($navUser['photo_path'])
                ? userAvatarUrl(Auth::userId(), $navUser['photo_path'])
                : memberAvatarUrl(null, null, $navMember['salutation'] ?? null);
        }
      ?>
      <div class="profile-menu" id="profile-menu">
        <button onclick="toggleProfile(event)" class="profile-btn" title="<?= htmlspecialchars($currentUserEmail ?: 'Konto') ?>">
          <span class="profile-avatar"><img src="<?= htmlspecialchars($navAvatarUrl) ?>" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;display:block"></span>
        </button>
        <div class="profile-dropdown" id="profile-dropdown">
          <a href="/portal/profile"><?= icon('pencil-simple') ?> Daten ändern</a>
          <a href="/portal/password"><?= icon('key') ?> Passwort ändern</a>
          <hr style="margin:.3rem 0;border-color:#f3f4f6">
          <a href="/portal/logout" style="color:#dc2626"><?= icon('sign-out') ?> Abmelden</a>
        </div>
      </div>
    </nav>
  </div>
</header>

<div class="portal-layout" style="<?= $showPrelaunchNotice ? 'filter:blur(4px);pointer-events:none;user-select:none' : '' ?>">
  <aside class="sidebar" id="sidebar">
    <?php if ($activeRoleName === 'platform_admin'): ?>
      <p class="sidebar-label">Plattform</p>
      <a href="/admin" class="<?= $_SERVER['REQUEST_URI'] === '/admin' || str_contains($_SERVER['REQUEST_URI'], '/admin/communities') || str_contains($_SERVER['REQUEST_URI'], '/admin/users') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('wrench') ?></span><span class="sidebar-text">Administration</span>
      </a>
      <a href="/admin/log" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/log') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('clipboard-text') ?></span><span class="sidebar-text">Aktivitätslog</span>
      </a>
      <a href="/admin/mail-settings" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/mail-settings') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('gear') ?></span><span class="sidebar-text">Einstellungen</span>
      </a>
      <a href="/admin/templates" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/templates') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('folder-simple') ?></span><span class="sidebar-text">Dateien</span>
      </a>
      <a href="/admin/backups" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/backups') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('floppy-disk') ?></span><span class="sidebar-text">Backups</span>
      </a>

    <?php elseif ($isManager): ?>
      <p class="sidebar-label">Verwaltung</p>
      <?php
        // Zähler für die Badges unten -- MUSS vor allen Links stehen, die sie lesen (u.a.
        // "Mitglieder" gleich als erstes): stand bis 08.09.2026 weiter unten, nach dem
        // "Mitglieder"-Link, wodurch $membersWithEspError dort noch gar nicht existierte
        // ("Undefined variable"-Warnung, Badge blieb dadurch praktisch immer unsichtbar, egal
        // ob tatsächlich ein ESP-Fehler vorlag).
        $pendingApplications = 0;
        $offeneNotifications = 0;
        $unassignedMeteringPoints = 0;
        $membersWithEspError = 0;
        $unreadSupportMessages = 0;
        if ($ar['community_id'] ?? null) {
          DB::setCommunity($ar['community_id']);
          $pendingApplications = (int)DB::fetchOne(
              "SELECT COUNT(*) AS cnt FROM membership_applications WHERE community_id = ? AND status = 'pending'",
              [$ar['community_id']]
          )['cnt'];
          $offeneNotifications = (int)DB::fetchOne(
              "SELECT COUNT(*) AS cnt FROM notifications WHERE community_id = ? AND status = 'offen'",
              [$ar['community_id']]
          )['cnt'];
          $unassignedMeteringPoints = (int)DB::fetchOne(
              "SELECT COUNT(*) AS cnt FROM metering_points WHERE community_id = ? AND member_id IS NULL",
              [$ar['community_id']]
          )['cnt'];
          // Ungelesene Nachrichten (nicht nur "offene Tickets", siehe Patrick 03.08.2026): eine
          // Mitglieder-Nachricht zaehlt als ungelesen, solange kein Manager seit ihrem Eintreffen
          // die Ticket-Detailseite geoeffnet hat (support_tickets.manager_read_at wird dort
          // gesetzt, siehe GET /portal/support/:id).
          $unreadSupportMessages = (int)DB::fetchOne(
              "SELECT COUNT(*) AS cnt
               FROM support_ticket_messages sm
               JOIN support_tickets st ON st.id = sm.ticket_id
               WHERE st.community_id = ? AND sm.is_staff = false
                 AND sm.created_at > COALESCE(st.manager_read_at, '-infinity'::timestamptz)",
              [$ar['community_id']]
          )['cnt'];
          // Live-Fehlerzähler (kein "gelesen"-Status wie bei Notifications, siehe Patrick
          // 30.07.2026): Anzahl Mitglieder mit mind. einem aktiven Zählpunkt, dessen ESP länger
          // als die Offline-Schwelle nicht mehr gemeldet hat ODER dessen Zähler (P1-Signal)
          // nicht erreichbar ist -- verschwindet automatisch, sobald das Gerät wieder online
          // ist bzw. der Zähler wieder erreichbar ist. War bis 31.08.2026 auf esp_online
          // angewiesen -- genau das flackerte laut Patrick trotz durchgehender Live-Daten
          // (19.08.2026, siehe hat_esp_fehler-Kommentar in /portal/members) und erzeugte hier
          // False-Positives, die diese Badge nie hatte, weil sie bis zum Reihenfolge-Fix vom
          // 08.09.2026 wegen der Undefined-Variable praktisch nie sichtbar war -- der erste
          // echte Blick auf eine tatsächlich gerenderte Zahl (31.08.2026, Patrick: Badge zeigt
          // "1", aber kein Mitglied in der Liste als fehlerhaft markiert) deckte das sofort auf.
          // Jetzt exakt dieselbe, bereits korrigierte Logik wie hat_esp_fehler in /portal/members
          // (kein esp_online mehr, dafür meter_code IS NOT NULL) UND wie die Status-Kachelzeile
          // im Obmann-Dashboard (mirror_source_metering_point_id IS NULL, sonst zählen
          // Demo-Zählpunkte mit, siehe migrate_20260906.sql-Kommentar dort).
          $espOfflineMinutesNav = espOfflineAfterMinutes();
          $membersWithEspError = (int)DB::fetchOne(
              "SELECT COUNT(DISTINCT mp.member_id) AS cnt
               FROM metering_points mp
               WHERE mp.community_id = ? AND mp.active = true AND mp.member_id IS NOT NULL
                 AND mp.meter_code IS NOT NULL AND mp.esp_last_seen_at IS NOT NULL
                 AND mp.mirror_source_metering_point_id IS NULL
                 AND NOT (mp.esp_last_seen_at > now() - (? || ' minutes')::interval AND mp.meter_reachable)",
              [$ar['community_id'], $espOfflineMinutesNav]
          )['cnt'];
        }
      ?>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('chart-bar') ?></span><span class="sidebar-text">Übersicht</span>
      </a>
      <a href="/portal/members" class="<?= str_contains($_SERVER['REQUEST_URI'], 'members') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('users-three') ?></span><span class="sidebar-text">Mitglieder</span>
        <?php if ($membersWithEspError > 0): ?>
          <span class="badge badge-red" style="margin-left:.4rem"><?= $membersWithEspError ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/files" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/files') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('folder-simple') ?></span><span class="sidebar-text">Dateien</span>
      </a>
      <a href="/portal/billing" class="<?= $_SERVER['REQUEST_URI'] === '/portal/billing' || str_starts_with($_SERVER['REQUEST_URI'], '/portal/billing?') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('currency-eur') ?></span><span class="sidebar-text">Abrechnung</span>
      </a>
      <a href="/portal/billing/invoices" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/billing/invoices') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('receipt') ?></span><span class="sidebar-text">Rechnungen</span>
      </a>
      <a href="/portal/applications" class="<?= str_contains($_SERVER['REQUEST_URI'], 'applications') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('download-simple') ?></span><span class="sidebar-text">Neuanmeldungen</span>
        <?php if ($pendingApplications > 0): ?>
          <span class="badge badge-yellow" style="margin-left:.4rem"><?= $pendingApplications ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/postfach" class="<?= str_contains($_SERVER['REQUEST_URI'], 'postfach') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('envelope-simple') ?></span><span class="sidebar-text">Postfach</span>
        <?php if ($offeneNotifications > 0): ?>
          <span class="badge badge-red" style="margin-left:.4rem"><?= $offeneNotifications ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/support" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/support') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('chat-circle-text') ?></span><span class="sidebar-text">Support-Tickets</span>
        <?php if ($unreadSupportMessages > 0): ?>
          <span class="badge badge-red" style="margin-left:.4rem"><?= $unreadSupportMessages ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/eda/upload" class="<?= str_contains($_SERVER['REQUEST_URI'], 'eda') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('folder-open') ?></span><span class="sidebar-text">EDA-Import</span>
      </a>
      <?php if ($unassignedMeteringPoints > 0): ?>
      <a href="/portal/metering-points/unassigned" class="<?= str_contains($_SERVER['REQUEST_URI'], 'metering-points') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('warning-circle') ?></span><span class="sidebar-text">Zählpunkte ohne Zuordnung</span>
        <span class="badge badge-yellow" style="margin-left:.4rem"><?= $unassignedMeteringPoints ?></span>
      </a>
      <?php endif; ?>
      <a href="/portal/settings" class="<?= str_contains($_SERVER['REQUEST_URI'], 'settings') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('gear') ?></span><span class="sidebar-text">Einstellungen</span>
      </a>

      <?php if ($isPlatformAdmin): ?>
        <hr style="margin:1rem 0;border-color:#e5e7eb">
        <a href="/admin">
          <span class="sidebar-icon"><?= icon('wrench') ?></span><span class="sidebar-text">Admin</span>
        </a>
      <?php endif; ?>

    <?php else: ?>
      <p class="sidebar-label">Mitglied</p>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('chart-bar') ?></span><span class="sidebar-text">Mein Verbrauch</span>
      </a>
      <a href="/portal/my/documents" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/my/documents') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('file-text') ?></span><span class="sidebar-text">Meine Dokumente</span>
      </a>
      <a href="/portal/invoices" class="<?= str_contains($_SERVER['REQUEST_URI'], 'invoices') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('receipt') ?></span><span class="sidebar-text">Rechnungen</span>
      </a>
      <a href="/portal/my/api-keys" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/my/api-keys') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('plug') ?></span><span class="sidebar-text">API-Zugänge</span>
      </a>
      <a href="/portal/my/support" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/my/support') ? 'active' : '' ?>">
        <span class="sidebar-icon"><?= icon('chat-circle-text') ?></span><span class="sidebar-text">Support</span>
      </a>
    <?php endif; ?>
  </aside>

  <main class="portal-content">
    <?php if (Auth::isDemo()): ?>
      <div class="alert alert-warning" style="margin-bottom:1rem;display:flex;align-items:center;gap:.5rem">
        <?= icon('eye') ?>
        <span>Demo-Zugang -- reine Ansicht zur Präsentation, keine Änderungen möglich, keine echten Mitgliederdaten.</span>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>

<?php if ($showPrelaunchNotice): ?>
<div style="position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:1000;padding:1rem">
  <div class="card" style="max-width:32rem;width:100%;padding:1.75rem">
    <h2 style="margin-bottom:.75rem"><?= icon('lightning') ?> Willkommen! Ein kurzer Hinweis, bevor es losgeht</h2>
    <p style="font-size:.9rem;line-height:1.6;margin-bottom:.75rem">
      Diese Plattform befindet sich aktuell noch in der Entwicklungs- und Vorbereitungsphase
      (Pre-Launch). Wir arbeiten laufend daran, sie zu verbessern und neue Funktionen zu
      ergänzen — wundern Sie sich daher bitte nicht, wenn sich Ansicht oder Inhalte von einem
      Tag auf den anderen ändern.
    </p>
    <p style="font-size:.9rem;line-height:1.6;margin-bottom:1.25rem">
      Haben Sie eine Idee oder einen Wunsch, was auf der Plattform noch fehlen könnte? Wir
      freuen uns über Ihr Feedback im Support-Bereich!
    </p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
      <a href="/portal/my/support" class="btn btn-secondary"><?= icon('chat-circle-text') ?> Zum Support</a>
      <form method="post" action="/portal/ack-prelaunch" style="margin-left:auto">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
        <button type="submit" class="btn btn-primary">Gelesen, weiter zur Plattform</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ─── Sidebar toggle ───────────────────────────────────────────────
const SIDEBAR_KEY = 'sidebarCollapsed';
const sidebar = document.getElementById('sidebar');

function toggleSidebar() {
  const collapsed = sidebar.classList.toggle('collapsed');
  localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
}

// Zustand wiederherstellen
if (localStorage.getItem(SIDEBAR_KEY) === '1') {
  sidebar.classList.add('collapsed');
}

// ─── Profil-Dropdown ─────────────────────────────────────────────
function toggleProfile(e) {
  e.stopPropagation();
  document.getElementById('profile-dropdown').classList.toggle('open');
}
document.addEventListener('click', () => {
  document.getElementById('profile-dropdown').classList.remove('open');
});

// ─── Löschbestätigung (Superadmin-Aktionen) ───────────────────────
function confirmDangerDelete(itemLabel) {
  const input = prompt('ACHTUNG: ' + itemLabel + ' wird unwiderruflich gelöscht.\nBitte zur Bestätigung "LOESCHEN" eingeben:');
  if (input === null) return false;
  if (input !== 'LOESCHEN') {
    alert('Löschung abgebrochen — Eingabe stimmte nicht mit "LOESCHEN" überein.');
    return false;
  }
  return true;
}

// ─── Dark-Mode-Toggle ─────────────────────────────────────────────
// Icon-Umschaltung (Mond/Sonne) läuft rein über CSS anhand [data-theme] (siehe app.css),
// kein Textaustausch mehr nötig.
function toggleDark() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('darkMode', next === 'dark' ? '1' : '0');
}

// ─── CSRF-Schutz (OWASP-Audit 13.08.2026) ─────────────────────────
// Fügt automatisch ein verstecktes Token-Feld in jedes method="post"-Formular der Seite ein,
// statt jede der ~70 Formular-Views einzeln anzufassen. Geprüft wird zentral in
// Router::dispatch() (siehe functions.php: csrfToken()/csrfValid()). Gleiches Muster wie in
// layouts/base.php.
(function () {
  var token = <?= json_encode(csrfToken()) ?>;
  document.querySelectorAll('form[method="post" i]').forEach(function (form) {
    if (form.querySelector('input[name="csrf_token"]')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = token;
    form.appendChild(input);
  });
})();
</script>

</body>
</html>
