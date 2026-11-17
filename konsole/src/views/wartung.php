<?php
/** Öffentliche Wartungsseite. @var array{active: bool, message: string, until: string, since: string} $wartung */
$title = 'Wartungsarbeiten – Strom für alle';
$msg = $wartung['message'] !== '' ? $wartung['message'] : 'Wir führen gerade Wartungsarbeiten durch. Bitte versuchen Sie es in Kürze erneut.';
ob_start(); ?>
<section class="card narrow center">
  <h1>Wartungsarbeiten</h1>
  <p class="pre"><?= $h($msg) ?></p>
  <?php if ($wartung['until'] !== ''): ?>
    <p>Voraussichtlich wieder erreichbar: <strong><?= $h(date('d.m.Y, H:i', (int) strtotime($wartung['until']))) ?> Uhr</strong></p>
  <?php endif; ?>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
