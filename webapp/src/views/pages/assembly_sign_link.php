<?php
/**
 * Unterschrift per Einmal-Link (ohne Login).
 * @var bool $invalid  Link ungültig/abgelaufen/benutzt
 * Sonst: @var bool $done, @var array $a, @var array $signer, @var list<array> $items, @var array $community,
 *        @var string $token, @var string $error
 */
$pageTitle = 'Protokoll unterschreiben – Strom für alle';
$pageNoindex = true;
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
ob_start();
?>
<section style="max-width:760px;margin:2rem auto;padding:0 1rem">
<?php if ($invalid): ?>
  <div class="card">
    <h2 style="margin-top:0">Link nicht mehr gültig</h2>
    <p>Dieser Link zum Unterschreiben ist abgelaufen, wurde bereits benutzt oder das Protokoll wurde inzwischen
       geändert. Bitte wenden Sie sich an den Obmann bzw. die Obfrau Ihrer Energiegemeinschaft und lassen Sie sich
       einen neuen Link schicken.</p>
  </div>
<?php elseif ($done): ?>
  <div class="card">
    <h2 style="margin-top:0"><?= icon('check-circle') ?> Danke für Ihre Unterschrift</h2>
    <p>Ihre Unterschrift wurde gespeichert. Sobald alle unterschrieben haben, wird das Protokoll abgeschlossen und
       in der Energiegemeinschaft abgelegt. Sie können dieses Fenster schließen.</p>
  </div>
<?php else: ?>
  <div class="card">
    <p style="margin:0;color:var(--gray-600);font-size:.9rem"><?= $h($community['name'] ?? '') ?></p>
    <h2 style="margin:.25rem 0 0">Protokoll: <?= $h($a['title']) ?> vom <?= date('d.m.Y', strtotime($a['held_on'])) ?></h2>
    <p style="margin-top:.5rem">Unterschrift als <strong><?= $h($signer['role_label']) ?></strong>: <?= $h($signer['name']) ?></p>
    <p><a href="/portal/unterschrift/versammlung/<?= $h($token) ?>/vorschau" target="_blank" rel="noopener" class="btn" style="background:var(--gray-100);color:var(--gray-700)">
      <?= icon('file-text') ?> Protokoll als PDF lesen</a></p>

    <h3>Tagesordnung</h3>
    <ol>
      <?php foreach ($items as $it): ?>
        <li style="margin-bottom:.75rem">
          <strong><?= $h($it['title']) ?></strong>
          <?php if (!empty($it['body'])): ?><div style="white-space:pre-line;color:var(--gray-700)"><?= $h($it['body']) ?></div><?php endif; ?>
          <?php if (!empty($it['resolution'])): ?><div style="white-space:pre-line;margin-top:.25rem"><em>Beschluss:</em> <?= $h($it['resolution']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= $h($error) ?></div><?php endif; ?>

    <form method="post" action="/portal/unterschrift/versammlung/<?= $h($token) ?>" data-signature-form>
      <?= csrfField() ?>
      <label style="font-size:.85rem;display:block;margin-bottom:.4rem">Ihre Unterschrift (bitte auf der gestrichelten Linie)</label>
      <div class="sig-pad-wrap">
        <canvas data-signature-pad width="600" height="180" style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
        <div class="sig-pad-guide"></div>
      </div>
      <div style="margin:.5rem 0 1rem">
        <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem" data-signature-clear>Löschen</button>
      </div>
      <input type="hidden" name="signature_image">
      <button type="submit" class="btn btn-primary"><?= icon('signature') ?> Protokoll unterschreiben</button>
    </form>
  </div>
  <script src="<?= htmlspecialchars(assetUrl('/assets/js/signature-pad-trim.js')) ?>" defer></script>
  <script src="<?= htmlspecialchars(assetUrl('/assets/js/assembly-sign.js')) ?>" defer></script>
<?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/base.php';
