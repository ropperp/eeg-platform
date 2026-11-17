<?php
/** @var array<string, mixed> $a @var array<string, mixed> $signer @var list<array<string, mixed>> $items @var string $error */
$pageTitle = 'Protokoll unterschreiben'; ob_start();
$base = '/portal/versammlungen/' . htmlspecialchars($a['id']);
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
?>
<p style="margin-bottom:.5rem"><a href="<?= $base ?>#unterschriften">← Zurück zum Protokoll</a></p>
<h2 style="margin-bottom:.25rem"><?= icon('signature') ?> Unterschrift: <?= $h($signer['name']) ?></h2>
<p style="color:var(--gray-600);margin-top:0"><?= $h($signer['role_label']) ?> · <?= $h($a['title']) ?> vom <?= date('d.m.Y', strtotime($a['held_on'])) ?></p>

<?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= $h($error) ?></div><?php endif; ?>

<?php if ($signer['signed_at'] !== null): ?>
  <div class="alert alert-success"><?= $h($signer['name']) ?> hat bereits unterschrieben.</div>
<?php elseif ($a['status'] !== 'signing'): ?>
  <div class="alert alert-error">Das Protokoll ist nicht zur Unterschrift freigegeben.</div>
<?php else: ?>
<div class="card">
  <p style="margin-top:0">
    Bitte das Gerät an <strong><?= $h($signer['name']) ?></strong> weitergeben. Den Inhalt zeigt die
    <a href="<?= $base ?>/pdf" target="_blank" rel="noopener">PDF-Vorschau</a>
    (Tagesordnung: <?= implode(', ', array_map(fn($i) => $h($i['title']), $items)) ?>).
  </p>
  <form method="post" action="<?= $base ?>/unterschreiben/<?= $h($signer['id']) ?>" data-signature-form>
    <label style="font-size:.85rem;display:block;margin-bottom:.4rem">Unterschrift (bitte auf der gestrichelten Linie)</label>
    <div class="sig-pad-wrap">
      <canvas data-signature-pad width="600" height="180" style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
      <div class="sig-pad-guide"></div>
    </div>
    <div style="margin:.5rem 0 1rem">
      <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem" data-signature-clear>Löschen</button>
    </div>
    <input type="hidden" name="signature_image">
    <button type="submit" class="btn btn-primary"><?= icon('check-circle') ?> Unterschreiben</button>
  </form>
</div>
<script src="<?= htmlspecialchars(assetUrl('/assets/js/signature-pad-trim.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(assetUrl('/assets/js/assembly-sign.js')) ?>" defer></script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
