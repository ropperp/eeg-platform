<?php
$pageTitle = 'Mein Verbrauch';
$communityId = Auth::activeCommunityId();
DB::setCommunity($communityId);

$userId = Auth::userId();
$member = DB::fetchOne(
    'SELECT m.*, mp.id AS mp_id, mp.type AS mp_type, mp.zaehlpunkt_nr
     FROM members m
     JOIN metering_points mp ON mp.member_id = m.id
     WHERE m.user_id = $1 AND m.community_id = $2 LIMIT 1',
    [$userId, $communityId]
);

// ESP-Tagessumme (Echtzeit-Aggregation, kein Rechtsdokument)
$today = null;
if ($member) {
    $today = DB::fetchOne(
        "SELECT
            MAX(energy_bezug_wh) - MIN(energy_bezug_wh) AS bezug_wh_today,
            MAX(energy_einspeisung_wh) - MIN(energy_einspeisung_wh) AS einsp_wh_today,
            MAX(power_bezug_w) AS peak_bezug_w
         FROM esp_measurements
         WHERE community_id = $1 AND metering_point_id = $2 AND time >= CURRENT_DATE",
        [$communityId, $member['mp_id']]
    );

    // Zeitreihe heute (5-Min-Aggregate)
    $series = DB::fetchAll(
        "SELECT time_bucket('5 minutes', time) AS bucket, AVG(power_bezug_w) AS bezug_w, AVG(power_einspeisung_w) AS einsp_w
         FROM esp_measurements
         WHERE community_id = $1 AND metering_point_id = $2 AND time >= CURRENT_DATE
         GROUP BY bucket ORDER BY bucket",
        [$communityId, $member['mp_id']]
    );
}

// EEG-Gesamtwerte
$communityAgg = DB::fetchOne(
    "SELECT COALESCE(SUM(power_einspeisung_w), 0) AS total_einsp_w,
            COALESCE(SUM(power_bezug_w), 0) AS total_bezug_w
     FROM esp_measurements
     WHERE community_id = $1 AND time >= now() - INTERVAL '2 minutes'",
    [$communityId]
);
$autarkie = ($communityAgg['total_bezug_w'] > 0)
    ? min(100, round($communityAgg['total_einsp_w'] / $communityAgg['total_bezug_w'] * 100))
    : 0;

ob_start();
?>

<h2 style="margin-bottom:1.5rem">Guten Tag, <?= htmlspecialchars($member['first_name'] ?? Auth::userName()) ?>!</h2>

<div class="grid-3" style="margin-bottom:2rem">
  <div class="card stat-card">
    <div class="stat-value"><?= number_format(($today['bezug_wh_today'] ?? 0) / 1000, 2, ',', '.') ?> kWh</div>
    <div class="stat-label">Bezug heute (ESP32-Daten)</div>
  </div>
  <?php if ($member && in_array($member['mp_type'], ['producer', 'prosumer'])): ?>
  <div class="card stat-card">
    <div class="stat-value"><?= number_format(($today['einsp_wh_today'] ?? 0) / 1000, 2, ',', '.') ?> kWh</div>
    <div class="stat-label">Einspeisung heute</div>
  </div>
  <?php endif; ?>
  <div class="card stat-card">
    <div class="stat-value"><?= $autarkie ?>%</div>
    <div class="stat-label">Gemeinschafts-Autarkie</div>
  </div>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Mein Verbrauch heute</h3>
  <div class="chart-container">
    <canvas id="my-chart"></canvas>
  </div>
  <p style="margin-top:.75rem;font-size:.8rem;color:#9ca3af">
    ⚠ Diese Daten kommen vom ESP32-Smart-Meter-Modul und dienen nur zur Orientierung.
    Die offiziellen Abrechnungswerte stammen vom EDA-Portal und sind erst bei der Quartalsabrechnung verfügbar.
  </p>
</div>

<div class="card">
  <h3 style="margin-bottom:.75rem">Mein Zählpunkt</h3>
  <p style="font-size:.875rem;color:#6b7280"><?= htmlspecialchars($member['zaehlpunkt_nr'] ?? '—') ?></p>
  <p style="margin-top:.5rem">
    <a href="/portal/invoices" class="btn btn-secondary" style="margin-top:.5rem">🧾 Meine Rechnungen</a>
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const series = <?= json_encode($series ?? []) ?>;
new Chart(document.getElementById('my-chart'), {
  type: 'line',
  data: {
    labels: series.map(s => new Date(s.bucket).toLocaleTimeString('de-AT', {hour:'2-digit',minute:'2-digit'})),
    datasets: [
      { label: 'Bezug (W)', data: series.map(s => s.bezug_w ?? 0), borderColor: '#dc2626', fill: false, tension: .3, pointRadius: 0 },
      { label: 'Einspeisung (W)', data: series.map(s => s.einsp_w ?? 0), borderColor: '#16a34a', fill: false, tension: .3, pointRadius: 0 },
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
