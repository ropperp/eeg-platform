// Startseiten-Kästen selbst anordnen (DashboardWidgets in src/dashboard_widgets.php).
// "Anordnen" schaltet den Bearbeiten-Modus ein: Kästen per Ziehen (Maus) oder mit ↑/↓
// (Handy, Tastatur) verschieben, mit dem Auge aus-/einblenden. Jede Änderung wird nach kurzer
// Pause automatisch im Konto gespeichert (POST /portal/dashboard-layout).
(function () {
  'use strict';

  document.querySelectorAll('[data-dash-toolbar]').forEach(function (toolbar) {
    var key = toolbar.getAttribute('data-dash-toolbar');
    var box = document.querySelector('.dash-widgets[data-dashboard="' + key + '"]');
    if (!box) return;
    var editBtn = toolbar.querySelector('.dash-btn-edit');
    var resetBtn = toolbar.querySelector('.dash-btn-reset');
    var hint = toolbar.querySelector('.dash-edit-hint');
    var form = toolbar.querySelector('.dash-layout-form');
    var saveTimer = null;
    var dragged = null;

    function widgets() { return Array.prototype.slice.call(box.querySelectorAll(':scope > .dash-widget')); }

    function setEditing(on) {
      box.classList.toggle('is-editing', on);
      editBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      editBtn.querySelector('span').textContent = on ? 'Fertig' : 'Anordnen';
      hint.hidden = !on;
      resetBtn.hidden = !on;
      widgets().forEach(function (w) {
        w.querySelector('.dash-widget-bar').hidden = !on;
        w.draggable = on;
      });
      if (!on && saveTimer) { clearTimeout(saveTimer); save(); }
    }

    function send(extra) {
      var data = new FormData(form);
      Object.keys(extra).forEach(function (k) { data.set(k, extra[k]); });
      return fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    }

    function save() {
      saveTimer = null;
      var order = widgets().map(function (w) { return w.dataset.widget; });
      var hidden = widgets().filter(function (w) { return w.classList.contains('is-hidden-widget'); })
        .map(function (w) { return w.dataset.widget; });
      send({ layout: JSON.stringify({ order: order, hidden: hidden }) })
        .then(function () { flash('Gespeichert ✓'); })
        .catch(function () { flash('Konnte nicht gespeichert werden', true); });
    }

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
      if (!confirm('Standard-Anordnung wiederherstellen?')) return;
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
        var nowHidden = tw.classList.toggle('is-hidden-widget');
        toggle.setAttribute('aria-pressed', nowHidden ? 'false' : 'true');
        var use = toggle.querySelector('use');
        if (use) use.setAttribute('href', use.getAttribute('href').replace(/#ph-eye(-slash)?$/, nowHidden ? '#ph-eye-slash' : '#ph-eye'));
        changed();
      }
    });

    // Ziehen mit der Maus (nur im Bearbeiten-Modus, Kästen sind dann draggable)
    box.addEventListener('dragstart', function (e) {
      dragged = e.target.closest('.dash-widget');
      if (!dragged) return;
      dragged.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragged.dataset.widget); } catch (err) { /* IE */ }
    });
    box.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();
      var over = e.target.closest('.dash-widget');
      if (!over || over === dragged) return;
      var rect = over.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      box.insertBefore(dragged, after ? over.nextElementSibling : over);
    });
    box.addEventListener('dragend', function () {
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      dragged = null;
      changed();
    });
  });
})();
