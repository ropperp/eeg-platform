<?php
$pageTitle = 'Gutschriften' . ($run ? ' – ' . $run['quartal'] : '');
ob_start();
?>

<h2 style="margin-bottom:.25rem"><?= icon('bank') ?> Offene Gutschriften<?= $run ? ' -- ' . htmlspecialchars($run['quartal']) : '' ?></h2>
<p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1.5rem">
  Mitglieder mit noch nicht überwiesenem Guthaben<?= $run ? ' aus diesem Abrechnungslauf' : ' (über alle Abrechnungsläufe)' ?>.
  Für jedes Feld gibt es einen eigenen „Kopieren"-Button -- damit lässt sich der Wert direkt in
  das entsprechende Feld einer neuen Überweisung im Online-Banking einfügen, ohne ihn abtippen zu
  müssen. Sobald eine Überweisung erledigt ist, verschwindet die Gutschrift aus dieser Liste.
  Wer stattdessen alle offenen Gutschriften auf einmal überweisen möchte: die SEPA-
  Sammelüberweisung unten als Datei herunterladen und im eigenen Online-Banking hochladen --
  dann entfällt das einzelne Abtippen ganz.
</p>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
  <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if (!empty($gutschriften)): ?>
  <a href="/portal/billing/gutschriften/sepa-xml<?= $run ? '?run_id=' . htmlspecialchars($run['id']) : '' ?>"
     class="btn btn-secondary" style="margin-bottom:1.5rem">
    <?= icon('arrow-down') ?> SEPA-Sammelüberweisung (pain.001) herunterladen
  </a>
<?php endif; ?>

<?php if (empty($gutschriften)): ?>
  <div class="card" style="text-align:center;color:var(--gray-600);padding:2rem">
    <?= icon('check-circle') ?> Keine offenen Gutschriften<?= $run ? ' in diesem Abrechnungslauf' : '' ?>.
  </div>
<?php else: ?>
  <?php foreach ($gutschriften as $g): ?>
    <div class="card" style="margin-bottom:1rem">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
        <h3 style="margin:0">
          <?= htmlspecialchars($g['name']) ?>
          <?php if (!$run): ?>
            <span class="badge badge-gray" style="font-size:.7rem;font-weight:400"><?= htmlspecialchars($g['quartal']) ?></span>
          <?php endif; ?>
        </h3>
        <form method="post" action="/portal/billing/gutschriften/<?= htmlspecialchars($g['invoice_id']) ?>/erledigt"
              onsubmit="return confirm('Überweisung von ' + <?= json_encode($g['betrag']) ?> + ' € an ' + <?= json_encode($g['name']) ?> + ' wirklich als durchgeführt markieren? Die Gutschrift verschwindet danach aus dieser Liste.')">
          <?php if ($run): ?><input type="hidden" name="run_id" value="<?= htmlspecialchars($run['id']) ?>"><?php endif; ?>
          <button type="submit" class="btn btn-primary" style="padding:.35rem .75rem;font-size:.8rem">
            <?= icon('check-circle') ?> Überweisung durchgeführt
          </button>
        </form>
      </div>
      <table style="width:100%">
        <tbody>
          <tr>
            <th style="width:160px;text-align:left;color:var(--gray-600);font-weight:500">Kontoinhaber</th>
            <td><?= htmlspecialchars($g['kontoinhaber']) ?></td>
            <td style="width:100px;text-align:right">
              <button type="button" class="btn btn-copy" style="padding:.3rem .6rem;font-size:.78rem;background:var(--gray-100);color:var(--gray-700)"
                      data-value="<?= htmlspecialchars($g['kontoinhaber'], ENT_QUOTES) ?>">Kopieren</button>
            </td>
          </tr>
          <tr>
            <th style="text-align:left;color:var(--gray-600);font-weight:500">IBAN</th>
            <td><code><?= htmlspecialchars($g['iban']) ?></code></td>
            <td style="text-align:right">
              <button type="button" class="btn btn-copy" style="padding:.3rem .6rem;font-size:.78rem;background:var(--gray-100);color:var(--gray-700)"
                      data-value="<?= htmlspecialchars($g['iban'], ENT_QUOTES) ?>">Kopieren</button>
            </td>
          </tr>
          <?php if (!empty($g['bic'])): ?>
          <tr>
            <th style="text-align:left;color:var(--gray-600);font-weight:500">BIC</th>
            <td><code><?= htmlspecialchars($g['bic']) ?></code></td>
            <td style="text-align:right">
              <button type="button" class="btn btn-copy" style="padding:.3rem .6rem;font-size:.78rem;background:var(--gray-100);color:var(--gray-700)"
                      data-value="<?= htmlspecialchars($g['bic'], ENT_QUOTES) ?>">Kopieren</button>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <th style="text-align:left;color:var(--gray-600);font-weight:500">Betrag</th>
            <td><strong><?= htmlspecialchars($g['betrag']) ?> €</strong></td>
            <td style="text-align:right">
              <button type="button" class="btn btn-copy" style="padding:.3rem .6rem;font-size:.78rem;background:var(--gray-100);color:var(--gray-700)"
                      data-value="<?= htmlspecialchars($g['betrag'], ENT_QUOTES) ?>">Kopieren</button>
            </td>
          </tr>
          <tr>
            <th style="text-align:left;color:var(--gray-600);font-weight:500">Verwendungszweck</th>
            <td><?= htmlspecialchars($g['verwendungszweck']) ?></td>
            <td style="text-align:right">
              <button type="button" class="btn btn-copy" style="padding:.3rem .6rem;font-size:.78rem;background:var(--gray-100);color:var(--gray-700)"
                      data-value="<?= htmlspecialchars($g['verwendungszweck'], ENT_QUOTES) ?>">Kopieren</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<a href="/portal/billing" style="font-size:.85rem">&larr; Zurück zur Abrechnung</a>

<script>
document.querySelectorAll('.btn-copy').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var value = btn.dataset.value || '';
    var restore = function () {
      var orig = btn.dataset.origLabel || 'Kopieren';
      btn.textContent = orig;
    };
    if (!btn.dataset.origLabel) btn.dataset.origLabel = btn.textContent;
    navigator.clipboard.writeText(value).then(function () {
      btn.textContent = 'Kopiert!';
      setTimeout(restore, 1200);
    }).catch(function () {
      btn.textContent = 'Fehler';
      setTimeout(restore, 1200);
    });
  });
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
