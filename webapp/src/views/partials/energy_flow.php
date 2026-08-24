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
 *
 * Verbindungslinien + Energie-Impulse (Patrick, 24.08.2026: "nach dem Vorbild der
 * Fronius-Energiefluss-Darstellung" -- Impulse müssen geometrisch am Kreisrand beginnen/enden,
 * keine feste Pixel-Positionierung) werden per JS als SVG gezeichnet, NICHT mehr serverseitig
 * als starre CSS-Connector-Divs: renderEflow() misst die tatsächlichen Kreis-Positionen/-Radien
 * per getBoundingClientRect() und berechnet die Linien daraus -- bleibt dadurch auch bei
 * geänderter Größe/Position der Kreise korrekt, ohne Anpassung im Code. Die PV-Verbindung
 * bekommt bewusst KEINE gerade Linie, sondern eine Bezier-Kurve, die um die Breite des
 * PV-Knotens (Kreis + Text darunter) herum ausweicht -- eine gerade Linie vom PV-Kreis direkt
 * zum EEG-Knoten würde sonst mitten durch den Text "676 W" / "PV-Erzeugung" laufen (Patrick:
 * "Die Verbindung darf sich optisch nicht mit dem Text überschneiden", Text bleibt aber an
 * seiner bisherigen Position -- Netz/Verbrauch brauchen das nicht, deren Text steht unterhalb
 * des Kreises, während die Verbindung seitlich auf Kreis-Mitte-Höhe verläuft).
 */
