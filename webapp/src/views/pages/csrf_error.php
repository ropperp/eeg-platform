<?php $pageTitle = 'Sitzung abgelaufen'; ob_start(); ?>
<div style="text-align:center;padding:6rem 2rem">
  <div style="font-size:4rem;margin-bottom:1rem"><?= icon('warning-circle') ?></div>
  <h1 style="font-size:2rem;margin-bottom:.5rem">Sitzung abgelaufen</h1>
  <p style="color:var(--gray-600);margin-bottom:1.5rem">
    Dieses Formular war zu lange geöffnet oder wurde von einer anderen Seite aus abgeschickt.
    Bitte die vorherige Seite neu laden und erneut versuchen.
  </p>
  <a href="javascript:history.back()" class="btn btn-primary">Zurück</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
