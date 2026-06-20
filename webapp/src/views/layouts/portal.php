<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <!-- Theme sofort setzen, bevor CSS geladen wird (verhindert Flash) -->
  <script>(function(){var t=localStorage.getItem('eeg-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t;})()</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Portal') ?> – EEG-Plattform</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <!-- Kritisches Layout inline: resistent gegen gecachte app.css auf dem Server -->
  <style>
    .profile-menu { position: relative; }
    .profile-btn  { display:flex;align-items:center;justify-content:center;width:36px;height:36px;
                    background:#f3f4f6;border:2px solid #e5e7eb;border-radius:9999px;padding:0;
                    cursor:pointer;font-size:1.2rem;line-height:1;
                    transition:background .15s,border-color .15s; }
    .profile-btn:hover { background:#dcfce7;border-color:#16a34a; }
    .profile-dropdown { display:none;position:absolute;right:0;top:calc(100% + 6px);
                        background:var(--white,#fff);border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;
                        box-shadow:0 4px 16px rgba(0,0,0,.1);min-width:190px;z-index:200;padding:.4rem; }
    .profile-dropdown a { display:block;padding:.5rem .75rem;border-radius:6px;
                          font-size:.875rem;color:var(--gray-800,#374151);white-space:nowrap;text-decoration:none; }
    .profile-dropdown a:hover { background:var(--gray-100,#f3f4f6); }
    .sidebar-text { transition:opacity .15s; }
    .sidebar--collapsed .sidebar-text { opacity:0;width:0;overflow:hidden; }
    .sidebar--collapsed .sidebar-label { opacity:0;height:0;margin:0;overflow:hidden; }
  </style>
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <div style="display:flex;align-items:center;gap:1rem">
      <button id="sidebar-toggle" onclick="toggleSidebar()" title="Menü ein-/ausklappen"
              style="background:none;border:none;cursor:pointer;padding:.25rem .4rem;border-radius:6px;font-size:1.2rem;color:#6b7280;line-height:1">☰</button>
      <a href="/" class="logo">⚡ EEG-Plattform</a>
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

      <!-- Theme-Toggle -->
      <button id="theme-toggle" onclick="toggleTheme()" title="Farbschema wechseln"
              style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:.25rem .4rem;
                     border-radius:6px;color:var(--gray-600,#6b7280);line-height:1">
        <span id="theme-icon">🌙</span>
      </button>

      <!-- Postfach-Badge -->
      <?php
        $notifyCount = 0;
        try { $notifyCount = Notify::unreadCount(); } catch (Throwable) {}
      ?>
      <a href="/portal/postfach" title="Postfach"
         style="position:relative;display:flex;align-items:center;text-decoration:none;color:#374151;padding:.25rem .4rem;border-radius:6px">
        <span style="font-size:1.15rem">📬</span>
        <?php if ($notifyCount > 0): ?>
          <span style="position:absolute;top:-2px;right:-2px;background:#dc2626;color:#fff;border-radius:50%;
                       font-size:.6rem;font-weight:700;min-width:16px;height:16px;display:flex;
                       align-items:center;justify-content:center;line-height:1;padding:0 2px">
            <?= $notifyCount > 99 ? '99+' : $notifyCount ?>
          </span>
        <?php endif; ?>
      </a>

      <!-- Profil-Dropdown -->
      <div class="profile-menu" id="profile-menu">
        <button onclick="toggleProfile(event)" class="profile-btn"
                title="<?= htmlspecialchars($currentUserEmail ?: 'Konto') ?>">
          <span class="profile-avatar">👤</span>
        </button>
        <!-- style="display:none" inline: verhindert Layout-Fehler bei gecachtem app.css -->
        <div class="profile-dropdown" id="profile-dropdown" style="display:none">
          <?php if (!$isPlatformAdmin): ?>
          <a href="/portal/profile">✏️ Daten ändern</a>
          <?php endif; ?>
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
      <a href="/admin" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/admin') && !str_contains($_SERVER['REQUEST_URI'], '/audit') ? 'active' : '' ?>">
        <span class="sidebar-icon">🔧</span><span class="sidebar-text">Administration</span>
      </a>
      <a href="/admin/audit" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/audit') ? 'active' : '' ?>">
        <span class="sidebar-icon">🗂</span><span class="sidebar-text">Protokoll</span>
      </a>
      <a href="/portal/postfach" class="<?= str_contains($_SERVER['REQUEST_URI'], '/postfach') ? 'active' : '' ?>">
        <span class="sidebar-icon">📬</span><span class="sidebar-text">Postfach
          <?php if ($notifyCount > 0): ?>
            <span style="background:#dc2626;color:#fff;border-radius:10px;font-size:.7rem;padding:1px 6px;margin-left:4px"><?= $notifyCount ?></span>
          <?php endif; ?>
        </span>
      </a>

    <?php elseif ($isManager): ?>
      <p class="sidebar-label">Verwaltung</p>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon">📊</span><span class="sidebar-text">Übersicht</span>
      </a>
      <a href="/portal/members" class="<?= str_contains($_SERVER['REQUEST_URI'], 'members') ? 'active' : '' ?>">
        <span class="sidebar-icon">👥</span><span class="sidebar-text">Mitglieder</span>
      </a>
      <a href="/portal/billing" class="<?= str_contains($_SERVER['REQUEST_URI'], 'billing') ? 'active' : '' ?>">
        <span class="sidebar-icon">💶</span><span class="sidebar-text">Abrechnung</span>
      </a>
      <a href="/portal/eda/upload" class="<?= str_contains($_SERVER['REQUEST_URI'], 'eda') ? 'active' : '' ?>">
        <span class="sidebar-icon">📂</span><span class="sidebar-text">EDA-Import</span>
      </a>
      <a href="/portal/settings" class="<?= str_contains($_SERVER['REQUEST_URI'], 'settings') ? 'active' : '' ?>">
        <span class="sidebar-icon">⚙️</span><span class="sidebar-text">Einstellungen</span>
      </a>
      <a href="/portal/postfach" class="<?= str_contains($_SERVER['REQUEST_URI'], '/postfach') ? 'active' : '' ?>">
        <span class="sidebar-icon">📬</span><span class="sidebar-text">Postfach
          <?php if ($notifyCount > 0): ?>
            <span style="background:#dc2626;color:#fff;border-radius:10px;font-size:.7rem;padding:1px 6px;margin-left:4px"><?= $notifyCount ?></span>
          <?php endif; ?>
        </span>
      </a>
      <a href="/portal/audit" class="<?= str_contains($_SERVER['REQUEST_URI'], '/portal/audit') ? 'active' : '' ?>">
        <span class="sidebar-icon">🗂</span><span class="sidebar-text">Protokoll</span>
      </a>

    <?php else: ?>
      <p class="sidebar-label">Mitglied</p>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">
        <span class="sidebar-icon">📊</span><span class="sidebar-text">Mein Verbrauch</span>
      </a>
      <a href="/portal/invoices" class="<?= str_contains($_SERVER['REQUEST_URI'], 'invoices') ? 'active' : '' ?>">
        <span class="sidebar-icon">🧾</span><span class="sidebar-text">Rechnungen</span>
      </a>
      <a href="/portal/postfach" class="<?= str_contains($_SERVER['REQUEST_URI'], '/postfach') ? 'active' : '' ?>">
        <span class="sidebar-icon">📬</span><span class="sidebar-text">Postfach
          <?php if ($notifyCount > 0): ?>
            <span style="background:#dc2626;color:#fff;border-radius:10px;font-size:.7rem;padding:1px 6px;margin-left:4px"><?= $notifyCount ?></span>
          <?php endif; ?>
        </span>
      </a>
    <?php endif; ?>
  </aside>

  <main class="portal-content">
    <?= $content ?>
  </main>
</div>

<script>
// ─── Sidebar toggle (BEM: sidebar--collapsed) ─────────────────────
const SIDEBAR_KEY = 'sidebarCollapsed';
const sidebar = document.getElementById('sidebar');

function toggleSidebar() {
  const collapsed = sidebar.classList.toggle('sidebar--collapsed');
  localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
}

if (localStorage.getItem(SIDEBAR_KEY) === '1') {
  sidebar.classList.add('sidebar--collapsed');
}

// ─── Profil-Dropdown ─────────────────────────────────────────────
const profileDropdown = document.getElementById('profile-dropdown');

function toggleProfile(e) {
  e.stopPropagation();
  profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
}

document.addEventListener('click', function () {
  profileDropdown.style.display = 'none';
});

// ─── Dark / Light Theme ───────────────────────────────────────────
const themeIcon = document.getElementById('theme-icon');

function applyTheme(dark) {
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  if (themeIcon) themeIcon.textContent = dark ? '☀️' : '🌙';
}

function toggleTheme() {
  const isDark = document.documentElement.dataset.theme === 'dark';
  localStorage.setItem('eeg-theme', isDark ? 'light' : 'dark');
  applyTheme(!isDark);
}

// Initiales Icon setzen (Theme wurde bereits im <head> gesetzt)
applyTheme(document.documentElement.dataset.theme === 'dark');
</script>

</body>
</html>
