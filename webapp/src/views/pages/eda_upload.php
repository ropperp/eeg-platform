<?php $pageTitle = 'EDA-Daten importieren'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem"><?= icon('folder-open') ?> EDA-Daten importieren</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= $error ?></div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Import gelöscht -- die zugehörigen Messwerte wurden entfernt.</div>
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
  <h3 style="margin-bottom:1rem"><?= icon('list') ?> Bisherige Importe (<?= count($imports ?? []) ?>)</h3>
  <?php if (empty($imports)): ?>
    <p style="font-size:.875rem;color:var(--gray-600)">Noch keine EDA-Daten importiert.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table style="font-size:.85rem;width:100%">
        <thead>
          <tr><th>Datei</th><th>Zeitraum</th><th>Datensätze</th><th>Status</th><th>Datenqualität</th><th>Importiert</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
          <?php foreach ($imports as $imp): ?>
            <?php
              $sb = ['ok' => 'green', 'warning' => 'yellow', 'error' => 'red'];
              $belastbar = (int)$imp['quality_l1'] + (int)$imp['quality_l2'];
              $l3 = (int)$imp['quality_l3'];
            ?>
            <tr>
              <td><code style="font-size:.78rem"><?= htmlspecialchars($imp['filename']) ?></code></td>
              <td><?= date('d.m.Y', strtotime($imp['period_from'])) ?> – <?= date('d.m.Y', strtotime($imp['period_to'])) ?></td>
              <td><?= number_format((int)$imp['records_imported'], 0, ',', '.') ?></td>
              <td><span class="badge badge-<?= $sb[$imp['status']] ?? 'gray' ?>"><?= htmlspecialchars($imp['status']) ?></span></td>
              <td>
                <?php if ($belastbar === 0 && $l3 === 0): ?>
                  <span style="color:var(--gray-600)">—</span>
                <?php else: ?>
                  <?php if ($belastbar > 0): ?><span class="badge badge-green" style="font-size:.72rem" title="L1/L2 -- echt gemessen bzw. belastbarer Ersatzwert"><?= $belastbar ?> belastbar</span><?php endif; ?>
                  <?php if ($l3 > 0): ?><span class="badge badge-red" style="font-size:.72rem" title="L3 -- nicht belastbarer Ersatzwert, wird nicht abgerechnet"><?= $l3 ?> L3</span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?= date('d.m.Y H:i', strtotime($imp['imported_at'])) ?>
                <?php if (!empty($imp['first_name'])): ?>
                  <br><span style="color:var(--gray-600);font-size:.78rem"><?= htmlspecialchars($imp['first_name'] . ' ' . $imp['last_name']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" action="/portal/eda/imports/<?= $imp['id'] ?>/delete" style="display:inline"
                      onsubmit="return confirmDangerDelete('EDA-Import „<?= htmlspecialchars(addslashes($imp['filename'])) ?>“ inkl. aller dabei importierten Messwerte')">
                  <button type="submit" class="btn btn-tint-red" style="padding:.35rem .6rem;font-size:.8rem" title="Import löschen"><?= icon('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:.8rem;color:var(--gray-600);margin-top:.75rem">
      Löschen entfernt den Import-Eintrag UND die dabei importierten Messwerte für diesen
      Zeitraum -- damit lässt sich dieselbe Datei danach erneut hochladen (der Import
      verweigert sonst mit „Duplikat"). Bereits berechnete Rechnungs-Entwürfe werden dadurch
      NICHT rückwirkend aktualisiert -- ggf. den Abrechnungslauf danach neu berechnen.
    </p>
  <?php endif; ?>
</div>


<div class="card" style="margin-top:1.5rem;border:1px solid #93c5fd">
  <h3 style="margin-bottom:1rem"><?= icon('chart-bar') ?> Viertelstundenwerte für das Mitglieder-Diagramm</h3>
  <p style="font-size:.875rem;color:var(--gray-600);margin-bottom:1rem">
    Zweiter, eigener Export-Typ aus dem EDA-Portal ("Energiedaten"-Sheet, echte
    Viertelstundenwerte statt Monatssummen) -- <strong>rein für das Verbrauchsdiagramm der
    Mitglieder</strong>, hat mit der Abrechnung nichts zu tun. Da EDA maximal einen Monat pro
    Export erlaubt, aber auch beliebig kürzere/überlappende Zeiträume: einfach alle paar Tage
    den aktuellen Ausschnitt hochladen, ein überschneidender Zeitraum wird automatisch
    überschrieben.
  </p>

  <?php if (!empty($intervalGap['letzter'])): ?>
    <div class="alert <?= ($intervalGap['fehlende_tage'] ?? 0) > 3 ? 'alert-warning' : 'alert-success' ?>" style="margin-bottom:1rem">
      <strong>Daten vorhanden bis <?= date('d.m.Y H:i', strtotime($intervalGap['letzter'])) ?>.</strong>
      <?php if (($intervalGap['fehlende_tage'] ?? 0) > 0): ?>
        Es fehlen die letzten <?= (int)$intervalGap['fehlende_tage'] ?> Tag<?= (int)$intervalGap['fehlende_tage'] === 1 ? '' : 'e' ?>
        bis heute (<?= date('d.m.Y') ?>) -- als Nächstes ab
        <strong><?= date('d.m.Y', strtotime($intervalGap['letzter'] . ' +1 day')) ?></strong> exportieren.
      <?php else: ?>
        Aktuell, keine Lücke bis heute.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-warning" style="margin-bottom:1rem">
      Noch keine Viertelstundenwerte importiert -- als Erstes ab dem Beitrittsdatum des ersten
      Mitglieds exportieren (siehe Mitgliederliste, nach Beitrittsdatum sortiert).
    </div>
  <?php endif; ?>

  <?php if (!empty($intervalError)): ?>
    <div class="alert alert-error" style="margin-bottom:1rem"><?= $intervalError ?></div>
  <?php endif; ?>
  <?php if (!empty($intervalResult)): ?>
    <div class="alert alert-<?= empty($intervalResult['warnings']) ? 'success' : 'warning' ?>" style="margin-bottom:1rem">
      <strong>Import abgeschlossen.</strong>
      <?= number_format($intervalResult['records'], 0, ',', '.') ?> Viertelstundenwerte importiert
      (<?= htmlspecialchars($intervalResult['period_from']) ?> – <?= htmlspecialchars($intervalResult['period_to']) ?>).
      <?php foreach ($intervalResult['warnings'] ?? [] as $w): ?>
        <br><span style="font-size:.85rem"><?= htmlspecialchars($w) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/portal/eda/upload-interval" enctype="multipart/form-data" style="text-align:center">
    <input type="file" name="xlsx_interval" accept=".xlsx" required style="margin-bottom:1rem;display:block;margin-left:auto;margin-right:auto">
    <button type="submit" class="btn btn-primary">Viertelstundenwerte importieren</button>
  </form>

  <?php if (!empty($intervalImports)): ?>
    <div style="overflow-x:auto;margin-top:1.5rem">
      <table style="font-size:.85rem;width:100%">
        <thead>
          <tr><th>Datei</th><th>Zeitraum</th><th>Datensätze</th><th>Importiert</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
          <?php foreach ($intervalImports as $imp): ?>
            <tr>
              <td><code style="font-size:.78rem"><?= htmlspecialchars($imp['filename']) ?></code></td>
              <td><?= date('d.m.Y H:i', strtotime($imp['period_from'])) ?> – <?= date('d.m.Y H:i', strtotime($imp['period_to'])) ?></td>
              <td><?= number_format((int)$imp['records_imported'], 0, ',', '.') ?></td>
              <td>
                <?= date('d.m.Y H:i', strtotime($imp['imported_at'])) ?>
                <?php if (!empty($imp['first_name'])): ?>
                  <br><span style="color:var(--gray-600);font-size:.78rem"><?= htmlspecialchars($imp['first_name'] . ' ' . $imp['last_name']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" action="/portal/eda/interval-imports/<?= $imp['id'] ?>/delete" style="display:inline"
                      onsubmit="return confirmDangerDelete('den Protokolleintrag „<?= htmlspecialchars(addslashes($imp['filename'])) ?>“ (Messwerte bleiben erhalten)')">
                  <button type="submit" class="btn btn-tint-red" style="padding:.35rem .6rem;font-size:.8rem" title="Nur Protokolleintrag löschen"><?= icon('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
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
