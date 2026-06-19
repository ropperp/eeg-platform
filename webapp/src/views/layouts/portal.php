<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Portal') ?> – EEG-Plattform</title>
  <link rel="stylesheet" href="/assets/css/app.css">
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

      <!-- Profil-Dropdown -->
      <div class="profile-menu" id="profile-menu">
        <button onclick="toggleProfile(event)" class="profile-btn" title="Profil">
          <span class="profile-avatar">👤</span>
          <span class="profile-name"><?= htmlspecialchars($currentUserEmail ?: 'Konto') ?></span>
          <span style="font-size:.7rem;color:#9ca3af">▾</span>
        </button>
        <div class="profile-dropdown" id="profile-dropdown">
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
      <a href="/admin" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin') ? 'active' : '' ?>">
        <span class="sidebar-icon">🔧</span><span class="sidebar-text">Administration</span>
      </a>
      <a href="/portal/password" class="<?= str_contains($_SERVER['REQUEST_URI'], 'password') ? 'active' : '' ?>">
        <span class="sidebar-icon">🔑</span><span class="sidebar-text">Passwort ändern</span>
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
</script>

</body>
</html>
