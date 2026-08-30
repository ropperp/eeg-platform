<?php
$pageTitle = 'Meine Einspeisung (Viertelstunden)';

// Gestapeltes Flächendiagramm wie bei /portal/my/verbrauch, seit dem Import der dritten
// EDA-Kennzahl-Spalte (migrate_20260907.sql, Patrick, 06.09.2026 [Folgetermin]: "Gleich wie bei
// den Verbrauchern zu den Einspeisern darstellen, wie viel sie einspeisen und wie viel davon in
// der Energiegemeinschaft verwendet wurde [...] gesamte Einspeisung [...] in Grau [...] was
// Energiegemeinschaftlich genutzt wurde bitte in Gelb"): unten (gelb) = der über den
// Teilnahmefaktor der Community zugeteilte, tatsächlich gemeinschaftlich genutzte Anteil, oben
// (grau) = zusätzlich in den Rest des Netzes eingespeister/nicht zugeteilter Überschuss --
// Gesamthöhe der Fläche = eigene Gesamterzeugung des Zählpunkts.
//
// FALLBACK für Tage, die vor dieser Migration importiert wurden ($data['has_erzeugung_gesamt']
// === false): die Gesamterzeugung ist für diese Tage schlicht nicht vorhanden (Spalte wurde
// vorher nicht gelesen) -- ein "gestapeltes" Diagramm mit einer Gesamtfläche von 0 W würde die
// gelbe Fläche fälschlich über die graue hinausragen lassen (sähe aus, als würde mehr
// gemeinschaftlich genutzt als überhaupt erzeugt). Für diese Tage bewusst die alte
// Einzel-Linien-Ansicht (nur die gemeinschaftlich genutzte eigene Einspeisung) mit Hinweistext,
// bis der Tag erneut hochgeladen wird.
$hasGesamt = $data['has_erzeugung_gesamt'];

$maxW = 1;
foreach ($data['intervals'] as $iv) {
    $maxW = max($maxW, (int)($iv['gemeinschaft_w'] ?? 0));
    if ($hasGesamt) { $maxW = max($maxW, (int)($iv['erzeugung_gesamt_w'] ?? 0)); }
}
$maxW = max($maxW * 1.1, 100);
$W = 1000; $H = 260; $padL = 40; $padB = 24; $chartW = $W - $padL - 10; $chartH = $H - $padB - 10;
$n = count($data['intervals']);
$x = fn($i) => $padL + ($chartW * $i / max(1, $n - 1));
$yFromW = fn($w) => 10 + $chartH - ($chartH * $w / $maxW);

