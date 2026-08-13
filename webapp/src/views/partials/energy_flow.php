<?php
/**
 * Energiefluss-Live-Grafik (PV-Erzeugung / EEG / Verbrauch / Netz), gemeinsam genutzt vom
 * Obmann-Dashboard (manager_dashboard.php) und der Mitglied-Startseite (member_dashboard.php,
 * seit 13.08.2026 -- Patrick: "ja bitte im Kundenportal auch hinzufügen"). Erwartet $live
 * (Rückgabe von communityLivePower()) im Scope des einbindenden Views.
 *
 * "Netz" ist die Differenz aus Erzeugung und Verbrauch der GANZEN Community -- kein
 * physischer Austausch zwischen Mitgliedern (jedes Mitglied hat seinen eigenen Netzanschluss),
 * sondern was gerade in Summe zusätzlich aus dem öffentlichen Netz kommt bzw. dorthin
 * überschüssig eingespeist wird. Der mittlere Kreis mit der Aufschrift "EEG" (Patrick,
 * 13.08.2026, als Ergänzung zum ursprünglichen Vorbild einer Fronius/Home-Assistant-
 * Energiefluss-Ansicht) steht für genau diese gemeinschaftliche Pooling-Stelle.
 *
 * Aktualisiert sich alle 5s per Fetch gegen /portal/api/live-power -- offen für jeden
 * eingeloggten Portal-Nutzer der aktiven Community, nicht nur Manager (siehe dortige Route).
 */
$netzW        = ($live['einsp_w'] ?? 0) - ($live['bezug_w'] ?? 0);
$netzDirClass = $netzW > 0 ? 'eflow-out' : ($netzW < 0 ? 'eflow-in' : '');
$netzLabel    = $netzW > 0 ? 'Netz (Einspeisung)' : ($netzW < 0 ? 'Netz (Bezug)' : 'Netz');
$pvActive     = ($live['einsp_w'] ?? 0) > 0;
$verbActive   = ($live['bezug_w'] ?? 0) > 0;
$netzActive   = $netzW != 0;
?>
<div class="card">
  <h3 style="margin-bottom:1rem"><?= icon('lightning') ?> Energiefluss (Live)</h3>
  <div class="eflow" id="eflow">
    <div class="eflow-node">
      <div class="eflow-circle eflow-circle-pv"><?= icon('sun') ?></div>
      <div class="eflow-value" id="ef-pv"><?= number_format($live['einsp_w'] ?? 0, 0, ',', '.') ?> W</div>
      <div class="eflow-label">PV-Erzeugung</div>
    </div>
    <div class="eflow-connector eflow-connector-v<?= $pvActive ? ' active' : '' ?>" id="ef-line-pv"><span></span></div>
    <div class="eflow-middle">
      <div class="eflow-node">
        <div class="eflow-circle eflow-circle-netz <?= $netzDirClass ?>" id="ef-netz-circle"><?= icon('plug') ?></div>
        <div class="eflow-value" id="ef-netz"><?= number_format(abs($netzW), 0, ',', '.') ?> W</div>
        <div class="eflow-label" id="ef-netz-label"><?= $netzLabel ?></div>
      </div>
      <div class="eflow-connector eflow-connector-h<?= $netzActive ? ' active' : '' ?><?= $netzW > 0 ? ' reverse' : '' ?>" id="ef-line-netz"><span></span></div>
      <div class="eflow-hub"><span>EEG</span></div>
      <div class="eflow-connector eflow-connector-h<?= $verbActive ? ' active' : '' ?>" id="ef-line-verbrauch"><span></span></div>
      <div class="eflow-node">
        <div class="eflow-circle eflow-circle-verbrauch"><?= icon('buildings') ?></div>
        <div class="eflow-value" id="ef-verbrauch"><?= number_format($live['bezug_w'] ?? 0, 0, ',', '.') ?> W</div>
        <div class="eflow-label">Verbrauch</div>
      </div>
    </div>
  </div>
  <p style="margin-top:1rem;font-size:.8rem;color:var(--gray-600)"><span id="live-active-meters"><?= $live['active_meters'] ?></span> Zählpunkte aktiv in den letzten 2 Min.</p>
  <p id="live-disclaimer" style="margin-top:.5rem;font-size:.75rem;color:#b45309;display:<?= ($live['active_meters'] ?? 0) < ($live['total_meters'] ?? 0) ? 'block' : 'none' ?>">
    <?= icon('warning-circle') ?> Hinweis: Nicht alle Zählpunkte sind gerade online. Die angezeigten
    Gesamtwerte können daher geringfügig von der tatsächlichen Situation abweichen.
  </p>
  <p style="margin-top:.5rem;font-size:.72rem;color:var(--gray-600)">
    "Netz" zeigt die Differenz zwischen Erzeugung und Verbrauch der ganzen Community -- kein
    physischer Austausch zwischen Mitgliedern, sondern was gerade in Summe zusätzlich aus dem
    öffentlichen Netz kommt bzw. dorthin überschüssig eingespeist wird. Für die Abrechnung
    zählt weiterhin ausschließlich der offizielle EDA-Import.
  </p>
