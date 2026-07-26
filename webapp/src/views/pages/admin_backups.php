<?php
$pageTitle = 'Backups';
// $status (array|null aus last_backup.json), $arten (gruppierte Dateien), $dirLesbar
$fmtBytes = function (int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 2, ',', '.') . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024)       return number_format($b / 1024, 0, ',', '.') . ' KB';
    return $b . ' B';
};
$fmtAlter = function (int $unix): string {
    if ($unix <= 0) return 'unbekannt';
    $s = time() - $unix;
    if ($s < 3600)  return 'vor ' . max(1, intdiv($s, 60)) . ' Min.';
    if ($s < 86400) return 'vor ' . intdiv($s, 3600) . ' Std.';
    return 'vor ' . intdiv($s, 86400) . ' Tag(en)';
};
$letzterLauf = (int)($status['unix'] ?? 0);
$alterStunden = $letzterLauf > 0 ? (time() - $letzterLauf) / 3600 : null;
// Backup läuft täglich 02:00 -> älter als 26 h bedeutet: mindestens ein Lauf ist ausgefallen.
$istAktuell = $alterStunden !== null && $alterStunden <= 26;
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:var(--gray-600);text-decoration:none">← Admin</a>
  <h2 style="margin:0">💾 Backups</h2>
</div>

<!-- Status der letzten Sicherung -->
<?php if (!$dirLesbar): ?>
  <div class="alert alert-error" style="margin-bottom:1.5rem">
    <strong>Backup-Verzeichnis nicht lesbar.</strong> Das Verzeichnis <code>backups/</code> ist nicht in den
    webapp-Container eingebunden. Nach dem nächsten Deploy mit aktueller <code>docker-compose.yml</code>
    (<code>docker compose up -d</code>) erscheint hier die Übersicht.
  </div>
<?php elseif ($letzterLauf === 0): ?>
  <div class="alert alert-warning" style="margin-bottom:1.5rem">
    <strong>Noch kein Backup-Lauf erfasst.</strong> Entweder lief die nächtliche Sicherung noch nie,
    oder sie ist älter als die Einführung dieser Übersicht. Prüfen mit
    <code>bash scripts/backup.sh</code> auf dem Server – danach steht hier der Zeitpunkt.
  </div>
<?php elseif ($istAktuell): ?>
  <div class="alert alert-success" style="margin-bottom:1.5rem">
    <strong>✅ Sicherung ist aktuell.</strong> Letzter erfolgreicher Lauf:
    <strong><?= htmlspecialchars((string)($status['zeitpunkt'] ?? '')) ?></strong>
    (<?= htmlspecialchars($fmtAlter($letzterLauf)) ?>) auf <code><?= htmlspecialchars((string)($status['host'] ?? '')) ?></code>.
  </div>
<?php else: ?>
  <div class="alert alert-error" style="margin-bottom:1.5rem">
    <strong>⚠️ Sicherung ist überfällig!</strong> Der letzte erfolgreiche Lauf war
    <strong><?= htmlspecialchars($fmtAlter($letzterLauf)) ?></strong>
    (<?= htmlspecialchars((string)($status['zeitpunkt'] ?? '')) ?>), erwartet wird täglich um 02:00 Uhr.
    Bitte prüfen: läuft der Cron-Job (<code>crontab -l</code>), ist genug Speicherplatz frei
    (<code>df -h</code>), und läuft <code>bash scripts/backup.sh</code> manuell durch?
  </div>
<?php endif; ?>

