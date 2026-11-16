/**
 * Upload-Seite der EDA-Daten: fragt den Status eines laufenden Importjobs ab (Container eda-worker)
 * und lädt die Seite neu, sobald er fertig ist -- dann zeigt sie Ergebnis oder Fehlermeldung.
 * Element: <div data-eda-job="<job-id>" data-eda-status="queued|running">. Neu geladen wird nur, wenn
 * sich der Status ändert (wartet -> läuft -> fertig). Erst alle 2 s, nach einer Minute alle 5 s.
 */
(function () {
  var box = document.querySelector('[data-eda-job]');
  if (!box) return;
  var id = box.getAttribute('data-eda-job');
  var shown = box.getAttribute('data-eda-status');
  var started = Date.now();
  function poll() {
    fetch('/portal/eda/jobs/' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 404) return { finished: true };   // Job weg -> Seite zeigt die Meldung
        if (!r.ok) throw new Error('HTTP ' + r.status);     // vorübergehender Fehler -> später erneut
        return r.json();
      })
      .then(function (d) {
        if (d.finished) { location.reload(); return; }
        if (d.status && d.status !== shown) { location.reload(); return; }
        setTimeout(poll, Date.now() - started > 60000 ? 5000 : 2000);
      })
      .catch(function () { setTimeout(poll, 5000); });
  }
  setTimeout(poll, 1500);
})();
