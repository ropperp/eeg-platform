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
    <a href="/" class="logo">⚡ EEG-Plattform</a>
    <nav>
      <?php $ar = Auth::activeRole(); ?>
      <?php if ($ar): ?>
        <span style="font-size:.85rem;color:#6b7280"><?= htmlspecialchars($ar['community_name']) ?></span>
      <?php endif; ?>

      <?php
        // Rollenwechsler: zeige wenn mehrere Rollen vorhanden
        $roles = $_SESSION['roles'] ?? [];
        if (count($roles) > 1):
      ?>
        <select onchange="switchRole(this)" style="margin-left:1rem;padding:.3rem .6rem;border-radius:6px;border:1px solid #e5e7eb;font-size:.85rem">
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r['community_id'] ?>|<?= $r['role'] ?>"
              <?= ($r === Auth::activeRole()) ? 'selected' : '' ?>>
              <?= htmlspecialchars($r['community_name']) ?> (<?= $r['role'] ?>)
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

      <a href="/portal/logout" style="margin-left:1.5rem;font-size:.875rem">Abmelden</a>
    </nav>
  </div>
</header>

<div class="portal-layout">
  <aside class="sidebar">
    <p style="font-size:.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:.75rem">
      <?= Auth::isManager() ? 'Verwaltung' : 'Mitglied' ?>
    </p>

    <?php if (Auth::isManager()): ?>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">📊 Übersicht</a>
      <a href="/portal/members"   class="<?= str_contains($_SERVER['REQUEST_URI'], 'members') ? 'active' : '' ?>">👥 Mitglieder</a>
      <a href="/portal/billing"   class="<?= str_contains($_SERVER['REQUEST_URI'], 'billing') ? 'active' : '' ?>">💶 Abrechnung</a>
      <a href="/portal/eda/upload" class="<?= str_contains($_SERVER['REQUEST_URI'], 'eda') ? 'active' : '' ?>">📂 EDA-Import</a>
      <a href="/portal/settings"  class="<?= str_contains($_SERVER['REQUEST_URI'], 'settings') ? 'active' : '' ?>">⚙️ Einstellungen</a>
    <?php else: ?>
      <a href="/portal/dashboard" class="<?= str_contains($_SERVER['REQUEST_URI'], 'dashboard') ? 'active' : '' ?>">📊 Mein Verbrauch</a>
      <a href="/portal/invoices"  class="<?= str_contains($_SERVER['REQUEST_URI'], 'invoices') ? 'active' : '' ?>">🧾 Rechnungen</a>
    <?php endif; ?>

    <?php if (Auth::isPlatformAdmin()): ?>
      <hr style="margin:1rem 0;border-color:#e5e7eb">
      <a href="/admin">🔧 Admin</a>
    <?php endif; ?>
  </aside>

  <main class="portal-content">
    <?= $content ?>
  </main>
</div>

</body>
</html>
