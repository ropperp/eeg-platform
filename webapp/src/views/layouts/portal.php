<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <!-- Theme sofort setzen, bevor CSS geladen wird (verhindert Flash) -->
  <script>(function(){var t=localStorage.getItem('eeg-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t;})()</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Portal') ?> – EEG-Plattform</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');})()</script>
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

      <!-- Dark-Mode-Toggle -->
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten"
              style="background:none;border:none;cursor:pointer;font-size:1.15rem;padding:.25rem .3rem;border-radius:6px;line-height:1">🌙</button>

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
