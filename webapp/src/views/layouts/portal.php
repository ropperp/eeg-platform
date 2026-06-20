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
        <button onclick="toggleProfile(event)" class="profile-btn" title="<?= htmlspecialchars($currentUserEmail ?: 'Konto') ?>">
          <span class="profile-avatar">👤</span>
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
