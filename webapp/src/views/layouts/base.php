<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'EEG-Plattform') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <a href="/" class="logo">⚡ EEG-Plattform</a>
    <nav>
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

</body>
</html>
