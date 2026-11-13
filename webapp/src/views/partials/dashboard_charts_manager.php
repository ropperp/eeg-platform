<?php
// Diagramme im Obmann-Dashboard (nur im Glas-Design, siehe manager_dashboard.php).
// Werte aus denselben Quellen wie die Abrechnung: EDA-Monatswerte (eda_measurements, nur
// L1/L2), Autarkie aus den Viertelstundenwerten (eda_interval_data, Verbrauch), Zählpunkte
// ohne Demo-Mitglieder. Erwartet: $communityId (DB::setCommunity() bereits gesetzt).
$gMonths = chartMonthKeys(12);
$gTeilnahme = [];
$gErzeugung = [];
foreach (DB::fetchAll(
    "SELECT to_char(date_trunc('month', time AT TIME ZONE 'Europe/Vienna'), 'YYYY-MM') AS ym,
            COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme, COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung
     FROM eda_measurements
     WHERE community_id = ? AND quality IN ('L1','L2') AND time >= (?::timestamp AT TIME ZONE 'Europe/Vienna')
     GROUP BY ym",
    [$communityId, $gMonths[0] . '-01']
) as $r) {
    $gTeilnahme[$r['ym']] = (float)$r['teilnahme'];
    $gErzeugung[$r['ym']] = (float)$r['erzeugung'];
}
$gHasMonthly = array_sum($gTeilnahme) + array_sum($gErzeugung) > 0;

$gAutarkie = DB::fetchOne(
    "SELECT to_char(date_trunc('month', time AT TIME ZONE 'Europe/Vienna'), 'YYYY-MM') AS ym,
            SUM(kwh_messung) AS verbrauch, SUM(kwh_gemeinschaft) AS gedeckt
     FROM eda_interval_data
     WHERE community_id = ? AND energy_direction = 'CONSUMPTION'
       AND time >= (?::timestamp AT TIME ZONE 'Europe/Vienna')
     GROUP BY ym ORDER BY ym DESC LIMIT 1",
    [$communityId, $gMonths[0] . '-01']
);
$gVerbrauch = (float)($gAutarkie['verbrauch'] ?? 0);
$gGedeckt = min($gVerbrauch, (float)($gAutarkie['gedeckt'] ?? 0));

$gTypes = ['consumer' => 0, 'producer' => 0, 'prosumer' => 0];
foreach (DB::fetchAll(
    "SELECT mp.type, COUNT(*) AS n FROM metering_points mp JOIN members m ON m.id = mp.member_id
     WHERE mp.community_id = ? AND mp.active = true AND m.is_demo = false
       AND mp.mirror_source_metering_point_id IS NULL
     GROUP BY mp.type",
    [$communityId]
) as $r) {
    if (isset($gTypes[$r['type']])) $gTypes[$r['type']] = (int)$r['n'];
}
$kwh = fn(float $v) => chartNumber($v) . ' kWh';
?>
<div class="gchart-row">
  <div class="card" style="display:flex;flex-direction:column">
    <div class="gcard-head">
      <div>
        <h3><?= icon('chart-bar') ?> Energie in der Gemeinschaft</h3>
        <p class="gcard-sub">Je Monat laut EDA-Daten des Netzbetreibers (Grundlage der Abrechnung)</p>
      </div>
    </div>
    <?php if ($gHasMonthly): ?>
      <?= renderBarChart($gMonths, [
          ['name' => 'Bezug aus der Gemeinschaft', 'class' => 'c1', 'values' => $gTeilnahme],
          ['name' => 'Erzeugung', 'class' => 'c2', 'values' => $gErzeugung],
      ], 'kWh', 6) ?>
    <?php else: ?>
      <p class="gcard-empty">Noch keine EDA-Monatswerte -- das Diagramm füllt sich nach dem ersten EDA-Import.</p>
    <?php endif; ?>
  </div>

  <div class="gchart-stack">
    <div class="card">
      <div class="gcard-head">
        <div>
          <h3><?= icon('lightning') ?> Autarkie</h3>
          <p class="gcard-sub"><?= $gAutarkie ? 'Verbrauch aus der Gemeinschaft gedeckt · ' . htmlspecialchars(monatsLabel($gAutarkie['ym'] . '-01')) : 'Anteil des Verbrauchs, den die Gemeinschaft deckt' ?></p>
        </div>
      </div>
      <?php if ($gVerbrauch > 0): ?>
        <?= renderRingChart($gGedeckt / $gVerbrauch * 100, 'aus der Gemeinschaft') ?>
        <div class="gmini">
          <div><strong><?= chartNumber($gVerbrauch) ?></strong><span>kWh Verbrauch</span></div>
          <div><strong><?= chartNumber($gGedeckt) ?></strong><span>kWh aus der EEG</span></div>
          <div><strong><?= chartNumber($gVerbrauch - $gGedeckt) ?></strong><span>kWh aus dem Netz</span></div>
        </div>
      <?php else: ?>
        <p class="gcard-empty">Noch keine Viertelstundenwerte importiert.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="gcard-head">
        <div>
          <h3><?= icon('users-three') ?> Zählpunkte</h3>
          <p class="gcard-sub">Aktive Zählpunkte nach Art</p>
        </div>
      </div>
      <?= renderDonutChart([
          ['label' => 'Verbraucher', 'value' => $gTypes['consumer'], 'class' => 'c1'],
          ['label' => 'Einspeiser', 'value' => $gTypes['producer'], 'class' => 'c2'],
          ['label' => 'Prosumer', 'value' => $gTypes['prosumer'], 'class' => 'c3'],
      ], (string)array_sum($gTypes), 'Zählpunkte') ?>
    </div>
  </div>
</div>
<script src="/assets/js/glass-charts.js?v=<?= @filemtime(ROOT . '/public/assets/js/glass-charts.js') ?: time() ?>"></script>
