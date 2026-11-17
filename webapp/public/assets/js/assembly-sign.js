/**
 * Unterschriftenfeld für Versammlungsprotokolle (vor Ort und per Link). Ohne Inline-Handler (CSP):
 *   <form data-signature-form> mit <canvas data-signature-pad>, <input type="hidden" name="signature_image">
 *   und optional <button type="button" data-signature-clear>.
 * Braucht signature-pad-trim.js (trimSignatureCanvas), das die Seite mitlädt.
 */
(function () {
  function setup(form) {
    var canvas = form.querySelector('canvas[data-signature-pad]');
    var input = form.querySelector('input[name="signature_image"]');
    if (!canvas || !input) return;
    var ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#00008B';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    var drawing = false;
    var hasSignature = false;

    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      var point = e.touches ? e.touches[0] : e;
      return {
        x: (point.clientX - rect.left) * (canvas.width / rect.width),
        y: (point.clientY - rect.top) * (canvas.height / rect.height)
      };
    }
    function start(e) { e.preventDefault(); drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      var p = pos(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      hasSignature = true;
    }
    function stop() { drawing = false; }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', stop);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', stop);

    var clear = form.querySelector('[data-signature-clear]');
    if (clear) {
      clear.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasSignature = false;
      });
    }
    form.addEventListener('submit', function (e) {
      if (!hasSignature) {
        e.preventDefault();
        alert('Bitte im Feld unterschreiben, bevor Sie absenden.');
        return;
      }
      input.value = trimSignatureCanvas(canvas);
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-signature-form]').forEach(setup);
  });
})();
