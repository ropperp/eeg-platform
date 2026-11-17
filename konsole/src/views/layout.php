<?php
/** Gemeinsame Hülle. Erwartet $title, $inner (fertiges HTML), $h. */
?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $h($title) ?></title>
  <link rel="stylesheet" href="/konsole.css">
  <script src="/konsole.js" defer></script>
</head>
<body>
  <header class="top"><strong>strom für alle</strong> · Steuerzentrale</header>
  <main><?= $inner ?></main>
</body>
</html>
