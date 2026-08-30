<?php
$pageTitle = 'Mein Verbrauch (Viertelstunden)';

// SVG-Flächendiagramm, gestapelt: unten (grün) = aus der EEG gedeckter Anteil, oben (grau) =
// zusätzlich aus dem öffentlichen Netz bezogen -- Gesamthöhe der Fläche = Gesamtverbrauch.
// Bewusst reines Inline-SVG statt einer JS-Chart-Bibliothek (Projektkonvention, siehe z.B. die
// CSS-Balken auf member_dashboard.php).
$maxW = 1;
foreach ($data['intervals'] as $iv) {
    $maxW = max($maxW, (int)($iv['verbrauch_w'] ?? 0));
}
$maxW = max($maxW * 1.1, 100);
$W = 1000; $H = 260; $padL = 40; $padB = 24; $chartW = $W - $padL - 10; $chartH = $H - $padB - 10;
$n = count($data['intervals']);
$x = fn($i) => $padL + ($chartW * $i / max(1, $n - 1));
$yFromW = fn($w) => 10 + $chartH - ($chartH * $w / $maxW);

$gemPoints = [];
$totalPoints = [];
foreach ($data['intervals'] as $i => $iv) {
    $gem = (int)($iv['gemeinschaft_w'] ?? 0);
    $tot = (int)($iv['verbrauch_w'] ?? 0);
    $gemPoints[] = $x($i) . ',' . $yFromW($gem);
    $totalPoints[] = $x($i) . ',' . $yFromW($tot);
}
$gemArea = $padL . ',' . $yFromW(0) . ' ' . implode(' ', $gemPoints) . ' ' . $x($n - 1) . ',' . $yFromW(0);
$totalArea = $padL . ',' . $yFromW(0) . ' ' . implode(' ', $totalPoints) . ' ' . $x($n - 1) . ',' . $yFromW(0);

ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <h2 style="margin:0"><?= icon('chart-bar') ?> Mein Verbrauch (Viertelstunden)</h2>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <?php $pickerColor = 'green'; $baseUrl = '/portal/my/verbrauch'; require __DIR__ . '/../partials/interval_day_picker.php'; ?>

  <?php if (!$data['has_data']): ?>
    <p style="color:var(--gray-600);font-size:.9rem">
      Für diesen Tag liegen noch keine Viertelstundenwerte vor. Ihr Obmann lädt diese Daten
      regelmäßig aus dem Netzbetreiber-Portal hoch -- sobald das für diesen Tag passiert ist,
      erscheint hier ein Diagramm.
    </p>
  <?php else: ?>
    <div style="display:flex;gap:2rem;margin-bottom:1rem;flex-wrap:wrap">
      <div>
        <div style="font-size:.78rem;color:var(--gray-600)">Gesamtverbrauch</div>
        <div style="font-size:1.4rem;font-weight:700"><?= number_format($data['total_messung_kwh'], 2, ',', '.') ?> kWh</div>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--gray-600)">Davon aus der EEG gedeckt</div>
        <div style="font-size:1.4rem;font-weight:700;color:#16a34a"><?= number_format($data['total_gemeinschaft_kwh'], 2, ',', '.') ?> kWh</div>
      </div>
      <div>
        <div style="font-size:.78rem;color:var(--gray-600)">Anteil Eigendeckung</div>
        <div style="font-size:1.4rem;font-weight:700">
          <?= $data['total_messung_kwh'] > 0 ? number_format($data['total_gemeinschaft_kwh'] / $data['total_messung_kwh'] * 100, 0) : '0' ?>%
        </div>
      </div>
    </div>

    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:auto" role="img" aria-label="Viertelstündlicher Verbrauch und Eigendeckung">
      <?php require __DIR__ . '/../partials/interval_chart_grid.php'; ?>
      <polygon points="<?= $totalArea ?>" fill="#cbd5e1" />
      <polygon points="<?= $gemArea ?>" fill="#86efac" />
      <polyline points="<?= implode(' ', $totalPoints) ?>" fill="none" stroke="#475569" stroke-width="1.5" />
      <polyline points="<?= implode(' ', $gemPoints) ?>" fill="none" stroke="#16a34a" stroke-width="1.5" />
    </svg>
    <div style="display:flex;gap:1.5rem;margin-top:.75rem;font-size:.8rem;color:var(--gray-600)">
      <span><span style="display:inline-block;width:.8rem;height:.8rem;background:#86efac;border:1px solid #16a34a;margin-right:.35rem;vertical-align:middle"></span>aus der EEG gedeckt</span>
      <span><span style="display:inline-block;width:.8rem;height:.8rem;background:#cbd5e1;border:1px solid #475569;margin-right:.35rem;vertical-align:middle"></span>zusätzlich aus dem Netz</span>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:.5rem"><?= icon('lightning') ?> Verbrauch optimieren</h3>
  <p style="font-size:.85rem;color:var(--gray-600)">
    Je größer die grüne Fläche im Verhältnis zur grauen, desto mehr Ihres Verbrauchs kommt aus
    der Energiegemeinschaft statt aus dem öffentlichen Netz. Verschieben Sie größere Verbraucher
    (Waschmaschine, Wallbox, Wärmepumpe) nach Möglichkeit in Zeiten, in denen die grüne Fläche an
    anderen Tagen zu dieser Uhrzeit typischerweise hoch war.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