$gemPoints = [];
$gesamtPoints = [];
foreach ($data['intervals'] as $i => $iv) {
    $gemPoints[] = $x($i) . ',' . $yFromW((int)($iv['gemeinschaft_w'] ?? 0));
    if ($hasGesamt) { $gesamtPoints[] = $x($i) . ',' . $yFromW((int)($iv['erzeugung_gesamt_w'] ?? 0)); }
}
$gemArea = $padL . ',' . $yFromW(0) . ' ' . implode(' ', $gemPoints) . ' ' . $x($n - 1) . ',' . $yFromW(0);
$gesamtArea = $hasGesamt ? $padL . ',' . $yFromW(0) . ' ' . implode(' ', $gesamtPoints) . ' ' . $x($n - 1) . ',' . $yFromW(0) : '';

ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <h2 style="margin:0"><?= icon('lightning') ?> Meine Einspeisung (Viertelstunden)</h2>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <?php $pickerColor = 'yellow'; $baseUrl = '/portal/my/einspeisung'; require __DIR__ . '/../partials/interval_day_picker.php'; ?>

  <?php if (!$data['has_data']): ?>
    <p style="color:var(--gray-600);font-size:.9rem">
      Für diesen Tag liegen noch keine Viertelstundenwerte vor. Ihr Obmann lädt diese Daten
      regelmäßig aus dem Netzbetreiber-Portal hoch -- sobald das für diesen Tag passiert ist,
      erscheint hier ein Diagramm.
    </p>
  <?php else: ?>
    <?php if (!$hasGesamt): ?>
      <p style="font-size:.78rem;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.5rem .75rem;margin-bottom:1rem">
        <?= icon('warning-circle') ?> Für diesen Tag liegt noch keine Gesamt-Einspeisung vor (nur der
        gemeinschaftlich genutzte Anteil) -- sobald dieser Tag erneut hochgeladen wird, erscheint
        hier auch die Gesamtfläche.
      </p>
    <?php endif; ?>
    <div style="display:flex;gap:2rem;margin-bottom:1rem;flex-wrap:wrap">
      <?php if ($hasGesamt): ?>
        <div>
          <div style="font-size:.78rem;color:var(--gray-600)">Gesamte Einspeisung</div>
          <div style="font-size:1.4rem;font-weight:700"><?= number_format($data['total_erzeugung_gesamt_kwh'], 2, ',', '.') ?> kWh</div>
        </div>
      <?php endif; ?>
      <div>
        <div style="font-size:.78rem;color:var(--gray-600)">Davon energiegemeinschaftlich genutzt</div>
        <div style="font-size:1.4rem;font-weight:700;color:#b45309"><?= number_format($data['total_gemeinschaft_kwh'], 2, ',', '.') ?> kWh</div>
      </div>
      <?php if ($hasGesamt && $data['total_erzeugung_gesamt_kwh'] > 0): ?>
        <div>
          <div style="font-size:.78rem;color:var(--gray-600)">Anteil gemeinschaftlich genutzt</div>
          <div style="font-size:1.4rem;font-weight:700">
            <?= number_format($data['total_gemeinschaft_kwh'] / $data['total_erzeugung_gesamt_kwh'] * 100, 0) ?>%
          </div>
        </div>
      <?php endif; ?>
    </div>

    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:auto" role="img" aria-label="Viertelstündliche Einspeisung<?= $hasGesamt ? ' und gemeinschaftliche Nutzung' : '' ?>">
      <?php if ($hasGesamt): ?>
        <polygon points="<?= $gesamtArea ?>" fill="#cbd5e1" />
      <?php endif; ?>
      <polygon points="<?= $gemArea ?>" fill="#fde68a" />
      <?php if ($hasGesamt): ?>
        <polyline points="<?= implode(' ', $gesamtPoints) ?>" fill="none" stroke="#475569" stroke-width="1.5" />
      <?php endif; ?>
      <polyline points="<?= implode(' ', $gemPoints) ?>" fill="none" stroke="#b45309" stroke-width="1.5" />
      <?php foreach ([0, 6, 12, 18, 23.75] as $h): $i = (int)round($h * 4); $i = min($i, $n - 1); ?>
        <text x="<?= $x($i) ?>" y="<?= $H - 6 ?>" font-size="11" fill="var(--gray-600)" text-anchor="middle"><?= sprintf('%02d:00', (int)$h) ?></text>
      <?php endforeach; ?>
      <text x="4" y="<?= $yFromW($maxW) + 4 ?>" font-size="11" fill="var(--gray-600)"><?= number_format($maxW, 0, ',', '.') ?> W</text>
      <text x="4" y="<?= $yFromW(0) ?>" font-size="11" fill="var(--gray-600)">0 W</text>
    </svg>
    <div style="display:flex;gap:1.5rem;margin-top:.75rem;font-size:.8rem;color:var(--gray-600)">
      <span><span style="display:inline-block;width:.8rem;height:.8rem;background:#fde68a;border:1px solid #b45309;margin-right:.35rem;vertical-align:middle"></span>energiegemeinschaftlich genutzt</span>
      <?php if ($hasGesamt): ?>
        <span><span style="display:inline-block;width:.8rem;height:.8rem;background:#cbd5e1;border:1px solid #475569;margin-right:.35rem;vertical-align:middle"></span>zusätzlich erzeugt (Rest)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
