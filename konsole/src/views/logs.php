<?php
/** @var string $name @var string $text @var string $user @var string $csrf */
$title = 'Logs ' . $name . ' – Steuerzentrale';
$who = $user;
ob_start(); ?>
<a class="back" href="/">← Zurück zur Übersicht</a>
<section class="card">
  <h2>Letzte Logzeilen: <?= $h($name) ?></h2>
  <p class="muted">Die neuesten 150 Zeilen, neueste unten. <a href="/logs?dienst=<?= $h(rawurlencode($name)) ?>">Neu laden</a></p>
  <pre class="log"><?= $h($text !== '' ? $text : '(keine Ausgabe)') ?></pre>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
