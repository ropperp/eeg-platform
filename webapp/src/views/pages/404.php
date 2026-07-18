<?php $pageTitle = 'Seite nicht gefunden'; ob_start(); ?>
<div style="text-align:center;padding:6rem 2rem">
  <div style="font-size:4rem;margin-bottom:1rem">🔌</div>
  <h1 style="font-size:2rem;margin-bottom:.5rem">Seite nicht gefunden</h1>
  <p style="color:var(--gray-600);margin-bottom:1.5rem">Die angeforderte Seite existiert nicht.</p>
  <a href="/" class="btn btn-primary">Zur Startseite</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
