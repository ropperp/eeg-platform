<?php
$pageTitle = 'Meine Einspeisung (Viertelstunden)';
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

// Bewusst NUR eine einzige Linie/Fläche (die eigene Einspeisung), keine gestapelte Darstellung
// wie bei /portal/my/verbrauch -- 'verbrauch_w' aus memberIntervalDayData() wäre hier die
// GESAMTE gemeinschaftliche Erzeugung (community-weit, nicht mitgliedsspezifisch, siehe
// Kommentar dort) und würde als vermeintlich eigener Wert in die Irre führen. Reines
// Inline-SVG statt einer JS-Chart-Bibliothek (Projektkonvention).
$maxW = 1;
foreach ($data['intervals'] as $iv) {
    $maxW = max($maxW, (int)($iv['gemeinschaft_w'] ?? 0));
}
$maxW = max($maxW * 1.1, 100);
$W = 1000; $H = 260; $padL = 40; $padB = 24; $chartW = $W - $padL - 10; $chartH = $H - $padB - 10;
$n = count($data['intervals']);
$x = fn($i) => $padL + ($chartW * $i / max(1, $n - 1));
$yFromW = fn($w) => 10 + $chartH - ($chartH * $w / $maxW);

$einspPoints = [];
foreach ($data['intervals'] as $i => $iv) {
    $einspPoints[] = $x($i) . ',' . $yFromW((int)($iv['gemeinschaft_w'] ?? 0));
}
$einspArea = $padL . ',' . $yFromW(0) . ' ' . implode(' ', $einspPoints) . ' ' . $x($n - 1) . ',' . $yFromW(0);

ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <h2 style="margin:0"><?= icon('lightning') ?> Meine Einspeisung (Viertelstunden)</h2>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
    <a href="?date=<?= $prevDate ?>" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem">&larr; Vortag</a>
    <form method="get" style="display:inline">
      <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"
             onchange="this.form.submit()" style="padding:.4rem .6rem;border:1px solid var(--gray-200);border-radius:6px">
    </form>
    <?php $nextDisabled = $nextDate > date('Y-m-d'); ?>
    <a href="<?= $nextDisabled ? '#' : '?date=' . $nextDate ?>" class="btn"
       style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem<?= $nextDisabled ? ';pointer-events:none;opacity:.4' : '' ?>">Folgetag &rarr;</a>
  </div>

  <?php if (!$data['has_data']): ?>
    <p style="color:var(--gray-600);font-size:.9rem">
      Für diesen Tag liegen noch keine Viertelstundenwerte vor. Ihr Obmann lädt diese Daten
      regelmäßig aus dem Netzbetreiber-Portal hoch -- sobald das für diesen Tag passiert ist,
      erscheint hier ein Diagramm.
    </p>
  <?php else: ?>
    <div style="display:flex;gap:2rem;margin-bottom:1rem;flex-wrap:wrap">
      <div>
        <div style="font-size:.78rem;color:var(--gray-600)">Meine Einspeisung</div>
        <div style="font-size:1.4rem;font-weight:700;color:#b45309"><?= number_format($data['total_gemeinschaft_kwh'], 2, ',', '.') ?> kWh</div>
      </div>
    </div>

    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:auto" role="img" aria-label="Viertelstündliche Einspeisung">
      <polygon points="<?= $einspArea ?>" fill="#fde68a" />
      <polyline points="<?= implode(' ', $einspPoints) ?>" fill="none" stroke="#b45309" stroke-width="1.5" />
      <?php foreach ([0, 6, 12, 18, 23.75] as $h): $i = (int)round($h * 4); $i = min($i, $n - 1); ?>
        <text x="<?= $x($i) ?>" y="<?= $H - 6 ?>" font-size="11" fill="var(--gray-600)" text-anchor="middle"><?= sprintf('%02d:00', (int)$h) ?></text>
      <?php endforeach; ?>
      <text x="4" y="<?= $yFromW($maxW) + 4 ?>" font-size="11" fill="var(--gray-600)"><?= number_format($maxW, 0, ',', '.') ?> W</text>
      <text x="4" y="<?= $yFromW(0) ?>" font-size="11" fill="var(--gray-600)">0 W</text>
    </svg>
    <div style="display:flex;gap:1.5rem;margin-top:.75rem;font-size:.8rem;color:var(--gray-600)">
      <span><span style="display:inline-block;width:.8rem;height:.8rem;background:#fde68a;border:1px solid #b45309;margin-right:.35rem;vertical-align:middle"></span>eigene Einspeisung</span>
    </div>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
