<?php $pageTitle = 'EDA-Daten importieren'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">📂 EDA-Daten importieren</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($result)): ?>
  <div class="alert alert-<?= empty($result['warnings']) ? 'success' : 'warning' ?>">
    <strong>Import abgeschlossen.</strong>
    <?= number_format($result['records'], 0, ',', '.') ?> Datensätze importiert
    (<?= htmlspecialchars($result['period_from']) ?> – <?= htmlspecialchars($result['period_to']) ?>).
  </div>
  <?php if (!empty($result['warnings'])): ?>
    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:.75rem">⚠️ Warnungen</h3>
      <ul style="font-size:.875rem;color:#92400e;padding-left:1.25rem">
        <?php foreach ($result['warnings'] as $w): ?>
          <li><?= htmlspecialchars($w) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <h3 style="margin-bottom:1rem">XLSX hochladen</h3>
  <p style="font-size:.875rem;color:#6b7280;margin-bottom:1.5rem">
    Laden Sie die XLSX-Datei aus dem <strong>EDA-Anwenderportal</strong> hoch.
    Dateiname-Format: <code>RC108175_2026-05-11T00_00-2026-06-11T23_45.xlsx</code>
  </p>

  <form method="post" action="/portal/eda/upload" enctype="multipart/form-data">
    <label class="upload-zone" for="xlsx-input">
      <div style="font-size:2.5rem;margin-bottom:.75rem">📄</div>
      <div style="font-weight:600;margin-bottom:.25rem">XLSX hier ablegen oder klicken</div>
      <div style="font-size:.8rem;color:#9ca3af">Maximale Dateigröße: 20 MB</div>
      <input type="file" id="xlsx-input" name="xlsx" accept=".xlsx" style="display:none"
             onchange="document.getElementById('file-name').textContent = this.files[0]?.name ?? ''">
    </label>
    <p id="file-name" style="margin-top:.5rem;font-size:.875rem;color:#16a34a;text-align:center"></p>
    <div style="text-align:center;margin-top:1rem">
      <button type="submit" class="btn btn-primary btn-lg">Import starten</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem">ℹ️ Wichtiger Hinweis</h3>
  <p style="font-size:.875rem;color:#6b7280">
    Die EDA-Daten sind die <strong>einzige rechtlich bindende Abrechnungsgrundlage</strong>.
    Nach erfolgreichem Import prüft das System automatisch ob alle Zählpunkte vollständig (COMPLETE)
    und ob das 60-Tage-Korrekturfenster abgelaufen ist.
    Erst dann wird der Freigabe-Button in der Abrechnung aktiviert.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
