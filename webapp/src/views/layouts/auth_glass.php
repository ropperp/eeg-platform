<?php
// Gemeinsame Hülle der Anmelde-Seiten im neuen Look (Login, Passwort vergessen, neues Passwort,
// 2FA-Bestätigung) -- dunkle Glas-Karte mit abgeschrägten Ecken vor Lichtstreifen, siehe
// assets/css/login-glass.css (Patrick, 14.11.2026). Nur im Glas-Design; sonst layouts/base.php.
// Erwartet: $pageTitle, $authStage (HTML der Karte(n) inkl. .lg-stage) und optional $authScripts.
// WICHTIG: jedes POST-Formular braucht csrfField() -- das Auto-Einfügen per Skript aus
// base.php/portal.php gibt es hier nicht (Vorfall 14.11.2026: Glas-Login ohne Token = CSRF-Fehlerseite).
$v = fn(string $f) => @filemtime(ROOT . '/public/assets/' . $f) ?: time();
?><!DOCTYPE html>
<html lang="de" data-design="glas">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Anmelden') ?> – Strom für alle</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#030b16">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= $v('css/app.css') ?>">
  <link rel="stylesheet" href="/assets/css/login-glass.css?v=<?= $v('css/login-glass.css') ?>">
</head>
<body class="lg-body">
  <div class="lg-streaks" aria-hidden="true"><span></span><span></span><span></span></div>
  <main class="lg-main">
    <a href="<?= htmlspecialchars(marketingUrl('/')) ?>" class="lg-logo">
      <img src="<?= htmlspecialchars(logoAssetUrl('dark')) ?>" alt="Strom für alle">
    </a>
    <?= $authStage ?>
    <p class="lg-foot"><?= htmlspecialchars(copyrightLine()) ?> · <a href="<?= htmlspecialchars(marketingUrl('/impressum')) ?>">Impressum</a> · <a href="<?= htmlspecialchars(marketingUrl('/datenschutz')) ?>">Datenschutz</a></p>
  </main>
  <?= $authScripts ?? '' ?>
  <script src="/assets/js/password-toggle.js?v=<?= $v('js/password-toggle.js') ?>"></script>
</body>
</html>
