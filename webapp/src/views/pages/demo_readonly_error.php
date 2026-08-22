<?php $pageTitle = 'Nur Lesezugriff'; ob_start(); ?>
<div style="text-align:center;padding:6rem 2rem">
  <div style="font-size:4rem;margin-bottom:1rem"><?= icon('eye') ?></div>
  <h1 style="font-size:2rem;margin-bottom:.5rem">Nur Lesezugriff</h1>
  <p style="color:var(--gray-600);margin-bottom:1.5rem">
    Dieser Demo-Zugang dient nur zur Ansicht (Präsentation/Review) und kann keine Daten
    verändern. Diese Aktion wurde deshalb nicht ausgeführt.
  </p>
  <a href="javascript:history.back()" class="btn btn-primary">Zurück</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
