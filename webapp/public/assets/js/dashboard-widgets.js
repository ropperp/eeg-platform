// Startseiten-Kästen selbst anordnen (DashboardWidgets in src/dashboard_widgets.php).
// "Anordnen" schaltet den Bearbeiten-Modus ein: Kästen per Ziehen (Maus) oder mit ↑/↓
// (Handy, Tastatur) verschieben, mit dem Auge ausblenden (Leiste "Ausgeblendet" zum Zurückholen),
// am Laptop rechts die Breite, unten die Höhe, an der Ecke beides ziehen -- nie kleiner als der
// Inhalt. Der Bearbeiten-Modus zeigt die Kästen in echter Größe, also genau wie danach.
// Laptop und Handy haben je eine eigene Anordnung (14.11.2026);
// jede Änderung wird nach kurzer Pause automatisch im Konto gespeichert
// (POST /portal/dashboard-layout, mit device=desktop|mobile).
(function () {
  'use strict';

  var MOBILE = window.matchMedia('(max-width: 700px)');
  var WIDE = window.matchMedia('(min-width: 901px)'); // ab hier stehen Kästen nebeneinander
  var SPANS = [4, 6, 8, 12];

  function device() { return MOBILE.matches ? 'mobile' : 'desktop'; }

  // Mauerwerk-Anordnung: jeder Kasten bekommt so viele 4-px-Zeilen, wie seine Höhe plus Abstand
  // braucht (Abstand 24 px = 1,5 rem, siehe app.css). Neu berechnet, sobald sich ein Kasten
  // vergrößert/verkleinert (Live-Werte, Schriften, Breitenänderung) oder das Fenster ändert.
  var ROW = 4, GAP = 24;
  function layoutMasonry(box) {
    if (!WIDE.matches) return;
    box.classList.add('is-masonry');
    box.querySelectorAll(':scope > .dash-widget').forEach(function (w) {
      if (getComputedStyle(w).display === 'none') return;
      var h = w.getBoundingClientRect().height;
      w.style.setProperty('--rows', String(Math.max(1, Math.ceil((h + GAP) / ROW))));
    });
  }
  function watchMasonry(box) {
    var pending = false;
    function schedule() {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () { pending = false; layoutMasonry(box); });
    }
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(schedule);
      box.querySelectorAll(':scope > .dash-widget').forEach(function (w) { ro.observe(w); });
    }
    window.addEventListener('resize', schedule);
    WIDE.addEventListener('change', schedule);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);
    layoutMasonry(box);
    box._masonry = schedule;
  }

  document.querySelectorAll('.dash-widgets[data-dashboard]').forEach(function (box) {
    var key = box.getAttribute('data-dashboard');
    var layouts = {};
    try { layouts = JSON.parse(box.getAttribute('data-layouts') || '{}'); } catch (e) { layouts = {}; }

    function widgets() { return Array.prototype.slice.call(box.querySelectorAll(':scope > .dash-widget')); }
    function byId(id) { return box.querySelector(':scope > .dash-widget[data-widget="' + id + '"]'); }

    function relayout() { if (box._masonry) box._masonry(); }

    function setSpan(w, span) {
      w.dataset.span = String(span);
      w.style.setProperty('--span', String(span));
      relayout();
    }

    function setHeight(w, px) {
      w.dataset.height = String(px || 0);
      if (px) w.style.setProperty('--min-h', px + 'px'); else w.style.removeProperty('--min-h');
      relayout();
    }

    // Natürliche Höhe = was der Inhalt mindestens braucht (ohne eigene Höhe, ohne Strecken in
    // der Reihe) -- darunter lässt sich ein Kasten nicht ziehen.
    function naturalHeight(w) {
      var oldMin = w.style.getPropertyValue('--min-h');
      w.style.removeProperty('--min-h');
      var hgt = w.getBoundingClientRect().height;
      if (oldMin) w.style.setProperty('--min-h', oldMin);
      return Math.ceil(hgt);
    }

    function setHidden(w, hidden) {
      w.classList.toggle('is-hidden-widget', hidden);
      relayout();
      var t = w.querySelector('.dash-toggle');
      if (!t) return;
      t.setAttribute('aria-pressed', hidden ? 'false' : 'true');
      var use = t.querySelector('use');
      if (use) use.setAttribute('href', use.getAttribute('href').replace(/#ph-eye(-slash)?$/, hidden ? '#ph-eye-slash' : '#ph-eye'));
    }

    // Anordnung des aktuellen Geräts anwenden (beim Laden sofort, und beim Wechsel der Breite)
    function applyLayout() {
      var l = layouts[device()];
      if (!l) return;
      (l.order || []).forEach(function (id) { var w = byId(id); if (w) box.appendChild(w); });
      var hidden = l.hidden || [];
      var sizes = l.sizes || {};
      var heights = l.heights || {};
      widgets().forEach(function (w) {
        setHidden(w, hidden.indexOf(w.dataset.widget) !== -1);
        setSpan(w, device() === 'desktop' && sizes[w.dataset.widget] ? sizes[w.dataset.widget] : 12);
        setHeight(w, device() === 'desktop' && heights[w.dataset.widget] ? heights[w.dataset.widget] : 0);
      });
      if (typeof renderTray === 'function') renderTray();
    }
    applyLayout();
    watchMasonry(box);
    var currentDevice = device();
    MOBILE.addEventListener('change', function () {
      if (box.classList.contains('is-editing')) { flush(); }
      currentDevice = device();
      applyLayout();
    });

    var toolbar = document.querySelector('[data-dash-toolbar="' + key + '"]');
    if (!toolbar) return; // Kundenansicht: nur anzeigen, nicht ändern
    var editBtn = toolbar.querySelector('.dash-btn-edit');
    var resetBtn = toolbar.querySelector('.dash-btn-reset');
    var hint = toolbar.querySelector('.dash-edit-hint');
    var form = toolbar.querySelector('.dash-layout-form');
    var saveTimer = null;
    var dragged = null;

    function setEditing(on) {
      box.classList.toggle('is-editing', on);
      editBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      editBtn.querySelector('span').textContent = on ? 'Fertig' : 'Anordnen';
      hint.hidden = !on;
      resetBtn.hidden = !on;
      widgets().forEach(function (w) {
        w.querySelector('.dash-widget-bar').hidden = !on;
        w.querySelectorAll('.dash-resize').forEach(function (r) { r.hidden = !on; });
        w.draggable = on;
      });
      renderTray();
      if (!on) flush();
    }

    // Ausgeblendete Kästen: im Bearbeiten-Modus NICHT im Raster (sonst sähe es anders aus als
    // danach), sondern als kleine Knöpfe in der Leiste oben zum Wieder-Einblenden.
    var tray = toolbar.querySelector('.dash-hidden-tray');
    function renderTray() {
      if (!tray) return;
      tray.querySelectorAll('button').forEach(function (b) { b.remove(); });
      var hiddenWs = widgets().filter(function (w) { return w.classList.contains('is-hidden-widget'); });
      hiddenWs.forEach(function (w) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'dash-unhide';
        b.dataset.widget = w.dataset.widget;
        b.textContent = '+ ' + (w.dataset.title || w.dataset.widget);
        b.setAttribute('aria-label', (w.dataset.title || w.dataset.widget) + ' wieder einblenden');
        tray.appendChild(b);
      });
      tray.hidden = !box.classList.contains('is-editing') || hiddenWs.length === 0;
    }
    if (tray) {
      tray.addEventListener('click', function (e) {
        var b = e.target.closest('.dash-unhide');
        if (!b) return;
        var w = byId(b.dataset.widget);
        if (!w) return;
        setHidden(w, false);
        renderTray();
        w.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        changed();
      });
    }

    function currentLayout() {
      var ws = widgets();
      var l = {
        order: ws.map(function (w) { return w.dataset.widget; }),
        hidden: ws.filter(function (w) { return w.classList.contains('is-hidden-widget'); })
          .map(function (w) { return w.dataset.widget; })
      };
      if (currentDevice === 'desktop') {
        l.sizes = {};
        l.heights = {};
        ws.forEach(function (w) {
          var s = parseInt(w.dataset.span, 10); if (s && s !== 12) l.sizes[w.dataset.widget] = s;
          var hgt = parseInt(w.dataset.height, 10); if (hgt) l.heights[w.dataset.widget] = hgt;
        });
      }
      return l;
    }

    function send(extra) {
      var data = new FormData(form);
      data.set('device', currentDevice);
      Object.keys(extra).forEach(function (k) { data.set(k, extra[k]); });
      return fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    }

    function save() {
      saveTimer = null;
      var l = currentLayout();
      layouts[currentDevice] = l;
      send({ layout: JSON.stringify(l) })
        .then(function () { flash('Gespeichert ✓'); })
        .catch(function () { flash('Konnte nicht gespeichert werden', true); });
    }
    function flush() { if (saveTimer) { clearTimeout(saveTimer); save(); } }

    function changed() {
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(save, 500);
    }

    function flash(text, isError) {
      var el = toolbar.querySelector('.dash-flash');
      if (!el) {
        el = document.createElement('span');
        el.className = 'dash-flash';
        el.setAttribute('role', 'status');
        toolbar.querySelector('.dash-toolbar-buttons').prepend(el);
      }
      el.textContent = text;
      el.classList.toggle('is-error', !!isError);
      el.classList.add('is-visible');
      setTimeout(function () { el.classList.remove('is-visible'); }, 1800);
    }

    editBtn.addEventListener('click', function () {
      setEditing(!box.classList.contains('is-editing'));
    });

    resetBtn.addEventListener('click', function () {
      var label = currentDevice === 'mobile' ? 'Handy' : 'Laptop';
      if (!confirm('Standard-Anordnung für dieses Gerät (' + label + ') wiederherstellen?')) return;
      send({ reset: '1' }).then(function () { location.reload(); })
        .catch(function () { flash('Konnte nicht zurückgesetzt werden', true); });
    });

    box.addEventListener('click', function (e) {
      var move = e.target.closest('.dash-move');
      var toggle = e.target.closest('.dash-toggle');
      if (move) {
        var w = move.closest('.dash-widget');
        var dir = parseInt(move.dataset.dir, 10);
        var sibling = dir < 0 ? w.previousElementSibling : w.nextElementSibling;
        if (!sibling) return;
        if (dir < 0) box.insertBefore(w, sibling); else box.insertBefore(sibling, w);
        move.focus();
        w.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        changed();
      } else if (toggle) {
        var tw = toggle.closest('.dash-widget');
        setHidden(tw, !tw.classList.contains('is-hidden-widget'));
        renderTray();
        changed();
      }
    });

    // Ziehen mit der Maus (nur im Bearbeiten-Modus, Kästen sind dann draggable)
    box.addEventListener('dragstart', function (e) {
      if (e.target.closest('.dash-resize')) { e.preventDefault(); return; }
      dragged = e.target.closest('.dash-widget');
      if (!dragged) return;
      dragged.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragged.dataset.widget); } catch (err) { /* alte Browser */ }
    });
    box.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();
      var over = e.target.closest('.dash-widget');
      if (!over || over === dragged) return;
      var rect = over.getBoundingClientRect();
      // Nebeneinander stehende Kästen: links/rechts entscheidet, sonst oben/unten
      var sideBySide = WIDE.matches && rect.width < box.getBoundingClientRect().width * 0.9;
      var after = sideBySide ? e.clientX > rect.left + rect.width / 2 : e.clientY > rect.top + rect.height / 2;
      box.insertBefore(dragged, after ? over.nextElementSibling : over);
    });
    box.addEventListener('dragend', function () {
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      dragged = null;
      changed();
    });

    // Größe am Laptop: rechter Rand = Breite (rastet auf 1/3, 1/2, 2/3, volle Breite ein),
    // unterer Rand = Höhe (in 10-px-Schritten, nie kleiner als der Inhalt), Ecke = beides.
    // Per Tastatur: Pfeile links/rechts am Breiten-, auf/ab am Höhen-Griff.
    function nearestSpan(raw) {
      return SPANS.reduce(function (best, s) { return Math.abs(s - raw) < Math.abs(best - raw) ? s : best; }, 12);
    }
    function clampHeight(w, px, minPx) {
      px = Math.round(px / 10) * 10;
      if (px <= minPx + 10) return 0; // so klein wie nötig = automatisch
      return Math.min(px, 2000);
    }
    box.addEventListener('pointerdown', function (e) {
      var handle = e.target.closest('.dash-resize');
      if (!handle || !WIDE.matches) return;
      e.preventDefault();
      var axis = handle.dataset.axis || 'x';
      var w = handle.closest('.dash-widget');
      var start = { span: parseInt(w.dataset.span, 10) || 12, height: parseInt(w.dataset.height, 10) || 0 };
      var rect = w.getBoundingClientRect();
      var col = box.getBoundingClientRect().width / 12;
      var minPx = 0;
      var startY = e.clientY;
      // Während des Ziehens keine Scroll-Verankerung, sonst scrollt der Browser beim Wachsen mit
      // und der Kasten wächst schneller als die Maus.
      var anchored = [document.documentElement, document.body];
      var oldAnchor = anchored.map(function (el) { return el.style.overflowAnchor; });
      anchored.forEach(function (el) { el.style.overflowAnchor = 'none'; });
      w.classList.add('is-resizing');
      handle.setPointerCapture(e.pointerId);
      function onMove(ev) {
        if (axis.indexOf('x') !== -1) {
          setSpan(w, nearestSpan((ev.clientX - rect.left) / col));
        }
        if (axis.indexOf('y') !== -1) {
          minPx = naturalHeight(w); // nach einer Breitenänderung braucht der Inhalt evtl. mehr
          var wanted = rect.height + (ev.clientY - startY);
          setHeight(w, clampHeight(w, wanted, minPx));
        }
      }
      function onUp() {
        anchored.forEach(function (el, i) { el.style.overflowAnchor = oldAnchor[i]; });
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onUp);
        handle.removeEventListener('pointercancel', onUp);
        w.classList.remove('is-resizing');
        // Eine gespeicherte Höhe, die nach schmälerem Kasten unter dem Inhalt läge, verwerfen
        var h = parseInt(w.dataset.height, 10) || 0;
        if (h && h <= naturalHeight(w) + 10) setHeight(w, 0);
        if ((parseInt(w.dataset.span, 10) || 12) !== start.span || (parseInt(w.dataset.height, 10) || 0) !== start.height) changed();
      }
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup', onUp);
      handle.addEventListener('pointercancel', onUp);
    });
    box.addEventListener('keydown', function (e) {
      var handle = e.target.closest('.dash-resize');
      if (!handle) return;
      var w = handle.closest('.dash-widget');
      if (handle.dataset.axis === 'x' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
        e.preventDefault();
        var i = SPANS.indexOf(parseInt(w.dataset.span, 10) || 12);
        var next = SPANS[Math.max(0, Math.min(SPANS.length - 1, i + (e.key === 'ArrowRight' ? 1 : -1)))];
        if (next !== parseInt(w.dataset.span, 10)) { setSpan(w, next); changed(); }
      } else if (handle.dataset.axis === 'y' && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        var minPx = naturalHeight(w);
        var cur = parseInt(w.dataset.height, 10) || w.getBoundingClientRect().height;
        var nextH = clampHeight(w, cur + (e.key === 'ArrowDown' ? 40 : -40), minPx);
        if (nextH !== (parseInt(w.dataset.height, 10) || 0)) { setHeight(w, nextH); changed(); }
      }
    });
  });
})();