<!-- Erklärung des zweistufigen Konzepts -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Wie gesichert wird</h3>
  <p style="color:var(--gray-600);font-size:.88rem;margin-bottom:.75rem">
    Mitglieder-/Abrechnungsdaten und Messwerte liegen technisch in <em>einer</em> PostgreSQL-Datenbank
    (TimescaleDB ist nur eine Erweiterung darin). Getrennt werden deshalb die <strong>Sicherungen</strong> –
    so lassen sich die kleinen, kritischen Stammdaten unabhängig von den großen Messwerten wiederherstellen:
  </p>
  <ul style="font-size:.88rem;color:var(--gray-700);margin:0 0 .5rem 1.1rem;line-height:1.7">
    <li><strong>Stammdaten-Dump</strong> (<code>eeg_stamm_*.dump</code>) – Mitglieder, Rechnungen, Verträge,
      Zählpunkte, Konfiguration. Klein und schnell. Beim Wiederherstellen bleiben die Messwerte unangetastet.</li>
    <li><strong>Datenbank vollständig</strong> (<code>eeg_*.dump</code>) – zusätzlich alle Messwerte
      (Viertelstunden-/Live-Werte). Das ist die Rundum-Absicherung.</li>
    <li><strong>Komplettsicherung</strong> (<code>eeg_full_*.tar.gz</code>) – Datenbank <em>und</em> Dateien
      (Beitrittserklärungen/SEPA-Mandate, Vertrags- und Rechnungs-PDFs, Uploads) in einer Datei.</li>
  </ul>
  <p style="font-size:.85rem;color:var(--gray-600)">
    Schlägt eine Sicherung fehl, geht automatisch eine Alarm-Mail an die in den
    <a href="/admin/mail-settings">E-Mail-Einstellungen</a> hinterlegten Adressen.
  </p>
</div>

<!-- Vorhandene Sicherungen -->
<?php foreach ($arten as $art): ?>
<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.75rem"><?= htmlspecialchars($art['label']) ?></h3>
  <?php if (empty($art['dateien'])): ?>
    <p style="color:var(--gray-600);font-size:.88rem">Noch keine Sicherung dieser Art vorhanden.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr><th>Datei</th><th>Erstellt</th><th style="text-align:right">Größe</th><th>Wiederherstellen mit</th></tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($art['dateien'], 0, 10) as $d): ?>
          <tr>
            <td style="font-size:.82rem"><code><?= htmlspecialchars($d['name']) ?></code></td>
            <td style="font-size:.85rem;white-space:nowrap">
              <?= date('d.m.Y H:i', $d['zeit']) ?><br>
              <span style="color:var(--gray-600);font-size:.78rem"><?= htmlspecialchars($fmtAlter($d['zeit'])) ?></span>
            </td>
            <td style="text-align:right;white-space:nowrap"><?= htmlspecialchars($fmtBytes($d['bytes'])) ?></td>
            <td>
              <code style="font-size:.75rem;user-select:all;display:block;word-break:break-all">bash scripts/restore.sh backups/<?= htmlspecialchars($d['name']) ?></code>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($art['dateien']) > 10): ?>
      <p style="font-size:.8rem;color:var(--gray-600);margin-top:.5rem">… und <?= count($art['dateien']) - 10 ?> ältere.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <h3 style="margin-bottom:.5rem">Wiederherstellen</h3>
  <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:.75rem">
    Zum Wiederherstellen den Befehl aus der Tabelle oben auf dem Server im Verzeichnis
    <code>/opt/eeg-platform</code> ausführen. Das Skript fragt vorher zur Sicherheit nach einer
    ausdrücklichen Bestätigung.
  </p>
  <p style="font-size:.85rem;color:var(--gray-600)">
    <strong>Warum kein Knopf, der das direkt hier ausführt?</strong> Eine Wiederherstellung überschreibt
    Datenbank bzw. Dateien. Damit die Webanwendung das auslösen könnte, bräuchte sie Zugriff auf die
    Docker-Steuerung des Servers – eine einzige Sicherheitslücke in der Weboberfläche würde dann
    ausreichen, um den kompletten Server zu übernehmen oder alle Daten zu löschen. Deshalb zeigt diese
    Seite den Stand und den fertigen Befehl, ausgeführt wird bewusst auf dem Server.
  </p>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
