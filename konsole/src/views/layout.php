<?php
/**
 * Gemeinsame Hülle. Erwartet $title, $inner (fertiges HTML), $h.
 * Optional: $bare (true = vollflächige Seite ohne Kopfzeile, z. B. Anmeldung/Wartung),
 * $who (Name des angemeldeten Admins), $csrf (für "Abmelden"), $refresh (Sekunden, Seite neu laden).
 */
$bare ??= false;
?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#1a2033">
  <?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="<?= (int) $refresh ?>"><?php endif; ?>
  <title><?= $h($title) ?></title>
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="stylesheet" href="/konsole.css">
  <script src="/konsole.js" defer></script>
</head>
<body>
<?php if ($bare): ?>
  <?= $inner ?>
<?php else: ?>
  <header class="top">
    <div class="top-inner">
      <img src="/logo-white.png" alt="strom für alle">
      <span class="title">Steuerzentrale</span>
      <?php if (!empty($who)): ?>
        <span class="who">
          <span><?= $h($who) ?></span>
          <form method="post" action="/abmelden"><input type="hidden" name="csrf" value="<?= $h($csrf ?? '') ?>">
            <button type="submit" class="btn">Abmelden</button></form>
        </span>
      <?php endif; ?>
    </div>
  </header>
  <main><?= $inner ?></main>
<?php endif; ?>
</body>
</html>
