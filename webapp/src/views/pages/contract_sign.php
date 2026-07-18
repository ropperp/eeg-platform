<?php
$pageTitle = contractTypeLabel($type) . ' unterschreiben';
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/my/documents" style="color:var(--gray-600);text-decoration:none">← Zurück zu meinen Dokumenten</a>
  <h2 style="margin:0">✍️ <?= htmlspecialchars(contractTypeLabel($type)) ?> unterschreiben</h2>
</div>

<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem">1. Vereinbarung prüfen</h3>
  <p style="font-size:.875rem;color:var(--gray-600);margin-bottom:.75rem">
    Bitte lesen Sie die Vereinbarung sorgfältig durch, bevor Sie unterschreiben.
  </p>
  <iframe src="/portal/my/contract/<?= htmlspecialchars($type) ?>" style="width:100%;height:480px;border:1px solid var(--gray-200);border-radius:8px"></iframe>
  <p style="margin-top:.5rem">
    <a href="/portal/my/contract/<?= htmlspecialchars($type) ?>" target="_blank" style="font-size:.85rem">📄 In neuem Tab öffnen</a>
  </p>
</div>

<div class="card" style="max-width:640px">
  <h3 style="margin-bottom:.75rem">2. Digital unterschreiben</h3>
  <form method="post" action="/portal/my/contract/<?= htmlspecialchars($type) ?>/sign" id="sign-form">
    <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.85rem;margin-bottom:1rem">
      <input type="checkbox" name="zustimmung" value="1" required style="margin-top:.2rem">
      <span>Ich habe die <?= htmlspecialchars(contractTypeLabel($type)) ?> oben gelesen und stimme ihr hiermit rechtsverbindlich zu.</span>
    </label>

    <label style="font-size:.85rem;display:block;margin-bottom:.4rem">Unterschrift</label>
    <canvas id="sig-pad" width="600" height="180" style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
    <div style="margin:.5rem 0 1rem">
      <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem" onclick="clearSignature()">Löschen</button>
    </div>
    <input type="hidden" name="signature_image" id="signature_image">

    <button type="submit" class="btn btn-primary">✅ Jetzt verbindlich unterschreiben und freigeben</button>
  </form>
</div>

<script>
(function () {
  const canvas = document.getElementById('sig-pad');
  const ctx = canvas.getContext('2d');
  ctx.strokeStyle = '#00008B';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  let drawing = false;
  let hasSignature = false;

  function pos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const point = e.touches ? e.touches[0] : e;
    return { x: (point.clientX - rect.left) * scaleX, y: (point.clientY - rect.top) * scaleY };
  }
  function start(e) {
    e.preventDefault();
    drawing = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }
  function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = pos(e);
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

  window.clearSignature = function () {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSignature = false;
  };

  document.getElementById('sign-form').addEventListener('submit', function (e) {
    if (!hasSignature) {
      e.preventDefault();
      alert('Bitte unterschreiben Sie im Feld, bevor Sie absenden.');
      return;
    }
    document.getElementById('signature_image').value = canvas.toDataURL('image/png');
  });
})();
</script>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
