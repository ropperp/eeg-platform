// Startseiten-Kästen selbst anordnen (DashboardWidgets in src/dashboard_widgets.php).
// "Anordnen" schaltet den Bearbeiten-Modus ein: Kästen per Ziehen (Maus) oder mit ↑/↓
// (Handy, Tastatur) verschieben, mit dem Auge aus-/einblenden, am Laptop am rechten Rand
// breiter/schmäler ziehen. Laptop und Handy haben je eine eigene Anordnung (14.11.2026);
// jede Änderung wird nach kurzer Pause automatisch im Konto gespeichert
// (POST /portal/dashboard-layout, mit device=desktop|mobile).
(function () {
  'use strict';

  var MOBILE = window.matchMedia('(max-width: 700px)');
  var WIDE = window.matchMedia('(min-width: 901px)'); // ab hier stehen Kästen nebeneinander
  var SPANS = [4, 6, 8, 12];

  function device() { return MOBILE.matches ? 'mobile' : 'desktop'; }

  document.querySelectorAll('.dash-widgets[data-dashboard]').forEach(function (box) {
    var key = box.getAttribute('data-dashboard');
    var layouts = {};
    try { layouts = JSON.parse(box.getAttribute('data-layouts') || '{}'); } catch (e) { layouts = {}; }

    function widgets() { return Array.prototype.slice.call(box.querySelectorAll(':scope > .dash-widget')); }
    function byId(id) { return box.querySelector(':scope > .dash-widget[data-widget="' + id + '"]'); }

    function setSpan(w, span) {
      w.dataset.span = String(span);
      w.style.setProperty('--span', String(span));
    }

    function setHidden(w, hidden) {
      w.classList.toggle('is-hidden-widget', hidden);
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
      widgets().forEach(function (w) {
        setHidden(w, hidden.indexOf(w.dataset.widget) !== -1);
        setSpan(w, device() === 'desktop' && sizes[w.dataset.widget] ? sizes[w.dataset.widget] : 12);
      });
    }
    applyLayout();
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
        var r = w.querySelector('.dash-resize');
        if (r) r.hidden = !on;
        w.draggable = on;
      });
      if (!on) flush();
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
        ws.forEach(function (w) { var s = parseInt(w.dataset.span, 10); if (s && s !== 12) l.sizes[w.dataset.widget] = s; });
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

    // Breite am Laptop: rechten Rand ziehen (rastet auf 1/3, 1/2, 2/3, volle Breite ein)
    // oder mit den Pfeiltasten links/rechts am Griff.
    function nearestSpan(raw) {
      return SPANS.reduce(function (best, s) { return Math.abs(s - raw) < Math.abs(best - raw) ? s : best; }, 12);
    }
    box.addEventListener('pointerdown', function (e) {
      var handle = e.target.closest('.dash-resize');
      if (!handle || !WIDE.matches) return;
      e.preventDefault();
      var w = handle.closest('.dash-widget');
      var startSpan = parseInt(w.dataset.span, 10) || 12;
      var left = w.getBoundingClientRect().left;
      var col = box.getBoundingClientRect().width / 12;
      w.classList.add('is-resizing');
      handle.setPointerCapture(e.pointerId);
      function onMove(ev) { setSpan(w, nearestSpan((ev.clientX - left) / col)); }
      function onUp() {
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onUp);
        handle.removeEventListener('pointercancel', onUp);
        w.classList.remove('is-resizing');
        if ((parseInt(w.dataset.span, 10) || 12) !== startSpan) changed();
      }
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup', onUp);
      handle.addEventListener('pointercancel', onUp);
    });
    box.addEventListener('keydown', function (e) {
      var handle = e.target.closest('.dash-resize');
      if (!handle || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
      e.preventDefault();
      var w = handle.closest('.dash-widget');
      var i = SPANS.indexOf(parseInt(w.dataset.span, 10) || 12);
      var next = SPANS[Math.max(0, Math.min(SPANS.length - 1, i + (e.key === 'ArrowRight' ? 1 : -1)))];
      if (next !== parseInt(w.dataset.span, 10)) { setSpan(w, next); changed(); }
    });
  });
})();
