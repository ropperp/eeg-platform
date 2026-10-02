<?php
/**
 * Energiefluss-Live-Karte (PV-Erzeugung / EEG / Verbrauch / Netz), gemeinsam genutzt vom
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
 *
 * Das eigentliche Diagramm (Markup + Werte + Animation) liegt seit 02.10.2026 in
 * partials/energy_flow_diagram.php + assets/js/energy-flow.js -- gemeinsam mit der öffentlichen
 * Live-Seite (pages/live.php) genutzt, siehe dort für Details zur rAF-basierten Animation
 * (ersetzt die vorherige SMIL-Fassung, die bei jedem 5s-Refresh das komplette SVG neu aufbaute
 * und dadurch gelegentlich "Punkt nicht sichtbar"/"mitten im Fließen unterbrochen" verursachte).
 * Diese Datei kümmert sich nur noch um: die Karte selbst, die Messe-Demo-/Offline-Disclaimer
 * und den Fetch-Poll-Loop gegen /portal/api/live-power.
 */
?>
<div class="card">
  <h3 style="margin-bottom:1rem"><?= icon('lightning') ?> Energiefluss (Live)</h3>
  <?php if (isset($communityId) && communityMesseDemoEnabled($communityId)): ?>
    <p style="margin:-.25rem 0 1rem;font-size:.8rem;color:#b45309">
      <?= icon('warning-circle') ?> Hinweis: Ein Teil dieser Werte ist aktuell <strong>simuliert</strong>
      (Platzhalterdaten zur Veranschaulichung, solange noch nicht alle Mitglieder eine eigene
      Ausleseeinheit haben) -- entspricht noch nicht dem tatsächlichen Verbrauch/der tatsächlichen
      Einspeisung.
    </p>
  <?php endif; ?>
  <?php require __DIR__ . '/energy_flow_diagram.php'; ?>
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
<script>
(function () {
  var flow = window.EnergyFlow.init('eflow');
  flow.update(<?= (float)($live['einsp_w'] ?? 0) ?>, <?= (float)($live['bezug_w'] ?? 0) ?>);

  function updateValues(d) {
    document.getElementById('live-active-meters').textContent = d.active_meters;
    document.getElementById('live-disclaimer').style.display = (d.active_meters < d.total_meters) ? 'block' : 'none';
    flow.update(d.einsp_w, d.bezug_w);
  }

  // Energiefluss-Grafik alle 5s per Fetch aktualisieren -- kein Seiten-Reload für Werte, die
  // sich laufend ändern (Patrick, 30.07.2026, erweitert 13.08.2026 um die Netz-Komponente nach
  // Vorbild einer Fronius/Home-Assistant-Energiefluss-Ansicht, seither auch im Kundenportal).
  setInterval(async () => {
    try {
      const res = await fetch('/portal/api/live-power');
      if (!res.ok) return;
      const d = await res.json();
      updateValues(d);
    } catch (e) { /* naechster Versuch in 5s */ }
  }, 5000);
})();
</script>