</div>
<style>
.eflow { display:flex; flex-direction:column; align-items:center; padding:.5rem 0 0; }
.eflow-node { display:flex; flex-direction:column; align-items:center; gap:.3rem; width:100px; }
.eflow-circle {
  width:64px; height:64px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  border:3px solid var(--gray-200); background:var(--white);
  transition: border-color .3s ease, color .3s ease;
}
.eflow-circle .icon { width:28px; height:28px; }
.eflow-circle-pv { border-color:#eab308; color:#eab308; }
.eflow-circle-verbrauch { border-color:#3b82f6; color:#3b82f6; }
.eflow-circle-netz { border-color:var(--gray-200); color:var(--gray-600); }
.eflow-circle-netz.eflow-in  { border-color:#dc2626; color:#dc2626; }
.eflow-circle-netz.eflow-out { border-color:#16a34a; color:#16a34a; }
.eflow-value { font-weight:700; font-size:.95rem; color:var(--gray-800); }
.eflow-label { font-size:.72rem; color:var(--gray-600); text-align:center; }
.eflow-middle { display:flex; align-items:center; }
.eflow-connector { position:relative; background:var(--gray-200); overflow:hidden; flex-shrink:0; color:var(--gray-600); }
.eflow-connector-v { width:2px; height:26px; }
.eflow-connector-h { width:40px; height:2px; }
.eflow-connector span { position:absolute; inset:0; opacity:0; }
.eflow-connector-v span { background: repeating-linear-gradient(to bottom, currentColor 0 6px, transparent 6px 12px); }
.eflow-connector-h span { background: repeating-linear-gradient(to right, currentColor 0 6px, transparent 6px 12px); }
.eflow-connector.active span { opacity:1; }
.eflow-connector-v.active span { animation: eflow-dash-v .6s linear infinite; }
.eflow-connector-h.active span { animation: eflow-dash-h .6s linear infinite; }
.eflow-connector-h.reverse.active span { animation-direction: reverse; }
@keyframes eflow-dash-h { from { background-position: 0 0; } to { background-position: -12px 0; } }
@keyframes eflow-dash-v { from { background-position: 0 0; } to { background-position: 0 -12px; } }
.eflow-hub {
  width:42px; height:42px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  background:var(--green-light); border:2px solid var(--green);
}
.eflow-hub span { font-size:.62rem; font-weight:700; color:var(--green-dark); letter-spacing:.02em; }
</style>
<script>
// Energiefluss-Grafik alle 5s per Fetch aktualisieren -- kein Seiten-Reload für Werte, die
// sich laufend ändern (Patrick, 30.07.2026, erweitert 13.08.2026 um die Netz-Komponente nach
// Vorbild einer Fronius/Home-Assistant-Energiefluss-Ansicht, seither auch im Kundenportal).
setInterval(async () => {
  try {
    const res = await fetch('/portal/api/live-power');
    if (!res.ok) return;
    const d = await res.json();
    const netzW = d.einsp_w - d.bezug_w;

    document.getElementById('ef-pv').textContent = d.einsp_w.toLocaleString('de-AT') + ' W';
    document.getElementById('ef-verbrauch').textContent = d.bezug_w.toLocaleString('de-AT') + ' W';
    document.getElementById('ef-netz').textContent = Math.abs(netzW).toLocaleString('de-AT') + ' W';
    document.getElementById('ef-netz-label').textContent = netzW > 0 ? 'Netz (Einspeisung)' : (netzW < 0 ? 'Netz (Bezug)' : 'Netz');

    const netzCircle = document.getElementById('ef-netz-circle');
    netzCircle.classList.remove('eflow-in', 'eflow-out');
    if (netzW > 0) netzCircle.classList.add('eflow-out');
    else if (netzW < 0) netzCircle.classList.add('eflow-in');

    document.getElementById('ef-line-pv').classList.toggle('active', d.einsp_w > 0);
    document.getElementById('ef-line-verbrauch').classList.toggle('active', d.bezug_w > 0);
    const netzLine = document.getElementById('ef-line-netz');
    netzLine.classList.toggle('active', netzW !== 0);
    netzLine.classList.toggle('reverse', netzW > 0);

    document.getElementById('live-active-meters').textContent = d.active_meters;
    document.getElementById('live-disclaimer').style.display = (d.active_meters < d.total_meters) ? 'block' : 'none';
  } catch (e) { /* naechster Versuch in 5s */ }
}, 5000);
</script>
