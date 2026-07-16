<?php $pageTitle = 'Dateien'; ob_start(); ?>

<h2 style="margin-bottom:.5rem">📁 Dateien</h2>
<p style="color:#6b7280;font-size:.875rem;margin-bottom:1.5rem">
  LaTeX-Vorlagen für Verträge, Rechnungen und die Beitrittserklärung. Herunterladen, bearbeiten
  und per Drag &amp; Drop wieder hochladen -- ohne Kommandozeile oder GitHub. Wirkt sofort auf
  alle künftig erzeugten PDFs.
</p>

<?php if (!empty($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php foreach ($templates as $t): ?>
<div class="card" style="margin-bottom:1rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <div>
      <strong><?= htmlspecialchars($t['label']) ?></strong>
      <code style="font-size:.78rem;color:#6b7280;margin-left:.5rem"><?= htmlspecialchars($t['filename']) ?></code>
      <?php if (!$t['exists']): ?>
        <span class="badge badge-gray" style="margin-left:.5rem">Keine Datei vorhanden</span>
      <?php elseif ($t['is_custom']): ?>
        <span class="badge badge-yellow" style="margin-left:.5rem">Angepasst</span>
      <?php else: ?>
        <span class="badge badge-gray" style="margin-left:.5rem">Standard</span>
      <?php endif; ?>
    </div>
    <?php if ($t['exists']): ?>
      <span style="font-size:.78rem;color:#9ca3af">
        <?= number_format($t['size'] / 1024, 1, ',', '.') ?> KB · zuletzt geändert <?= date('d.m.Y H:i', $t['mtime']) ?>
      </span>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <?php if ($t['exists']): ?>
      <a href="/admin/templates/<?= htmlspecialchars($t['filename']) ?>/download" class="btn" style="background:#f3f4f6;color:#374151;font-size:.85rem">
        ⬇️ Herunterladen
      </a>
    <?php endif; ?>

    <form method="post" action="/admin/templates/<?= htmlspecialchars($t['filename']) ?>/upload"
          enctype="multipart/form-data" class="template-upload-form" style="flex:1;min-width:260px">
      <label class="template-dropzone"
             data-filename="<?= htmlspecialchars($t['filename']) ?>"
             style="display:flex;align-items:center;justify-content:center;gap:.5rem;border:2px dashed #d1d5db;border-radius:8px;padding:.6rem 1rem;cursor:pointer;font-size:.82rem;color:#6b7280;transition:border-color .15s,background .15s">
        <span class="dz-text">📄 Neue Datei hierher ziehen oder klicken</span>
        <input type="file" name="file" accept=".tex" required style="display:none">
      </label>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('.template-dropzone').forEach(function (zone) {
  const input = zone.querySelector('input[type=file]');
  const text = zone.querySelector('.dz-text');
  const form = zone.closest('form');

  function highlight(on) {
    zone.style.borderColor = on ? '#16a34a' : '#d1d5db';
    zone.style.background = on ? '#f0fdf4' : '';
  }

  zone.addEventListener('dragover', function (e) { e.preventDefault(); highlight(true); });
  zone.addEventListener('dragleave', function () { highlight(false); });
  zone.addEventListener('drop', function (e) {
    e.preventDefault();
    highlight(false);
    if (e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      submitAfterPick();
    }
  });
  input.addEventListener('change', submitAfterPick);

  function submitAfterPick() {
    if (!input.files.length) return;
    text.textContent = '⏳ ' + input.files[0].name + ' wird hochgeladen …';
    form.submit();
  }
});
</script>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
