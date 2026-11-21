// Statuten-Generator (Admin): Einladungslink in die Zwischenablage kopieren.
// Eingebunden per <script src> (CSP: keine Inline-Handler), siehe pages/admin_statute_detail.php.
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-copy-target]') : null;
    if (!btn) return;
    e.preventDefault();
    var input = document.getElementById(btn.getAttribute('data-copy-target'));
    if (!input) return;
    var done = function () { btn.textContent = 'Kopiert ✓'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
    } else {
      input.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* markiert, manuell kopieren */ }
    }
  });
})();