$netzW        = ($live['einsp_w'] ?? 0) - ($live['bezug_w'] ?? 0);
$netzDirClass = $netzW > 0 ? 'eflow-out' : ($netzW < 0 ? 'eflow-in' : '');
$netzLabel    = $netzW > 0 ? 'Netz (Einspeisung)' : ($netzW < 0 ? 'Netz (Bezug)' : 'Netz');
?>
<div class="card">
  <h3 style="margin-bottom:1rem"><?= icon('lightning') ?> Energiefluss (Live)</h3>
  <div class="eflow" id="eflow">
    <svg class="eflow-svg" id="eflow-svg"></svg>
    <div class="eflow-node" data-eflow-node="pv">
      <div class="eflow-circle eflow-circle-pv"><?= icon('sun') ?></div>
      <div class="eflow-value" id="ef-pv"><?= number_format($live['einsp_w'] ?? 0, 0, ',', '.') ?> W</div>
      <div class="eflow-label">PV-Erzeugung</div>
    </div>
    <div class="eflow-middle">
      <div class="eflow-node" data-eflow-node="netz">
        <div class="eflow-circle eflow-circle-netz <?= $netzDirClass ?>" id="ef-netz-circle"><?= icon('plug') ?></div>
        <div class="eflow-value" id="ef-netz"><?= number_format(abs($netzW), 0, ',', '.') ?> W</div>
        <div class="eflow-label" id="ef-netz-label"><?= $netzLabel ?></div>
      </div>
      <div class="eflow-hub" data-eflow-node="hub"><span>EEG</span></div>
      <div class="eflow-node" data-eflow-node="verbrauch">
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
.eflow { position:relative; display:flex; flex-direction:column; align-items:center; padding:.5rem 0 0; }
.eflow-svg { position:absolute; inset:0; width:100%; height:100%; overflow:visible; pointer-events:none; z-index:0; }
.eflow-baseline { stroke:var(--gray-200); stroke-width:2; fill:none; }
.eflow-pulse { filter:drop-shadow(0 0 3px currentColor); }
.eflow-node { position:relative; z-index:1; display:flex; flex-direction:column; align-items:center; gap:.3rem; width:100px; }
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
.eflow-middle { position:relative; z-index:1; display:flex; align-items:center; gap:1.75rem; margin-top:.25rem; }
.eflow-hub {
  position:relative; z-index:1;
  width:42px; height:42px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  background:var(--green-light); border:2px solid var(--green);
}
.eflow-hub span { font-size:.62rem; font-weight:700; color:var(--green-dark); letter-spacing:.02em; }
</style>
<script>
(function () {
  // ─── Geometrie: Kreis-Mittelpunkt + Radius eines Knotens relativ zum #eflow-Container ───
  function nodeCircle(container, containerRect, name) {
    const el = container.querySelector('[data-eflow-node="' + name + '"]');
    const target = el.classList.contains('eflow-hub') ? el : el.querySelector('.eflow-circle');
    const r = target.getBoundingClientRect();
    return {
      cx: r.left + r.width / 2 - containerRect.left,
      cy: r.top + r.height / 2 - containerRect.top,
      radius: r.width / 2,
    };
  }
  function nodeBox(container, containerRect, name) {
    const r = container.querySelector('[data-eflow-node="' + name + '"]').getBoundingClientRect();
    return { width: r.width, height: r.height };
  }
  // Gerade Linie zwischen zwei Kreisen, exakt am jeweiligen Kreisrand beginnend/endend (nicht
  // am Mittelpunkt) -- Patrick: "Keine sichtbare Lücke zwischen Kreis und animiertem Energiefluss."
  function trimStraight(a, b) {
    const dx = b.cx - a.cx, dy = b.cy - a.cy, dist = Math.hypot(dx, dy) || 1;
    const ux = dx / dist, uy = dy / dist;
    return { x1: a.cx + ux * a.radius, y1: a.cy + uy * a.radius, x2: b.cx - ux * b.radius, y2: b.cy - uy * b.radius };
  }
  // PV->EEG als Bezier-Kurve, die seitlich um die volle Breite des PV-Knotens (Kreis + Wert +
  // Beschriftung darunter) ausweicht, statt gerade durch den Text zu laufen.
  function pvCurve(pv, pvBox, hub) {
    const x0 = pv.cx, y0 = pv.cy + pv.radius;
    const x1 = hub.cx, y1 = hub.cy - hub.radius;
    const bulge = pvBox.width / 2 + 14;
    const midY = (y0 + y1) / 2;
    const c1x = x0 + bulge, c1y = y0 + (midY - y0) * 0.55;
    const c2x = x1 + bulge * 0.3, c2y = y1 - (y1 - midY) * 0.55;
    return 'M ' + x0 + ' ' + y0 + ' C ' + c1x + ' ' + c1y + ', ' + c2x + ' ' + c2y + ', ' + x1 + ' ' + y1;
  }
  // Anzahl/Tempo der Energie-Impulse hängt von der tatsächlichen Leistung ab (Patrick: "höhere
  // Leistung -> mehrere bzw. etwas schnellere Energieimpulse [...] 0 W -> keine animierten
  // Impulse") -- rein dekorativ bei 0 nichts anzeigen, sonst 1-3 Impulse je Leistungsstufe.
  function pulseCount(w) {
    const a = Math.abs(w);
    if (a <= 0) return 0;
    if (a < 500) return 1;
    if (a < 2000) return 2;
    return 3;
  }
  function pulseDuration(w) {
    const a = Math.min(Math.abs(w), 5000);
    return Math.max(0.7, 2.2 - (a / 5000) * 1.5);
  }
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const XLINK_NS = 'http://www.w3.org/1999/xlink';
  function svgEl(tag) { return document.createElementNS(SVG_NS, tag); }

  // Zeichnet eine Basislinie (dezent, immer sichtbar) + 0-3 animierte Impuls-Punkte, die exakt
  // entlang dieser Linie/Kurve vom Anfang zum Ende laufen (SVG <animateMotion> mit <mpath>,
  // damit der Impuls physisch auf dem Pfad bleibt statt frei zu schweben).
  function buildConnector(svg, id, pathD, color, power) {
    const path = svgEl('path');
    path.setAttribute('id', id);
    path.setAttribute('class', 'eflow-baseline');
    path.setAttribute('d', pathD);
    svg.appendChild(path);

    const count = pulseCount(power);
    const dur = pulseDuration(power);
    for (let i = 0; i < count; i++) {
      const dot = svgEl('circle');
      dot.setAttribute('r', 4);
      dot.setAttribute('class', 'eflow-pulse');
      dot.setAttribute('fill', color);
      const anim = svgEl('animateMotion');
      anim.setAttribute('dur', dur + 's');
      anim.setAttribute('repeatCount', 'indefinite');
      anim.setAttribute('begin', (-(i * dur / count)) + 's');
      const mpath = svgEl('mpath');
      mpath.setAttributeNS(XLINK_NS, 'href', '#' + id);
      anim.appendChild(mpath);
      dot.appendChild(anim);
      svg.appendChild(dot);
    }
  }

  function renderEflow(einspW, bezugW) {
    const container = document.getElementById('eflow');
    const svg = document.getElementById('eflow-svg');
    if (!container || !svg) return;
    const containerRect = container.getBoundingClientRect();
    svg.setAttribute('viewBox', '0 0 ' + containerRect.width + ' ' + containerRect.height);
    svg.innerHTML = '';

    const pv = nodeCircle(container, containerRect, 'pv');
    const pvBox = nodeBox(container, containerRect, 'pv');
    const netz = nodeCircle(container, containerRect, 'netz');
    const verbrauch = nodeCircle(container, containerRect, 'verbrauch');
    const hub = nodeCircle(container, containerRect, 'hub');
    const netzW = einspW - bezugW;

    // PV -> EEG: ein Verbraucher speist nie in die Gemeinschaft ein, deshalb immer diese Richtung.
    buildConnector(svg, 'eflow-path-pv', pvCurve(pv, pvBox, hub), '#eab308', einspW);

    // EEG -> Verbrauch: ein Mitglied bezieht nur, speist nie zurück in die Gemeinschaft.
    const verbLine = trimStraight(hub, verbrauch);
    buildConnector(svg, 'eflow-path-verbrauch', 'M ' + verbLine.x1 + ' ' + verbLine.y1 + ' L ' + verbLine.x2 + ' ' + verbLine.y2, '#3b82f6', bezugW);

    // Netz <-> EEG: Richtung hängt vom Vorzeichen ab (Bezug: Netz -> EEG, Einspeisung: EEG -> Netz)
    // -- dafür schlicht die passende Linie wählen, statt denselben Pfad nachträglich umzukehren.
    const netzLine = netzW < 0 ? trimStraight(netz, hub) : trimStraight(hub, netz);
    buildConnector(svg, 'eflow-path-netz', 'M ' + netzLine.x1 + ' ' + netzLine.y1 + ' L ' + netzLine.x2 + ' ' + netzLine.y2, netzW < 0 ? '#dc2626' : '#16a34a', netzW);
  }

  function updateValues(d) {
    const netzW = d.einsp_w - d.bezug_w;
    document.getElementById('ef-pv').textContent = d.einsp_w.toLocaleString('de-AT') + ' W';
    document.getElementById('ef-verbrauch').textContent = d.bezug_w.toLocaleString('de-AT') + ' W';
    document.getElementById('ef-netz').textContent = Math.abs(netzW).toLocaleString('de-AT') + ' W';
    document.getElementById('ef-netz-label').textContent = netzW > 0 ? 'Netz (Einspeisung)' : (netzW < 0 ? 'Netz (Bezug)' : 'Netz');

    const netzCircle = document.getElementById('ef-netz-circle');
    netzCircle.classList.remove('eflow-in', 'eflow-out');
    if (netzW > 0) netzCircle.classList.add('eflow-out');
    else if (netzW < 0) netzCircle.classList.add('eflow-in');

    document.getElementById('live-active-meters').textContent = d.active_meters;
    document.getElementById('live-disclaimer').style.display = (d.active_meters < d.total_meters) ? 'block' : 'none';

    renderEflow(d.einsp_w, d.bezug_w);
  }

  let lastValues = { einsp_w: <?= (float)($live['einsp_w'] ?? 0) ?>, bezug_w: <?= (float)($live['bezug_w'] ?? 0) ?> };
  renderEflow(lastValues.einsp_w, lastValues.bezug_w);

  // Bei Größenänderung (Fenster, Sidebar ein-/ausklappen, Handy drehen) die Geometrie neu
  // berechnen -- die Kreis-Positionen ändern sich dabei, die Linien/Impulse sonst nicht mehr.
  let resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () { renderEflow(lastValues.einsp_w, lastValues.bezug_w); }, 150);
  });

  // Energiefluss-Grafik alle 5s per Fetch aktualisieren -- kein Seiten-Reload für Werte, die
  // sich laufend ändern (Patrick, 30.07.2026, erweitert 13.08.2026 um die Netz-Komponente nach
  // Vorbild einer Fronius/Home-Assistant-Energiefluss-Ansicht, seither auch im Kundenportal).
  setInterval(async () => {
    try {
      const res = await fetch('/portal/api/live-power');
      if (!res.ok) return;
      const d = await res.json();
      lastValues = d;
      updateValues(d);
    } catch (e) { /* naechster Versuch in 5s */ }
  }, 5000);
})();
</script>
