<?php
// Diagramme im Mitglieder-Dashboard (nur im Glas-Design, siehe member_dashboard.php): eigener
// Monatsverlauf aus den EDA-Monatswerten (L1/L2, wie die Rechnung) und der Anteil des eigenen
// Verbrauchs, den die Gemeinschaft gedeckt hat (Viertelstundenwerte, letzter Monat mit Daten).
// Erwartet: $communityId, $mpIds, $meteringPoints, $hasConsumer, $hasProducer.
$gMonths = chartMonthKeys(12);
$gTeilnahme = [];
$gErzeugung = [];
$gPh = implode(',', array_fill(0, count($mpIds), '?'));
foreach (DB::fetchAll(
    "SELECT to_char(date_trunc('month', time AT TIME ZONE 'Europe/Vienna'), 'YYYY-MM') AS ym,
            COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme, COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung
     FROM eda_measurements
     WHERE community_id = ? AND metering_point_id IN ($gPh) AND quality IN ('L1','L2')
       AND time >= (?::timestamp AT TIME ZONE 'Europe/Vienna')
     GROUP BY ym",
    array_merge([$communityId], $mpIds, [$gMonths[0] . '-01'])
) as $r) {
    $gTeilnahme[$r['ym']] = (float)$r['teilnahme'];
    $gErzeugung[$r['ym']] = (float)$r['erzeugung'];
}
$gSeries = [];
if ($hasConsumer) $gSeries[] = ['name' => 'Bezug aus der Gemeinschaft', 'class' => 'c1', 'values' => $gTeilnahme];
if ($hasProducer) $gSeries[] = ['name' => 'Eigene Erzeugung', 'class' => 'c2', 'values' => $gErzeugung];
$gHasMonthly = array_sum($gTeilnahme) + array_sum($gErzeugung) > 0;

$gConsumerIds = array_column(array_filter($meteringPoints, fn($mp) => in_array($mp['type'], ['consumer', 'prosumer'], true)), 'id');
$gAnteil = null;
if ($gConsumerIds) {
    $gPhC = implode(',', array_fill(0, count($gConsumerIds), '?'));
    $gAnteil = DB::fetchOne(
        "SELECT to_char(date_trunc('month', time AT TIME ZONE 'Europe/Vienna'), 'YYYY-MM') AS ym,
                SUM(kwh_messung) AS verbrauch, SUM(kwh_gemeinschaft) AS gedeckt
         FROM eda_interval_data
         WHERE community_id = ? AND metering_point_id IN ($gPhC) AND energy_direction = 'CONSUMPTION'
           AND time >= (?::timestamp AT TIME ZONE 'Europe/Vienna')
         GROUP BY ym ORDER BY ym DESC LIMIT 1",
        array_merge([$communityId], $gConsumerIds, [$gMonths[0] . '-01'])
    );
}
$gVerbrauch = (float)($gAnteil['verbrauch'] ?? 0);
$gGedeckt = min($gVerbrauch, (float)($gAnteil['gedeckt'] ?? 0));
?>
<div class="gchart-row"<?= $gVerbrauch > 0 ? '' : ' style="grid-template-columns:1fr"' ?>>
  <div class="card" style="display:flex;flex-direction:column">
    <div class="gcard-head">
      <div>
        <h3><?= icon('chart-bar') ?> Ihr Verlauf</h3>
        <p class="gcard-sub">Je Monat laut EDA-Daten des Netzbetreibers -- dieselben Werte wie auf der Rechnung</p>
      </div>
    </div>
    <?php if ($gHasMonthly && $gSeries): ?>
      <?= renderBarChart($gMonths, $gSeries, 'kWh', 6) ?>
    <?php else: ?>
      <p class="gcard-empty">Noch keine Monatswerte -- das Diagramm füllt sich, sobald die Energiegemeinschaft Daten des Netzbetreibers importiert hat.</p>
    <?php endif; ?>
  </div>
  <?php if ($gVerbrauch > 0): ?>
  <div class="card">
    <div class="gcard-head">
      <div>
        <h3><?= icon('lightning') ?> Aus der Gemeinschaft</h3>
        <p class="gcard-sub">Anteil Ihres Verbrauchs · <?= htmlspecialchars(monatsLabel($gAnteil['ym'] . '-01')) ?></p>
      </div>
    </div>
    <?= renderRingChart($gGedeckt / $gVerbrauch * 100, 'Ihres Verbrauchs') ?>
    <div class="gmini">
      <div><strong><?= chartNumber($gVerbrauch) ?></strong><span>kWh Verbrauch</span></div>
      <div><strong><?= chartNumber($gGedeckt) ?></strong><span>kWh aus der EEG</span></div>
      <div><strong><?= chartNumber($gVerbrauch - $gGedeckt) ?></strong><span>kWh aus dem Netz</span></div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="/assets/js/glass-charts.js?v=<?= @filemtime(ROOT . '/public/assets/js/glass-charts.js') ?: time() ?>"></script>
