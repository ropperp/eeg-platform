<?php $pageTitle = 'EDA-Daten importieren'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem"><?= icon('folder-open') ?> EDA-Daten importieren</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= $error ?></div>
<?php endif; ?>

<?php if (!empty($result)): ?>
  <div class="alert alert-<?= empty($result['warnings']) ? 'success' : 'warning' ?>" style="margin-bottom:1rem">
    <strong>Import abgeschlossen.</strong>
    <?= number_format($result['records'], 0, ',', '.') ?> Datensätze importiert
    (<?= htmlspecialchars($result['period_from']) ?> – <?= htmlspecialchars($result['period_to']) ?>).
  </div>
  <?php if (!empty($result['neu_angelegt'])): ?>
    <div class="card" style="margin-bottom:1.5rem;border:1px solid #93c5fd">
      <h3 style="margin-bottom:.5rem"><?= icon('plus') ?> Neu angelegt (<?= count($result['neu_angelegt']) ?>)</h3>
      <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:.75rem">
        Diese Zählpunkte waren im Export enthalten, aber bei uns noch nicht registriert -- sie
        sind jetzt angelegt, aber noch keinem Mitglied zugeordnet und noch nicht aktiv.
      </p>
      <ul style="font-size:.875rem;padding-left:1.25rem">
        <?php foreach ($result['neu_angelegt'] as $n): ?>
          <li><code style="font-size:.78rem"><?= htmlspecialchars($n['zaehlpunkt_nr']) ?></code>
            (Typ-Vermutung: <?= $n['type_guess'] === 'producer' ? 'Einspeisung' : 'Bezug' ?>)</li>
        <?php endforeach; ?>
      </ul>
      <a href="/portal/metering-points/unassigned" class="btn btn-primary" style="margin-top:.75rem">Jetzt zuordnen</a>
    </div>
  <?php endif; ?>
  <?php if (!empty($result['warnings'])): ?>
    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:.75rem"><?= icon('warning-circle') ?> Warnungen</h3>
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
  <p style="font-size:.875rem;color:var(--gray-600);margin-bottom:1.5rem">
    Laden Sie den <strong>Energiedatenreport (Monatsexport)</strong> aus dem
    <strong>EDA-Anwenderportal</strong> hoch (Sheets „Gesamtübersicht" und „Detailübersicht").
    Eine Datei deckt immer genau <strong>einen Kalendermonat</strong> ab -- für einen
    Quartals-Abrechnungslauf bitte alle drei Monatsdateien des Quartals nacheinander hier
    hochladen. Die Beträge werden beim Berechnen der Rechnungen automatisch über den ganzen
    Quartalszeitraum summiert.
  </p>

  <form method="post" action="/portal/eda/upload" enctype="multipart/form-data">
    <div style="text-align:center;margin-bottom:1.5rem">
      <div id="upload-zone" onclick="document.getElementById('xlsx-input').click()"
           style="border:2px dashed #d1d5db;border-radius:8px;padding:2.5rem 2rem;cursor:pointer;
                  display:inline-block;min-width:360px;transition:border-color .2s"
           onmouseover="this.style.borderColor='#16a34a'" onmouseout="this.style.borderColor='#d1d5db'">
        <div style="font-size:2.5rem;margin-bottom:.75rem"><?= icon('file-text') ?></div>
        <div style="font-weight:600;margin-bottom:.25rem">XLSX hier ablegen oder klicken</div>
        <div style="font-size:.8rem;color:var(--gray-600)">Maximale Dateigröße: 20 MB</div>
      </div>
      <input type="file" id="xlsx-input" name="xlsx" accept=".xlsx" style="display:none"
             onchange="document.getElementById('file-label').textContent = this.files[0]?.name ?? ''">
      <p id="file-label" style="margin-top:.75rem;font-size:.875rem;color:#16a34a;font-weight:500"></p>
    </div>
    <div style="text-align:center">
      <button type="submit" class="btn btn-primary btn-lg">Import starten</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('info') ?> Wichtiger Hinweis</h3>
  <p style="font-size:.875rem;color:var(--gray-600)">
    Die EDA-Daten sind die <strong>einzige rechtlich bindende Abrechnungsgrundlage</strong>.
    Für die Rechnungsberechnung zählen ausschließlich Werte der Qualität <strong>L1/L2</strong>
    (echt gemessen bzw. belastbarer Ersatzwert) -- Zeiträume mit noch vorhandenen
    <strong>L3</strong>-Werten (nicht belastbarer Ersatzwert) sperren die endgültige Freigabe
    des Abrechnungslaufs automatisch, bis ein neuerer Monatsreport keine L3-Werte mehr meldet.
    Details dazu: <code>docs/EDA_DATENQUALITAET.md</code>.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
