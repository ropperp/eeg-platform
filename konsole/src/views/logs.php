<?php
/** @var string $name @var string $text @var string $user */
$title = 'Logs ' . $name;
ob_start(); ?>
<p><a href="/">← Zurück zur Übersicht</a></p>
<section class="card">
  <h2>Letzte Logzeilen: <?= $h($name) ?></h2>
  <pre class="log"><?= $h($text !== '' ? $text : '(keine Ausgabe)') ?></pre>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
