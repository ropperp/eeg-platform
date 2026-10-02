/**
 * Energiefluss-Diagramm (PV -> EEG -> Verbrauch, EEG <-> Netz) -- Animations-Engine, gemeinsam
 * genutzt von partials/energy_flow_diagram.php (eingebunden sowohl im Obmann-/Mitglied-Portal
 * als auch auf der öffentlichen Live-Seite, siehe dort).
 *
 * Ersetzt seit 02.10.2026 die vorherige SMIL-<animateMotion>-Fassung. Patrick: "Der Punkt, der
 * da wandert: Ab und zu wird der gar nicht angezeigt, ab und zu wird da mitten im sogenannten
 * 'Fließen' unterbrochen, weil gerade ein neuer Zahlenwert kommt. [...] Wenn sich der zum
 * Beispiel gerade ändert, dann fährt der Punkt noch weiter, und wenn ein neuer Punkt kommt, dann
 * nimmt er den neuen Wert." Ursache der alten Fassung: der aufrufende Code hat bei JEDEM
 * 5s-Datenrefresh das komplette SVG (svg.innerHTML = '') geleert und alle <animateMotion>-
 * Elemente neu erzeugt -- SMIL-Animationen starten dabei zwangsläufig wieder bei ihrem eigenen
 * "begin"-Zeitpunkt neu, unabhängig davon, wie weit die vorherige Animation schon war; traf der
 * Refresh "ungünstig" mitten in einer Bewegung, wirkte das wie ein Abreißen/Verschwinden.
 *
 * Diese Fassung baut das SVG (Basislinie + Glow-Trail + Punkt pro Verbindung) GENAU EINMAL beim
 * ersten Aufruf von init() auf und ändert danach nur noch Attribute auf denselben Elementen --
 * nie mehr Löschen+Neuerzeugen. Die Punktposition kommt aus einer eigenen, beim Laden der Seite
 * gestarteten requestAnimationFrame-Zeitachse, die von Datenrefreshes komplett unabhängig
 * weiterläuft. Ein Refresh (update()) ändert nur noch: OB eine Verbindung gerade aktiv ist, in
 * welche Richtung (nur bei "Netz" relevant) und die Text-/Zahlenwerte -- nie die Bewegung selbst.
 *
 * Schimmernder Glow-Trail (Patrick: "mit vielleicht dahinter so einem schimmernden Strahl"):
 * ein kurzer Linienabschnitt mit Farbverlauf (transparent -> Verbindungsfarbe) läuft dem Punkt
 * voraus, zusätzlich zum bereits vorhandenen drop-shadow-Glow auf dem Punkt selbst (siehe CSS
 * .eflow-trail/.eflow-pulse in app.css).
 */
