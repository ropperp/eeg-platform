<?php
/**
 * Partial: Datenqualitäts-Bericht (Zähler + Detailliste) -- erwartet $qualityReport aus
 * edaQualityReport() (webapp/public/index.php): ['counts' => ['L1'=>n,'L2'=>n,'L3'=>n],
 * 'details' => [['name','zaehlpunkt_nr','typ','monat','quality'], ...]]. Gemeinsam genutzt von
 * eda_upload.php (sofort nach einem Upload) und eda_import_quality.php (Detailseite eines
 * bereits bestehenden Imports).
 */
$c = $qualityReport['counts'];
$details = $qualityReport['details'];
$total = $c['L1'] + $c['L2'] + $c['L3'];
?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('chart-bar') ?> Datenqualität</h3>
  <?php if ($total === 0): ?>
    <p style="font-size:.875rem;color:var(--gray-600)">Keine Messwerte in diesem Zeitraum gefunden.</p>
  <?php else: ?>
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1rem">
      <div><span class="badge badge-green" style="font-size:.85rem"><?= $c['L1'] ?></span> <span style="font-size:.85rem;color:var(--gray-600)">L1 -- echt gemessen</span></div>
      <div><span class="badge badge-yellow" style="font-size:.85rem"><?= $c['L2'] ?></span> <span style="font-size:.85rem;color:var(--gray-600)">L2 -- belastbarer Ersatzwert</span></div>
      <div><span class="badge badge-red" style="font-size:.85rem"><?= $c['L3'] ?></span> <span style="font-size:.85rem;color:var(--gray-600)">L3 -- NICHT belastbar, sperrt die Freigabe</span></div>
    </div>
    <?php if ($c['L3'] > 0): ?>
      <div class="alert alert-error" style="margin-bottom:1rem">
        Es gibt noch <?= $c['L3'] ?> L3-Datensatz/-sätze in diesem Zeitraum -- die endgültige
        Freigabe eines Abrechnungslaufs, der diesen Zeitraum umfasst, bleibt so lange automatisch
        gesperrt, bis ein neuerer EDA-Export für die betroffenen Zählpunkte keine L3-Werte mehr meldet.
      </div>
    <?php elseif ($c['L2'] > 0): ?>
      <div class="alert alert-warning" style="margin-bottom:1rem">
        Alle Werte sind mindestens L2 (belastbar) -- eine Freigabe ist damit grundsätzlich möglich,
        auch wenn noch keine 60 Tage seit Monatsende vergangen sind.
      </div>
    <?php else: ?>
      <div class="alert alert-success" style="margin-bottom:1rem">
        Alle Werte sind L1 (echt gemessen) -- bestmögliche Datenqualität, Freigabe uneingeschränkt möglich.
      </div>
    <?php endif; ?>

    <?php if (!empty($details)): ?>
      <h4 style="font-size:.9rem;margin-bottom:.5rem">Betroffene Zählpunkte (L2/L3)</h4>
      <div style="overflow-x:auto">
        <table style="font-size:.85rem;width:100%">
          <thead>
            <tr><th>Qualität</th><th>Mitglied</th><th>Zählpunkt</th><th>Typ</th><th>Monat</th></tr>
          </thead>
          <tbody>
            <?php foreach ($details as $d): ?>
              <tr>
                <td><span class="badge badge-<?= $d['quality'] === 'L3' ? 'red' : 'yellow' ?>" style="font-size:.72rem"><?= $d['quality'] ?></span></td>
                <td><?= htmlspecialchars($d['name']) ?></td>
                <td><code style="font-size:.78rem"><?= htmlspecialchars($d['zaehlpunkt_nr']) ?></code></td>
                <td><?= htmlspecialchars($d['typ']) ?></td>
                <td><?= htmlspecialchars($d['monat']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
