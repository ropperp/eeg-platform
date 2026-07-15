<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Strom für alle') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(ROOT . '/public/assets/css/app.css') ?: time() ?>">
  <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');})()</script>
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <a href="<?= htmlspecialchars(marketingUrl('/')) ?>" class="logo">⚡ Strom für alle</a>
    <nav>
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten"
              style="background:none;border:none;cursor:pointer;font-size:1.15rem;padding:.25rem .3rem;border-radius:6px;line-height:1">🌙</button>
      <a href="<?= htmlspecialchars(marketingUrl('/live')) ?>">Live-Anzeige</a>
      <a href="<?= htmlspecialchars(marketingUrl('/rc108175/kontakt')) ?>">Kontakt</a>
      <?php if (Auth::check()): ?>
        <a href="<?= htmlspecialchars(portalUrl('/portal/dashboard')) ?>">Portal</a>
        <?php if (Auth::isPlatformAdmin()): ?>
          <a href="<?= htmlspecialchars(portalUrl('/admin')) ?>">Admin</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(portalUrl('/portal/logout')) ?>">Abmelden (<?= htmlspecialchars(Auth::userName()) ?>)</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars(marketingUrl('/beitreten')) ?>" class="btn btn-secondary" style="padding:.4rem .9rem">Informieren und Beitreten</a>
        <a href="<?= htmlspecialchars(portalUrl('/portal/login')) ?>" class="btn btn-primary" style="padding:.4rem .9rem">Anmelden</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?= $content ?>

<footer>
  <div class="container">
    <div style="margin-bottom:.75rem">
      <a href="/rc108175/impressum">Impressum</a> ·
      <a href="/rc108175/statuten">Statuten</a> ·
      <a href="/rc108175/datenschutz">Datenschutz</a> ·
      <a href="/rc108175/agb">AGBs</a> ·
      <a href="/rc108175/preisliste">Preisliste</a> ·
      <a href="/rc108175/kontakt">Kontakt</a>
    </div>
    Strom für alle · Diplomarbeit HTL Kärnten 2026/27 · Patrick Ropper, Fabian Amlacher, Alexander Brunner
  </div>
</footer>

<script>
function toggleDark() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('darkMode', next === 'dark' ? '1' : '0');
  document.getElementById('theme-toggle').textContent = next === 'dark' ? '☀️' : '🌙';
}
document.getElementById('theme-toggle').textContent =
  document.documentElement.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
</script>
</body>
</html>
