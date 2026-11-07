// Reiter (Tabs) für Einstellungsseiten, siehe views/partials/tabs_nav.php.
// - Unterstreichung gleitet zum aktiven Reiter, der Inhalt blendet je nach Richtung von links
//   bzw. rechts ein (bei "Bewegung reduzieren" im Betriebssystem ohne Animation, siehe app.css).
// - Der offene Reiter bleibt erhalten: Adresse mit #reiter (Link teilbar) und
//   sessionStorage -- so landet man nach "Speichern" (Formular -> Redirect) wieder im selben Reiter.
// - Tastatur wie bei nativen Reitern: Pfeil links/rechts, Pos1, Ende.
(function () {
  document.querySelectorAll('[data-tabs]').forEach(function (list) {
    var storageKey = 'tabs:' + list.getAttribute('data-tabs');
    var buttons = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
    var indicator = list.querySelector('.tab-indicator');
    var current = -1;

    function panelOf(btn) { return document.getElementById(btn.getAttribute('aria-controls')); }
    function indexOfId(id) {
      for (var i = 0; i < buttons.length; i++) { if (buttons[i].getAttribute('data-tab') === id) return i; }
      return -1;
    }
    function moveIndicator() {
      if (!indicator || current < 0) return;
      var btn = buttons[current];
      indicator.style.width = btn.offsetWidth + 'px';
      indicator.style.transform = 'translateX(' + btn.offsetLeft + 'px)';
    }

    function activate(i, animate) {
      if (i === current || i < 0 || i >= buttons.length) return;
      var fromLeft = i < current;
      buttons.forEach(function (b, j) {
        var on = j === i;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
        var panel = panelOf(b);
        if (!panel) return;
        panel.hidden = !on;
        panel.classList.remove('tab-enter', 'tab-enter-left');
        if (on && animate) {
          void panel.offsetWidth; // Animation neu starten
          panel.classList.add('tab-enter');
          if (fromLeft) panel.classList.add('tab-enter-left');
        }
      });
      current = i;
      moveIndicator();
      var id = buttons[i].getAttribute('data-tab');
      try { sessionStorage.setItem(storageKey, id); } catch (e) {}
      if (animate) {
        history.replaceState(null, '', location.pathname + location.search + '#' + id);
        // Auf schmalen Bildschirmen scrollt die Leiste waagrecht -- aktiven Reiter einblenden.
        list.scrollTo({ left: Math.max(0, buttons[i].offsetLeft - 24), behavior: 'smooth' });
      }
    }

    var start = indexOfId(location.hash.slice(1));
    if (start < 0) {
      try { start = indexOfId(sessionStorage.getItem(storageKey) || ''); } catch (e) {}
    }
    activate(start < 0 ? 0 : start, false);
    // Übergänge erst nach dem ersten Zeichnen einschalten, sonst gleitet die Unterstreichung
    // beim Laden sichtbar von links herein.
    requestAnimationFrame(function () { requestAnimationFrame(function () { list.classList.add('tabs-ready'); }); });

    buttons.forEach(function (b, i) {
      b.addEventListener('click', function () { activate(i, true); });
      b.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowRight') next = (i + 1) % buttons.length;
        else if (e.key === 'ArrowLeft') next = (i - 1 + buttons.length) % buttons.length;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = buttons.length - 1;
        if (next === null) return;
        e.preventDefault();
        activate(next, true);
        buttons[next].focus();
      });
    });

    window.addEventListener('hashchange', function () {
      var i = indexOfId(location.hash.slice(1));
      if (i >= 0) activate(i, true);
    });
    window.addEventListener('resize', moveIndicator);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(moveIndicator);
  });
})();
