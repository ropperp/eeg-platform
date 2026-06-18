<?php
$pageTitle = 'Live-Anzeige';
$preselect = htmlspecialchars($_GET['eeg'] ?? '');
ob_start();
?>

<div style="padding:3rem 0;background:#fff;border-bottom:1px solid #e5e7eb">
  <div class="container" style="text-align:center">
    <h1 style="font-size:1.75rem;margin-bottom:.5rem">⚡ Live-Energiedaten</h1>
    <p style="color:#6b7280">Suchen Sie eine Energiegemeinschaft um ihre aktuellen Daten zu sehen.</p>

    <div class="search-wrap" style="margin-top:1.5rem">
      <div class="form-group" style="margin:0">
        <input type="text" id="eeg-search" placeholder="Gemeinschaft suchen…"
               value="<?= $preselect ?>"
               autocomplete="off" style="font-size:1rem;padding:.75rem 1rem">
      </div>
      <ul class="search-results" id="search-results" style="display:none"></ul>
    </div>
  </div>
</div>

<div class="container" style="padding-top:2rem">
  <div id="dashboard" style="display:none">
    <h2 id="community-name" style="margin-bottom:1.5rem"></h2>

    <div class="grid-3" style="margin-bottom:2rem">
      <div class="card stat-card">
        <div class="stat-value" id="bezug-w">—</div>
        <div class="stat-label">Aktueller Bezug (W)</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value" id="einsp-w">—</div>
        <div class="stat-label">Aktuelle Einspeisung (W)</div>
      </div>
      <div class="card stat-card">
        <div class="stat-value" id="today-kwh">—</div>
        <div class="stat-label">Erzeugung heute (kWh)</div>
      </div>
    </div>

    <div class="grid-2">
      <div class="card">
        <h3 style="margin-bottom:1rem">Autarkie</h3>
        <div class="gauge-wrap">
          <canvas id="gauge-canvas" width="200" height="120"></canvas>
          <div class="gauge-label" id="autarkie-pct">0%</div>
          <div class="gauge-sub">der Gemeinschaft</div>
        </div>
      </div>
      <div class="card">
        <h3 style="margin-bottom:1rem">Verlauf letzte 2 Stunden</h3>
        <div class="chart-container">
          <canvas id="timeseries-chart"></canvas>
        </div>
      </div>
    </div>

    <p style="margin-top:1.5rem;font-size:.8rem;color:#9ca3af;text-align:center">
      Daten werden alle 10 Sekunden aktualisiert. Anzeige: aggregierte Gemeinschaftswerte, keine Personendaten.
    </p>
  </div>

  <div id="no-selection" style="text-align:center;padding:4rem;color:#6b7280">
    <div style="font-size:3rem;margin-bottom:1rem">🔍</div>
    <p>Geben Sie den Namen einer Energiegemeinschaft ein um die Echtzeit-Daten zu sehen.</p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
let currentSlug = null;
let chart = null;
let refreshTimer = null;

// ─── Suche ───────────────────────────────────────────────
const searchInput = document.getElementById('eeg-search');
const resultsList = document.getElementById('search-results');

searchInput.addEventListener('input', async () => {
  const q = searchInput.value.trim();
  if (q.length < 2) { resultsList.style.display = 'none'; return; }
  const res = await fetch('/api/communities/search?q=' + encodeURIComponent(q));
  const data = await res.json();
  resultsList.innerHTML = '';
  if (data.length === 0) { resultsList.style.display = 'none'; return; }
  data.forEach(c => {
    const li = document.createElement('li');
    li.textContent = c.name;
    li.addEventListener('click', () => {
      searchInput.value = c.name;
      resultsList.style.display = 'none';
      loadDashboard(c.slug);
    });
    resultsList.appendChild(li);
  });
  resultsList.style.display = 'block';
});

document.addEventListener('click', e => {
  if (!e.target.closest('.search-wrap')) resultsList.style.display = 'none';
});

// ─── Dashboard ────────────────────────────────────────────
async function loadDashboard(slug) {
  currentSlug = slug;
  if (refreshTimer) clearInterval(refreshTimer);
  await refresh();
  refreshTimer = setInterval(refresh, 10000);
}

async function refresh() {
  if (!currentSlug) return;
  const res = await fetch('/api/live/' + currentSlug);
  if (!res.ok) return;
  const d = await res.json();

  document.getElementById('dashboard').style.display = 'block';
  document.getElementById('no-selection').style.display = 'none';
  document.getElementById('bezug-w').textContent = d.bezug_w.toLocaleString('de-AT') + ' W';
  document.getElementById('einsp-w').textContent = d.einspeisung_w.toLocaleString('de-AT') + ' W';
  document.getElementById('today-kwh').textContent = d.today_kwh.toLocaleString('de-AT') + ' kWh';
  document.getElementById('autarkie-pct').textContent = d.autarkie_pct + '%';

  drawGauge(d.autarkie_pct);
  drawChart(d.series);
}

// ─── Gauge ────────────────────────────────────────────────
function drawGauge(pct) {
  const canvas = document.getElementById('gauge-canvas');
  const ctx = canvas.getContext('2d');
  canvas.width = 200; canvas.height = 120;
  ctx.clearRect(0, 0, 200, 120);

  const cx = 100, cy = 110, r = 90, start = Math.PI, end = 2 * Math.PI;

  // Hintergrund
  ctx.beginPath();
  ctx.arc(cx, cy, r, start, end);
  ctx.strokeStyle = '#e5e7eb';
  ctx.lineWidth = 18;
  ctx.stroke();

  // Füllstand
  const fillEnd = start + (pct / 100) * Math.PI;
  ctx.beginPath();
  ctx.arc(cx, cy, r, start, fillEnd);
  ctx.strokeStyle = pct > 80 ? '#16a34a' : pct > 50 ? '#ca8a04' : '#dc2626';
  ctx.lineWidth = 18;
  ctx.stroke();
}

// ─── Zeitreihen-Chart ─────────────────────────────────────
function drawChart(series) {
  const labels = series.map(s => new Date(s.bucket).toLocaleTimeString('de-AT', {hour:'2-digit',minute:'2-digit'}));
  const bezug  = series.map(s => s.bezug_w ?? 0);
  const einsp  = series.map(s => s.einspeisung_w ?? 0);

  if (chart) {
    chart.data.labels = labels;
    chart.data.datasets[0].data = bezug;
    chart.data.datasets[1].data = einsp;
    chart.update('none');
    return;
  }

  chart = new Chart(document.getElementById('timeseries-chart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Bezug (W)', data: bezug, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.1)', fill: true, tension: .3, pointRadius: 0 },
        { label: 'Einspeisung (W)', data: einsp, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.1)', fill: true, tension: .3, pointRadius: 0 },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true } }
    }
  });
}

// Vorauswahl aus URL-Parameter
const preselect = '<?= $preselect ?>';
if (preselect) loadDashboard(preselect);
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
