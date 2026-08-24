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
 * geänderter Größe/Position der Kreise korrekt, ohne Anpassung im Code.
 *
 * Ausschließlich GERADE Linien (Patrick, 24.08.2026, zweite Fassung: "ABSOLUT KEINE KURVEN [...]
 * Die Verbindung soll immer die direkte kürzeste gerade Strecke [...] sein" -- die erste Fassung
 * hatte die PV-Verbindung noch als Bezier-Kurve um den Text herumgeführt, das wurde bewusst
 * wieder verworfen: die Linie darf jetzt direkt durch den Text "676 W"/"PV-Erzeugung" laufen,
 * der Text bleibt unverändert an seiner Position, nur unter der Linie).
 *
 * Zwei synchronisierte Impuls-Phasen statt unabhängiger Einzel-Verbindungen (Patrick,
 * 24.08.2026, dritte Fassung: "zuerst alle Energieflüsse rein in die EEG [...] und dann nach
 * den 0,5 sec. alle Flüsse raus" -- mit zwei Beispielen: Einspeisung ins Netz = PV->EEG
 * zuerst, dann gemeinsam EEG->Netz UND EEG->Verbrauch; zu wenig Eigendeckung = PV->EEG UND
 * Netz->EEG gemeinsam zuerst, dann EEG->Verbrauch). Jede Verbindung kettet ihren eigenen
 * Impuls per SMIL an ihre EIGENE id (begin="<startOffset>s;<eigene-id>.end+<Pause>s", das
 * bewährte Selbstreferenz-Idiom aus der vorherigen Fassung) -- "rein"-Verbindungen bekommen
 * startOffset=0, "raus"-Verbindungen startOffset=OUT_START_S (1,5s). Da ALLE Verbindungen
 * gegen dieselbe Dokument-Zeitachse starten, laufen gleich-phasige Verbindungen zwangsläufig
 * exakt synchron, ohne dass eine Verbindung auf eine andere verweisen müsste. Ein erster Versuch
 * mit einem gemeinsamen, separat erzeugten unsichtbaren "Zeitgeber-Element", an das sich alle
 * Impulse per id.begin anhängen, blieb in Chromium wirkungslos (die referenzierenden Impulse
 * feuerten nie) -- vermutlich eine Einschränkung von Chromiums SMIL-Sync-Base-Auflösung
 * zwischen zwei zur Laufzeit per JS neu eingefügten Elementen; mit Playwright-Zeitstempel-
 * Polling verifiziert (siehe Sitzungslog), deshalb bewusst NICHT so umgesetzt.
 * Phase "rein" feuert bei 0s/3s/6s/..., Phase "raus" bei 1,5s/4,5s/... (0,5s Pause nach 1s
 * Bewegung, siehe PULSE_MOVE_S/PULSE_PAUSE_S/CYCLE_S/OUT_START_S).
 *
 * Kreis-Mittelpunkte von Netz/Verbrauch auf Höhe des EEG-Knotens (Patrick, 24.08.2026: "Bitte
 * die Kreise so weit runter, dass sie mit dem Mittelpunkt auf Höhe der Linie sind"): Ursache war,
 * dass .eflow-middle die Kreis+Wert+Label-SÄULE als Ganzes zentriert hat (align-items:center),
 * nicht den Kreis allein -- bei Netz/Verbrauch (Kreis + Wert + Label darunter) liegt der
 * Kreis-Mittelpunkt dadurch spürbar ÜBER der Säulen-Mitte, beim EEG-Hub (nur ein Kreis, kein
 * Text darunter) fallen beide zusammen. Behoben, indem Wert+Label (.eflow-text) aus dem
 * Höhen-Fluss herausgenommen werden (position:absolute unterhalb des Kreises) -- .eflow-node
 * besteht dadurch layouttechnisch nur noch aus dem Kreis selbst, exakt wie .eflow-hub, wodurch
 * align-items:center jetzt wirklich die Kreis-MITTELPUNKTE zueinander ausrichtet statt der
 * unterschiedlich hohen Gesamt-Säulen. Kein fixer Pixel-Wert nötig -- reserviert wird der
 * darunter frei werdende Platz stattdessen pauschal per margin/padding in rem (siehe CSS), wie
 * jeder normale Karten-Abstand auch.
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
      <div class="eflow-text">
        <div class="eflow-value" id="ef-pv"><?= number_format($live['einsp_w'] ?? 0, 0, ',', '.') ?> W</div>
        <div class="eflow-label">PV-Erzeugung</div>
      </div>
    </div>
    <div class="eflow-middle">
      <div class="eflow-node" data-eflow-node="netz">
        <div class="eflow-circle eflow-circle-netz <?= $netzDirClass ?>" id="ef-netz-circle"><?= icon('plug') ?></div>
        <div class="eflow-text">
          <div class="eflow-value" id="ef-netz"><?= number_format(abs($netzW), 0, ',', '.') ?> W</div>
          <div class="eflow-label" id="ef-netz-label"><?= $netzLabel ?></div>
        </div>
      </div>
      <div class="eflow-hub" data-eflow-node="hub"><span>EEG</span></div>
      <div class="eflow-node" data-eflow-node="verbrauch">
        <div class="eflow-circle eflow-circle-verbrauch"><?= icon('buildings') ?></div>
        <div class="eflow-text">
          <div class="eflow-value" id="ef-verbrauch"><?= number_format($live['bezug_w'] ?? 0, 0, ',', '.') ?> W</div>
          <div class="eflow-label">Verbrauch</div>
        </div>
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
/* .eflow-node besteht layouttechnisch NUR aus dem Kreis (Breite/Höhe = Kreisgröße) -- Wert/Label
   sitzen in .eflow-text darunter per position:absolute, tragen also nicht zur Höhe des Knotens
   bei. Wichtig für die Kreis-Ausrichtung: dadurch zentriert align-items:center in .eflow-middle
   die KREIS-Mittelpunkte von Netz/Hub/Verbrauch exakt zueinander, statt wie zuvor die
   unterschiedlich hohen Kreis+Text-Säulen als Ganzes (siehe Kommentar oben am Dateianfang). */
.eflow-node { position:relative; z-index:1; display:flex; justify-content:center; width:100px; }
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
.eflow-text { position:absolute; top:100%; left:0; width:100%; margin-top:.3rem; }
.eflow-value { font-weight:700; font-size:.95rem; color:var(--gray-800); text-align:center; }
.eflow-label { font-size:.72rem; color:var(--gray-600); text-align:center; }
/* Platz für .eflow-text, die seit obigem Fix nicht mehr in der Fluss-Höhe mitzählt -- ohne das
   würde der PV-Text mit der Netz/Hub/Verbrauch-Reihe überlappen bzw. deren eigener Text mit den
   nachfolgenden Karten-Absätzen. Reichlich bemessen (zwei kurze Textzeilen + Abstand passen bei
   Weitem hinein), kein Bezug zu den festen Pixelwerten der Animation weiter unten. */
.eflow-node[data-eflow-node="pv"] { margin-bottom: 2.6rem; }
.eflow-middle { position:relative; z-index:1; display:flex; align-items:center; gap:1.75rem; margin-top:.25rem; margin-bottom: 2.6rem; }
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
  // Gerade Linie zwischen zwei Kreisen, exakt am jeweiligen Kreisrand beginnend/endend (nicht
  // am Mittelpunkt) -- Patrick: "Keine sichtbare Lücke zwischen Kreis und animiertem
  // Energiefluss" bzw. "die direkte kürzeste gerade Strecke". Einzige Verbindungsform -- keine
  // Kurven/Bögen, auch nicht bei PV (läuft bewusst durch den Text, siehe Kommentar oben).
  function trimStraight(a, b) {
    const dx = b.cx - a.cx, dy = b.cy - a.cy, dist = Math.hypot(dx, dy) || 1;
    const ux = dx / dist, uy = dy / dist;
    return { x1: a.cx + ux * a.radius, y1: a.cy + uy * a.radius, x2: b.cx - ux * b.radius, y2: b.cy - uy * b.radius };
  }
  // Erzwingt eine exakt waagrechte Linie (Patrick, 24.08.2026: "Bitte mach die Linien bei Netz
  // und Verbrauch waagrecht. das schiefe gefällt mir nicht.") -- Netz/Verbrauch und der EEG-Knoten
  // liegen zwar alle in derselben Flex-Reihe, aber leicht unterschiedlich hohe Beschriftungen
  // darunter können die gemessene Kreis-Mitte um ein, zwei Pixel verschieben und die Linie dadurch
  // sichtbar schräg wirken lassen. Nimmt deshalb bewusst NUR die Y-Koordinate des EEG-Knotens als
  // gemeinsame Höhe für beide Seiten, nicht den Mittelwert der gemessenen Mitten.
  function trimHorizontal(a, b, y) {
    const dir = b.cx >= a.cx ? 1 : -1;
    return { x1: a.cx + dir * a.radius, y1: y, x2: b.cx - dir * b.radius, y2: y };
  }
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const XLINK_NS = 'http://www.w3.org/1999/xlink';
  function svgEl(tag) { return document.createElementNS(SVG_NS, tag); }

  // Bewegungsdauer + Pause eines Impulses (Patrick: "Bewegung: ca. 0,8-1,2 Sekunden, Pause nach
  // Ankunft: exakt ca. 0,5 Sekunden").
  const PULSE_MOVE_S = 1;
  const PULSE_PAUSE_S = 0.5;
  // Voller Takt: Phase "rein" (1s) + Pause (0.5s) + Phase "raus" (1s) + Pause (0.5s) = 3s.
  const CYCLE_S = 2 * (PULSE_MOVE_S + PULSE_PAUSE_S);
  const OUT_START_S = PULSE_MOVE_S + PULSE_PAUSE_S;

  // Zeichnet eine Basislinie (dezent, immer sichtbar) + EIN einzelner animierter Impuls (statt
  // mehrerer gleichzeitig): startet am Ausgangskreis, läuft zum Zielkreis, verschwindet
  // vollständig, macht Pause bis zum nächsten Takt derselben Phase. "startOffsetS" ist entweder
  // 0 (Phase "rein") oder OUT_START_S (Phase "raus") -- ALLE Verbindungen derselben Phase
  // bekommen exakt denselben startOffsetS und laufen dadurch zwangsläufig synchron, ohne dass
  // eine Verbindung auf eine andere verweisen müsste (Patrick: "zuerst alle Energieflüsse rein
  // in die EEG [...] dann [...] alle Flüsse raus"). Kettet sich danach selbst weiter über
  // begin="<startOffset>s;<eigene-id>.end+<Pause bis zum naechsten Takt>s" -- dasselbe
  // Selbstreferenz-Idiom wie in der vorherigen Fassung (siehe Sitzungslog), nur mit variablem
  // Start. Bewusst KEINE Querverweise zwischen zwei dynamisch per JS erzeugten Elementen (etwa
  // ein gemeinsamer unsichtbarer "Zeitgeber", an den sich alle Impulse per id.begin anhängen) --
  // ein erster Versuch in genau dieser Form blieb in Chromium wirkungslos (die referenzierenden
  // Impulse feuerten nie, vermutlich weil Chromiums SMIL-Sync-Base-Auflösung bei zur Laufzeit per
  // appendChild() eingefügten Elementen nicht zuverlässig zwischen zwei brandneuen Elementen
  // auflöst) -- mit Playwright-Zeitstempel-Polling verifiziert, siehe Sitzungslog.
  function buildConnector(svg, id, pathD, color, active, startOffsetS) {
    const path = svgEl('path');
    path.setAttribute('id', id);
    path.setAttribute('class', 'eflow-baseline');
    path.setAttribute('d', pathD);
    svg.appendChild(path);
    if (!active) return;

    const motionId = id + '-motion';
    const begin = startOffsetS + 's;' + motionId + '.end+' + (CYCLE_S - PULSE_MOVE_S) + 's';

    const dot = svgEl('circle');
    dot.setAttribute('r', 4);
    dot.setAttribute('class', 'eflow-pulse');
    dot.setAttribute('fill', color);
    dot.setAttribute('opacity', 0);

    const motion = svgEl('animateMotion');
    motion.setAttribute('id', motionId);
    motion.setAttribute('dur', PULSE_MOVE_S + 's');
    motion.setAttribute('begin', begin);
    motion.setAttribute('fill', 'freeze');
    const mpath = svgEl('mpath');
    mpath.setAttributeNS(XLINK_NS, 'href', '#' + id);
    motion.appendChild(mpath);
    dot.appendChild(motion);

    // Sichtbarkeit synchron zur selben Phase: kurz einblenden, unterwegs sichtbar, kurz vor
    // Ankunft ausblenden -- "friert" bei 0 ein, bis der nächste Takt derselben Phase beginnt.
    const fade = svgEl('animate');
    fade.setAttribute('attributeName', 'opacity');
    fade.setAttribute('values', '0;1;1;0');
    fade.setAttribute('keyTimes', '0;0.15;0.85;1');
    fade.setAttribute('dur', PULSE_MOVE_S + 's');
    fade.setAttribute('begin', begin);
    fade.setAttribute('fill', 'freeze');
    dot.appendChild(fade);

    svg.appendChild(dot);
  }

  function renderEflow(einspW, bezugW) {
    const container = document.getElementById('eflow');
    const svg = document.getElementById('eflow-svg');
    if (!container || !svg) return;
    const containerRect = container.getBoundingClientRect();
    svg.setAttribute('viewBox', '0 0 ' + containerRect.width + ' ' + containerRect.height);
    svg.innerHTML = '';

    const pv = nodeCircle(container, containerRect, 'pv');
    const netz = nodeCircle(container, containerRect, 'netz');
    const verbrauch = nodeCircle(container, containerRect, 'verbrauch');
    const hub = nodeCircle(container, containerRect, 'hub');
    const netzW = einspW - bezugW;

    // PV -> EEG: ein Verbraucher speist nie in die Gemeinschaft ein, deshalb immer diese Richtung
    // und immer Phase "rein" (speist in den EEG-Pool ein, startet bei 0s).
    const pvLine = trimStraight(pv, hub);
    buildConnector(svg, 'eflow-path-pv', 'M ' + pvLine.x1 + ' ' + pvLine.y1 + ' L ' + pvLine.x2 + ' ' + pvLine.y2, '#eab308', einspW > 0, 0);

    // EEG -> Verbrauch: ein Mitglied bezieht nur, speist nie zurück in die Gemeinschaft -- immer
    // Phase "raus" (startet bei OUT_START_S). Waagrecht auf Höhe des EEG-Knotens erzwungen
    // (siehe trimHorizontal()).
    const verbLine = trimHorizontal(hub, verbrauch, hub.cy);
    buildConnector(svg, 'eflow-path-verbrauch', 'M ' + verbLine.x1 + ' ' + verbLine.y1 + ' L ' + verbLine.x2 + ' ' + verbLine.y2, '#3b82f6', bezugW > 0, OUT_START_S);

    // Netz <-> EEG: Richtung hängt vom Vorzeichen ab (Bezug: Netz -> EEG = Phase "rein",
    // Einspeisung: EEG -> Netz = Phase "raus") -- dafür schlicht die passende Linie UND Phase
    // wählen, statt denselben Pfad nachträglich umzukehren. Ebenfalls waagrecht auf Höhe des
    // EEG-Knotens erzwungen.
    const netzLine = netzW < 0 ? trimHorizontal(netz, hub, hub.cy) : trimHorizontal(hub, netz, hub.cy);
    buildConnector(svg, 'eflow-path-netz', 'M ' + netzLine.x1 + ' ' + netzLine.y1 + ' L ' + netzLine.x2 + ' ' + netzLine.y2, netzW < 0 ? '#dc2626' : '#16a34a', netzW !== 0, netzW < 0 ? 0 : OUT_START_S);
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