(function () {
  var SVG_NS = 'http://www.w3.org/2000/svg';
  function svgEl(tag) { return document.createElementNS(SVG_NS, tag); }

  // Bewegungsdauer + Pause eines Impulses (Patrick: "Bewegung: ca. 0,8-1,2 Sekunden, Pause nach
  // Ankunft: exakt ca. 0,5 Sekunden") -- unverändert aus der SMIL-Vorfassung übernommen, nur die
  // Umsetzung (rAF statt SMIL) hat sich geändert.
  var PULSE_MOVE_S = 1;
  var PULSE_PAUSE_S = 0.5;
  // Voller Takt: Phase "rein" (1s) + Pause (0.5s) + Phase "raus" (1s) + Pause (0.5s) = 3s.
  var CYCLE_S = 2 * (PULSE_MOVE_S + PULSE_PAUSE_S);
  var OUT_START_S = PULSE_MOVE_S + PULSE_PAUSE_S;
  // Anteil der Bewegungsphase, den der Glow-Trail hinter dem Punkt bedeckt.
  var TRAIL_FRACTION = 0.32;

  // ─── Geometrie-Helfer (unverändert aus der Vorfassung übernommen) ─────────────────────────
  function nodeCircle(container, containerRect, name) {
    var el = container.querySelector('[data-eflow-node="' + name + '"]');
    var target = el.classList.contains('eflow-hub') ? el : el.querySelector('.eflow-circle');
    var r = target.getBoundingClientRect();
    return {
      cx: r.left + r.width / 2 - containerRect.left,
      cy: r.top + r.height / 2 - containerRect.top,
      radius: r.width / 2,
    };
  }
  // Gerade Linie zwischen zwei Kreisen, exakt am jeweiligen Kreisrand beginnend/endend -- Patrick:
  // "die direkte kürzeste gerade Strecke". Einzige Verbindungsform, auch bei PV (läuft bewusst
  // durch den Text "PV-Erzeugung"/"676 W").
  function trimStraight(a, b) {
    var dx = b.cx - a.cx, dy = b.cy - a.cy, dist = Math.hypot(dx, dy) || 1;
    var ux = dx / dist, uy = dy / dist;
    return { x1: a.cx + ux * a.radius, y1: a.cy + uy * a.radius, x2: b.cx - ux * b.radius, y2: b.cy - uy * b.radius };
  }
  // Erzwingt eine exakt waagrechte Linie für Netz/Verbrauch (Patrick: "das schiefe gefällt mir
  // nicht") -- nimmt bewusst nur die Y-Koordinate des EEG-Knotens als gemeinsame Höhe.
  function trimHorizontal(a, b, y) {
    var dir = b.cx >= a.cx ? 1 : -1;
    return { x1: a.cx + dir * a.radius, y1: y, x2: b.cx - dir * b.radius, y2: y };
  }
  // Ein-/Ausblenden während der Bewegungsphase (ersetzt die SMIL-<animate> opacity-keyTimes
  // 0/0.15/0.85/1 aus der Vorfassung).
  function fadeForProgress(p) {
    if (p < 0.15) return p / 0.15;
    if (p > 0.85) return (1 - p) / 0.15;
    return 1;
  }

  var instanceCounter = 0;

  function init(containerId) {
    var container = document.getElementById(containerId);
    var noop = { update: function () {} };
    if (!container) return noop;
    var svg = container.querySelector('.eflow-svg');
    if (!svg) return noop;

    instanceCounter++;
    var defs = svgEl('defs');
    svg.appendChild(defs);

    // Jede Verbindung besteht aus: einer stets sichtbaren dezenten Basislinie, einem
    // schimmernden Trail und dem Punkt selbst -- alle drei werden hier EINMAL erzeugt und
    // danach nur noch per Attribut aktualisiert (siehe Datei-Kommentar oben).
    function makeConnector(gradId, color) {
      var baseline = svgEl('path');
      baseline.setAttribute('class', 'eflow-baseline');
      svg.appendChild(baseline);

      var grad = svgEl('linearGradient');
      grad.setAttribute('id', gradId);
      // userSpaceOnUse statt des Default-objectBoundingBox: bei einer EXAKT senkrechten
      // Verbindung (PV -> EEG steht immer genau senkrecht übereinander, siehe Layout) hat die
      // Bounding-Box des Trails eine Breite von 0 -- der Default-Gradient (verläuft annahmegemäß
      // waagrecht, x1=0%/x2=100%) kollabiert dadurch zu einem einzelnen Punkt und wird nur noch
      // einfarbig (kein Verlauf mehr) gerendert. Mit userSpaceOnUse werden x1/y1/x2/y2 stattdessen
      // jeden Frame explizit auf die tatsächlichen Trail-Endpunkte gesetzt (siehe frame()) --
      // funktioniert dadurch unabhängig von der Linienrichtung (senkrecht/waagrecht/schräg).
      grad.setAttribute('gradientUnits', 'userSpaceOnUse');
      var stop1 = svgEl('stop');
      stop1.setAttribute('offset', '0%');
      stop1.setAttribute('stop-opacity', '0');
      stop1.setAttribute('stop-color', color);
      var stop2 = svgEl('stop');
      stop2.setAttribute('offset', '100%');
      stop2.setAttribute('stop-opacity', '0.95');
      stop2.setAttribute('stop-color', color);
      grad.appendChild(stop1);
      grad.appendChild(stop2);
      defs.appendChild(grad);

      var trail = svgEl('line');
      trail.setAttribute('class', 'eflow-trail');
      trail.setAttribute('stroke', 'url(#' + gradId + ')');
      trail.setAttribute('stroke-width', 3);
      trail.setAttribute('stroke-linecap', 'round');
      trail.style.color = color;
      trail.setAttribute('opacity', 0);
      svg.appendChild(trail);

      var dot = svgEl('circle');
      dot.setAttribute('r', 5.5);
      dot.setAttribute('class', 'eflow-pulse');
      dot.setAttribute('fill', color);
      dot.style.color = color;
      dot.setAttribute('opacity', 0);
      svg.appendChild(dot);

      return {
        baseline: baseline, trail: trail, dot: dot, grad: grad, stop1: stop1, stop2: stop2,
        active: false, phase: 'in',
        p1: { x: 0, y: 0 }, p2: { x: 0, y: 0 },
      };
    }

    var conn = {
      pv:        makeConnector('eflow-grad-pv-' + instanceCounter, '#eab308'),
      verbrauch: makeConnector('eflow-grad-verbrauch-' + instanceCounter, '#3b82f6'),
      netz:      makeConnector('eflow-grad-netz-' + instanceCounter, '#16a34a'),
    };

    function setConnectorPath(c, p1, p2) {
      c.p1 = p1; c.p2 = p2;
      c.baseline.setAttribute('d', 'M ' + p1.x + ' ' + p1.y + ' L ' + p2.x + ' ' + p2.y);
    }

    function recomputeGeometry(einspW, bezugW) {
      var containerRect = container.getBoundingClientRect();
      svg.setAttribute('viewBox', '0 0 ' + containerRect.width + ' ' + containerRect.height);

      var pv = nodeCircle(container, containerRect, 'pv');
      var netz = nodeCircle(container, containerRect, 'netz');
      var verbrauch = nodeCircle(container, containerRect, 'verbrauch');
      var hub = nodeCircle(container, containerRect, 'hub');

      var pvLine = trimStraight(pv, hub);
      setConnectorPath(conn.pv, { x: pvLine.x1, y: pvLine.y1 }, { x: pvLine.x2, y: pvLine.y2 });

      var verbLine = trimHorizontal(hub, verbrauch, hub.cy);
      setConnectorPath(conn.verbrauch, { x: verbLine.x1, y: verbLine.y1 }, { x: verbLine.x2, y: verbLine.y2 });

      var netzW = einspW - bezugW;
      var netzLine = netzW < 0 ? trimHorizontal(netz, hub, hub.cy) : trimHorizontal(hub, netz, hub.cy);
      setConnectorPath(conn.netz, { x: netzLine.x1, y: netzLine.y1 }, { x: netzLine.x2, y: netzLine.y2 });
    }

    var lastEinspW = 0, lastBezugW = 0;

    // Wird bei jedem Datenrefresh aufgerufen -- ändert NUR, ob/in welche Richtung eine
    // Verbindung aktiv ist, die Pfadgeometrie (falls sich die Netz-Richtung umkehrt) und die
    // Text-Beschriftungen. Die laufende Bewegung selbst (siehe frame()) bleibt davon unberührt.
    function applyValues(einspW, bezugW) {
      lastEinspW = einspW; lastBezugW = bezugW;
      var netzW = einspW - bezugW;

      conn.pv.active = einspW > 0;
      conn.verbrauch.active = bezugW > 0;
      conn.netz.active = netzW !== 0;
      conn.netz.phase = netzW < 0 ? 'in' : 'out';

      var netzColor = netzW < 0 ? '#dc2626' : '#16a34a';
      conn.netz.dot.setAttribute('fill', netzColor);
      conn.netz.dot.style.color = netzColor;
      conn.netz.trail.style.color = netzColor;
      conn.netz.stop1.setAttribute('stop-color', netzColor);
      conn.netz.stop2.setAttribute('stop-color', netzColor);

      recomputeGeometry(einspW, bezugW);

      // Text/Beschriftungen innerhalb des Diagramms -- nur gesetzt, falls im jeweiligen
      // Aufrufer vorhanden (beide Einbindungen haben sie aktuell, aber robust gegen Varianten).
      var pvEl = container.querySelector('#ef-pv');
      if (pvEl) pvEl.textContent = Math.round(einspW).toLocaleString('de-AT') + ' W';
      var vbEl = container.querySelector('#ef-verbrauch');
      if (vbEl) vbEl.textContent = Math.round(bezugW).toLocaleString('de-AT') + ' W';
      var nzEl = container.querySelector('#ef-netz');
      if (nzEl) nzEl.textContent = Math.round(Math.abs(netzW)).toLocaleString('de-AT') + ' W';
      var nzLabelEl = container.querySelector('#ef-netz-label');
      if (nzLabelEl) nzLabelEl.textContent = netzW > 0 ? 'Netz (Einspeisung)' : (netzW < 0 ? 'Netz (Bezug)' : 'Netz');
      var nzCircleEl = container.querySelector('#ef-netz-circle');
      if (nzCircleEl) {
        nzCircleEl.classList.remove('eflow-in', 'eflow-out');
        if (netzW > 0) nzCircleEl.classList.add('eflow-out');
        else if (netzW < 0) nzCircleEl.classList.add('eflow-in');
      }
    }

    // ─── Dauerhafte Zeitachse + rAF-Loop ───────────────────────────────────────────────────
    // startTime wird EINMAL beim Laden der Seite gesetzt und NIE durch einen Datenrefresh
    // zurückgesetzt -- das löst "mitten im Fließen unterbrochen": der Punkt bewegt sich
    // unabhängig davon weiter, ob/wann neue Zahlen eintreffen.
    var startTime = performance.now();
    function frame(now) {
      var t = (now - startTime) / 1000;
      var cyclePos = ((t % CYCLE_S) + CYCLE_S) % CYCLE_S;

      ['pv', 'verbrauch', 'netz'].forEach(function (key) {
        var c = conn[key];
        if (!c.active) {
          c.dot.setAttribute('opacity', 0);
          c.trail.setAttribute('opacity', 0);
          return;
        }
        var localT = c.phase === 'in' ? cyclePos : (cyclePos - OUT_START_S + CYCLE_S) % CYCLE_S;
        if (localT >= PULSE_MOVE_S) {
          c.dot.setAttribute('opacity', 0);
          c.trail.setAttribute('opacity', 0);
          return;
        }
        var progress = localT / PULSE_MOVE_S;
        var x = c.p1.x + (c.p2.x - c.p1.x) * progress;
        var y = c.p1.y + (c.p2.y - c.p1.y) * progress;
        var opacity = fadeForProgress(progress);
        c.dot.setAttribute('cx', x);
        c.dot.setAttribute('cy', y);
        c.dot.setAttribute('opacity', opacity);

        var trailProgress = Math.max(0, progress - TRAIL_FRACTION);
        var tx = c.p1.x + (c.p2.x - c.p1.x) * trailProgress;
        var ty = c.p1.y + (c.p2.y - c.p1.y) * trailProgress;
        c.trail.setAttribute('x1', tx);
        c.trail.setAttribute('y1', ty);
        c.trail.setAttribute('x2', x);
        c.trail.setAttribute('y2', y);
        c.trail.setAttribute('opacity', opacity);
        // Gradient-Vektor jeden Frame auf die AKTUELLEN Trail-Endpunkte legen (userSpaceOnUse,
        // siehe makeConnector()) -- sonst bliebe der Verlauf bei einer senkrechten Verbindung
        // (PV -> EEG) auf einen Punkt kollabiert und würde nur noch einfarbig gerendert.
        c.grad.setAttribute('x1', tx);
        c.grad.setAttribute('y1', ty);
        c.grad.setAttribute('x2', x);
        c.grad.setAttribute('y2', y);
      });

      requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);

    // Bei Größenänderung (Fenster, Sidebar ein-/ausklappen, Handy drehen) nur die Geometrie neu
    // berechnen -- die laufende Animation/Zeitachse bleibt unangetastet.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { recomputeGeometry(lastEinspW, lastBezugW); }, 150);
    });

    return { update: applyValues };
  }

  window.EnergyFlow = { init: init };
})();
