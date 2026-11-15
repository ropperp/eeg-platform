/* Deklarative Aktionen statt Inline-Handlern (17.11.2026).
 *
 * Die Content-Security-Policy erlaubt keine Inline-Skripte ohne Nonce und keine on*="…"-Attribute mehr
 * (script-src-attr 'none'). Ein eingeschleustes <img onerror=…> oder <a href="javascript:…"> läuft damit
 * nicht, selbst wenn irgendwo Text unmaskiert ausgegeben würde. Dieses Skript übernimmt, was früher
 * Inline-Handler taten -- ohne eval, nur über feste, hier aufgeführte Aktionen:
 *
 *   data-confirm="Text"                  Abfrage (OK/Abbrechen) vor Absenden eines <form> bzw. Klick auf Knopf/Link
 *   data-danger-confirm="Beschreibung"   Doppelte Löschbestätigung (confirmDangerDelete(), "LOESCHEN" tippen)
 *   data-on<click|change|input|submit>="funktion" data-arg-0=… data-arg-1=…
 *                                        ruft die globale Funktion auf. Argumente: Text; "@this" = das Element,
 *                                        "@event", "@value" (this.value), "@true"/"@false". data-return="1":
 *                                        liefert die Funktion false, wird die Standardaktion abgebrochen.
 *   data-dialog-open="id" / data-dialog-close="id"   <dialog> öffnen/schließen
 *   data-click-target="id"               Klick an ein anderes Element weitergeben (Datei-Auswahl)
 *   data-print / data-back               Drucken / eine Seite zurück
 *   data-href="url"                      ganze Zeile klickbar (Klick auf Links/Knöpfe darin bleibt unberührt)
 *   data-autosubmit                      Formular bei Änderung absenden
 *   data-toggle-target="id" data-toggle-when="wert"   Element einblenden, wenn Auswahl == wert
 *   data-file-label="id"                 gewählten Dateinamen in Element schreiben
 *
 * Die Zuhörer hängen in der Capture-Phase am document: so greift event.stopPropagation() in einer Funktion
 * (z. B. Profilmenü) wie früher, bevor ein "Klick daneben schließt"-Zuhörer läuft.
 */
(function () {
  'use strict';

  function arg(el, i, ev) {
    var v = el.getAttribute('data-arg-' + i);
    switch (v) {
      case '@this': return el;
      case '@event': return ev;
      case '@value': return el.value;
      case '@true': return true;
      case '@false': return false;
      default: return v;
    }
  }

  function callNamed(el, ev, name) {
    var fn = window[name];
    if (typeof fn !== 'function') { console.error('ui-actions: Funktion fehlt:', name); return undefined; }
    var args = [];
    for (var i = 0; el.hasAttribute('data-arg-' + i); i++) args.push(arg(el, i, ev));
    return fn.apply(el, args);
  }

  function askConfirm(el) {
    if (el.hasAttribute('data-confirm') && !window.confirm(el.getAttribute('data-confirm'))) return false;
    if (el.hasAttribute('data-danger-confirm') && !window.confirmDangerDelete(el.getAttribute('data-danger-confirm'))) return false;
    return true;
  }

  // Bestätigungen: Formulare beim Absenden, alle anderen Elemente beim Klick
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.matches && f.matches('[data-confirm],[data-danger-confirm]') && !askConfirm(f)) e.preventDefault();
  }, true);

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-confirm],[data-danger-confirm]') : null;
    if (t && t.tagName !== 'FORM' && !askConfirm(t)) { e.preventDefault(); e.stopPropagation(); return; }

    var el;
    if ((el = e.target.closest('[data-dialog-open]'))) { e.preventDefault(); var d = document.getElementById(el.getAttribute('data-dialog-open')); if (d) d.showModal(); return; }
    if ((el = e.target.closest('[data-dialog-close]'))) { e.preventDefault(); var c = document.getElementById(el.getAttribute('data-dialog-close')); if (c) c.close(); return; }
    if ((el = e.target.closest('[data-click-target]'))) { var x = document.getElementById(el.getAttribute('data-click-target')); if (x) x.click(); return; }
    if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); return; }
    if (e.target.closest('[data-back]')) { e.preventDefault(); history.back(); return; }
    if ((el = e.target.closest('[data-href]'))) {
      var inner = e.target.closest('a,button,input,select,textarea,label');
      if (!inner || !el.contains(inner) || inner === el) { window.location.href = el.getAttribute('data-href'); return; }
    }
    if ((el = e.target.closest('[data-onclick]'))) {
      var r = callNamed(el, e, el.getAttribute('data-onclick'));
      if (r === false && el.hasAttribute('data-return')) e.preventDefault();
    }
  }, true);

  ['change', 'input', 'submit'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var el = e.target.closest ? e.target.closest('[data-on' + type + ']') : null;
      if (el) {
        var r = callNamed(el, e, el.getAttribute('data-on' + type));
        if (r === false && el.hasAttribute('data-return')) e.preventDefault();
      }
    }, true);
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute) return;
    if (el.hasAttribute('data-autosubmit') && el.form) el.form.submit();
    if (el.hasAttribute('data-toggle-target')) {
      var t = document.getElementById(el.getAttribute('data-toggle-target'));
      if (t) t.style.display = el.value === el.getAttribute('data-toggle-when') ? 'block' : 'none';
    }
    if (el.hasAttribute('data-file-label')) {
      var l = document.getElementById(el.getAttribute('data-file-label'));
      if (l) l.textContent = (el.files && el.files[0] && el.files[0].name) || '';
    }
  }, true);
})();
