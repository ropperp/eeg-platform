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
              style="background:none;border:none;cursor:pointer;padding:.25rem .4rem;border-radius:6px;font-size:1.2rem;color:#6b7280;line-height:1">☰</button>
      <a href="<?= htmlspecialchars(marketingUrl('/')) ?>" class="logo"><img src="/assets/images/logo.png" alt="Strom für alle" class="logo-img"></a>
    </div>

    <nav style="display:flex;align-items:center;gap:1rem">
      <?php
        $ar    = Auth::activeRole();
        $roles = $_SESSION['roles'] ?? [];
        $isPlatformAdmin = Auth::isPlatformAdmin();
        $isManager = Auth::isManager();
        $activeRoleName = $ar['role'] ?? '';
        $currentUserEmail = $_SESSION['user_email'] ?? '';
      ?>

      <?php if ($ar && $activeRoleName !== 'platform_admin'): ?>
        <span style="font-size:.85rem;color:#6b7280"><?= htmlspecialchars($ar['community_name'] ?? '') ?></span>
      <?php elseif ($isPlatformAdmin): ?>
        <span style="font-size:.85rem;color:#16a34a;font-weight:600">Plattform-Admin</span>
      <?php endif; ?>

      <?php if (count($roles) > 1): ?>
        <select onchange="switchRole(this)" style="padding:.3rem .6rem;border-radius:6px;border:1px solid #e5e7eb;font-size:.85rem">
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r['community_id'] ?? '' ?>|<?= $r['role'] ?>"
              <?= ($r === Auth::activeRole()) ? 'selected' : '' ?>>
              <?php if ($r['role'] === 'platform_admin'): ?>
                🔧 Plattform-Admin
              <?php else: ?>
                <?= htmlspecialchars($r['community_name'] ?? '') ?> (<?= $r['role'] ?>)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <form id="switch-form" method="post" action="/portal/switch-role" style="display:none">
          <input type="hidden" name="community_id" id="sw-community">
          <input type="hidden" name="role" id="sw-role">
        </form>
        <script>
          function switchRole(sel) {
            const [cid, role] = sel.value.split('|');
            document.getElementById('sw-community').value = cid;
            document.getElementById('sw-role').value = role;
            document.getElementById('switch-form').submit();
          }
        </script>
      <?php endif; ?>

      <!-- Dark-Mode-Toggle -->
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten"
              style="background:none;border:none;cursor:pointer;font-size:1.15rem;padding:.25rem .3rem;border-radius:6px;line-height:1">🌙</button>

      <!-- Profil-Dropdown -->
      <?php
        $navMember = null;
        if (Auth::activeCommunityId()) {
            $navMember = DB::fetchOne(
                'SELECT id, photo_path, salutation FROM members WHERE user_id = ? AND community_id = ?',
                [Auth::userId(), Auth::activeCommunityId()]
            );
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
          <a href="/portal/profile">✏️ Daten ändern</a>
          <a href="/portal/password">🔑 Passwort ändern</a>
          <hr style="margin:.3rem 0;border-color:#f3f4f6">
          <a href="/portal/logout" style="color:#dc2626">🚪 Abmelden</a>
        </div>
      </div>
    </nav>
  </div>
</header>

<div class="portal-layout">
  <aside class="sidebar" id="sidebar">
    <?php if ($activeRoleName === 'platform_admin'): ?>
      <p class="sidebar-label">Plattform</p>
      <a href="/admin" class="<?= $_SERVER['REQUEST_URI'] === '/admin' || str_contains($_SERVER['REQUEST_URI'], '/admin/communities') || str_contains($_SERVER['REQUEST_URI'], '/admin/users') ? 'active' : '' ?>">
        <span class="sidebar-icon">🔧</span><span class="sidebar-text">Administration</span>
      </a>
      <a href="/admin/log" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/log') ? 'active' : '' ?>">
        <span class="sidebar-icon">📋</span><span class="sidebar-text">Aktivitätslog</span>
      </a>
      <a href="/admin/mail-settings" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/mail-settings') ? 'active' : '' ?>">
        <span class="sidebar-icon">✉️</span><span class="sidebar-text">E-Mail-Einstellungen</span>
      </a>

    <?php elseif ($isManager): ?>
      <p class="sidebar-label">Verwaltung</p>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon">📊</span><span class="sidebar-text">Übersicht</span>
      </a>
      <a href="/portal/members" class="<?= str_contains($_SERVER['REQUEST_URI'], 'members') ? 'active' : '' ?>">
        <span class="sidebar-icon">👥</span><span class="sidebar-text">Mitglieder</span>
      </a>
      <a href="/portal/files" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/files') ? 'active' : '' ?>">
        <span class="sidebar-icon">📁</span><span class="sidebar-text">Dateien</span>
      </a>
      <a href="/portal/billing" class="<?= $_SERVER['REQUEST_URI'] === '/portal/billing' || str_starts_with($_SERVER['REQUEST_URI'], '/portal/billing?') ? 'active' : '' ?>">
        <span class="sidebar-icon">💶</span><span class="sidebar-text">Abrechnung</span>
      </a>
      <a href="/portal/billing/invoices" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/billing/invoices') ? 'active' : '' ?>">
        <span class="sidebar-icon">🧾</span><span class="sidebar-text">Rechnungen</span>
      </a>
      <?php
        $pendingApplications = 0;
        $offeneNotifications = 0;
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
        }
      ?>
      <a href="/portal/applications" class="<?= str_contains($_SERVER['REQUEST_URI'], 'applications') ? 'active' : '' ?>">
        <span class="sidebar-icon">📥</span><span class="sidebar-text">Neuanmeldungen</span>
        <?php if ($pendingApplications > 0): ?>
          <span class="badge badge-yellow" style="margin-left:.4rem"><?= $pendingApplications ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/postfach" class="<?= str_contains($_SERVER['REQUEST_URI'], 'postfach') ? 'active' : '' ?>">
        <span class="sidebar-icon">📬</span><span class="sidebar-text">Postfach</span>
        <?php if ($offeneNotifications > 0): ?>
          <span class="badge badge-yellow" style="margin-left:.4rem"><?= $offeneNotifications ?></span>
        <?php endif; ?>
      </a>
      <a href="/portal/eda/upload" class="<?= str_contains($_SERVER['REQUEST_URI'], 'eda') ? 'active' : '' ?>">
        <span class="sidebar-icon">📂</span><span class="sidebar-text">EDA-Import</span>
      </a>
      <a href="/portal/settings" class="<?= str_contains($_SERVER['REQUEST_URI'], 'settings') ? 'active' : '' ?>">
        <span class="sidebar-icon">⚙️</span><span class="sidebar-text">Einstellungen</span>
      </a>

      <?php if ($isPlatformAdmin): ?>
        <hr style="margin:1rem 0;border-color:#e5e7eb">
        <a href="/admin">
          <span class="sidebar-icon">🔧</span><span class="sidebar-text">Admin</span>
        </a>
      <?php endif; ?>

    <?php else: ?>
      <p class="sidebar-label">Mitglied</p>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon">📊</span><span class="sidebar-text">Mein Verbrauch</span>
      </a>
      <a href="/portal/my/documents" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/my/documents') ? 'active' : '' ?>">
        <span class="sidebar-icon">📄</span><span class="sidebar-text">Meine Dokumente</span>
      </a>
      <a href="/portal/invoices" class="<?= str_contains($_SERVER['REQUEST_URI'], 'invoices') ? 'active' : '' ?>">
        <span class="sidebar-icon">🧾</span><span class="sidebar-text">Rechnungen</span>
      </a>
    <?php endif; ?>
  </aside>

  <main class="portal-content">
    <?= $content ?>
  </main>
</div>

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
function toggleDark() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('darkMode', next === 'dark' ? '1' : '0');
  document.getElementById('theme-toggle').textContent = next === 'dark' ? '☀️' : '🌙';
}
// Initiales Icon setzen
document.getElementById('theme-toggle').textContent =
  document.documentElement.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
</script>

</body>
</html>
