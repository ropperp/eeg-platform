<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'EEG-Plattform') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');})()</script>
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <a href="/" class="logo">⚡ EEG-Plattform</a>
    <nav>
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten"
              style="background:none;border:none;cursor:pointer;font-size:1.15rem;padding:.25rem .3rem;border-radius:6px;line-height:1">🌙</button>
      <a href="/live">Live-Anzeige</a>
      <?php if (Auth::check()): ?>
        <a href="/portal/dashboard">Portal</a>
        <?php if (Auth::isPlatformAdmin()): ?>
          <a href="/admin">Admin</a>
        <?php endif; ?>
        <a href="/portal/logout">Abmelden (<?= htmlspecialchars(Auth::userName()) ?>)</a>
      <?php else: ?>
        <a href="/portal/login" class="btn btn-primary" style="padding:.4rem .9rem">Anmelden</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?= $content ?>

<footer>
  <div class="container">
    EEG-Plattform · Diplomarbeit HTL Kärnten 2026/27 · Patrick Ropper, Fabian Amlacher, Alexander Brunner
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
