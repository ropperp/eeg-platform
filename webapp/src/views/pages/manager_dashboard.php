<?php
$pageTitle = 'Verwaltungs-Übersicht';
$communityId = Auth::activeCommunityId();
DB::setCommunity($communityId);

$community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
$memberCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM members WHERE community_id = ? AND status = ?', [$communityId, 'active'])['cnt'];
$pendingCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM members WHERE community_id = ? AND status = ?', [$communityId, 'pending'])['cnt'];
$mpCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM metering_points WHERE community_id = ? AND active = true', [$communityId])['cnt'];

$lastImport = DB::fetchOne('SELECT * FROM eda_imports WHERE community_id = ? ORDER BY imported_at DESC LIMIT 1', [$communityId]);
$openBilling = DB::fetchOne("SELECT * FROM billing_runs WHERE community_id = ? AND status IN ('pending','ready') ORDER BY quartal DESC LIMIT 1", [$communityId]);

// ─── Status-Kachelzeile: Betriebsreife auf einen Blick (letzter EDA-Import, letztes
// Backup, ESB online/offline, offene Rechnungen) -- siehe diplomarbeit-berater-Vorschlag. ───
$esbStats = DB::fetchOne(
    "SELECT COUNT(*) FILTER (WHERE esb_last_seen_at IS NOT NULL) AS bekannt,
            COUNT(*) FILTER (WHERE esb_online) AS online
     FROM metering_points WHERE community_id = ? AND active = true",
    [$communityId]
);
$openInvoices = DB::fetchOne(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(saldo_eur) FILTER (WHERE saldo_eur > 0), 0) AS summe
     FROM invoices
     WHERE community_id = ? AND sent_at IS NOT NULL
       AND COALESCE(payment_status, 'offen') NOT IN ('eingezogen', 'ueberwiesen')",
    [$communityId]
);
// Backup-Status ist plattformweit (kein Community-Bezug), aber für den Obmann relevant genug,
// um hier mit angezeigt zu werden -- gleiche Datei wie /admin/backups (nur lesend gemountet).
$backupStatus = null;
$backupFile = '/var/www/html/backups/last_backup.json';
if (is_readable($backupFile)) {
    $backupStatus = json_decode((string)file_get_contents($backupFile), true) ?: null;
}
$backupAgeHours = $backupStatus && !empty($backupStatus['unix'])
    ? (time() - (int)$backupStatus['unix']) / 3600 : null;

// Community-Gesamtleistung live
$live = DB::fetchOne(
    "SELECT COALESCE(SUM(power_einspeisung_w),0) AS einsp_w, COALESCE(SUM(power_bezug_w),0) AS bezug_w,
            COUNT(DISTINCT metering_point_id) AS active_meters
     FROM esp_measurements WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes'",
    [$communityId]
);

ob_start();
?>

<h2 style="margin-bottom:1.5rem"><?= htmlspecialchars($community['name']) ?></h2>

<!-- KPI-Kacheln -->
<div class="grid-3" style="margin-bottom:2rem">
  <div class="card stat-card">
    <div class="stat-value"><?= $memberCount ?></div>
    <div class="stat-label">Aktive Mitglieder</div>
  </div>
  <div class="card stat-card">
    <div class="stat-value"><?= $mpCount ?></div>
    <div class="stat-label">Registrierte Zählpunkte</div>
  </div>
  <div class="card stat-card">
    <div class="stat-value" style="<?= $pendingCount > 0 ? 'color:#ca8a04' : '' ?>">
      <?= $pendingCount ?>
    </div>
    <div class="stat-label">Ausstehende Beitritte</div>
  </div>
</div>

<!-- Status-Kachelzeile: Betriebsreife auf einen Blick -->
<div class="grid-4" style="margin-bottom:2rem">
  <div class="card" style="padding:1rem">
    <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem"><?= icon('folder-open') ?> Letzter EDA-Import</div>
    <?php if ($lastImport): ?>
      <div style="font-weight:700"><?= date('d.m.Y', strtotime($lastImport['imported_at'])) ?></div>
      <span class="badge badge-<?= $lastImport['status'] === 'ok' ? 'green' : 'yellow' ?>" style="margin-top:.25rem;display:inline-block"><?= $lastImport['status'] ?></span>
    <?php else: ?>
      <div style="font-weight:700;color:var(--gray-600)">—</div>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:1rem">
    <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem"><?= icon('floppy-disk') ?> Letztes Backup</div>
    <?php if ($backupAgeHours !== null): ?>
      <div style="font-weight:700"><?= date('d.m.Y H:i', (int)$backupStatus['unix']) ?></div>
      <span class="badge badge-<?= $backupAgeHours <= 26 ? 'green' : 'red' ?>" style="margin-top:.25rem;display:inline-block">
        <?= $backupAgeHours <= 26 ? 'aktuell' : 'überfällig' ?>
      </span>
    <?php else: ?>
      <div style="font-weight:700;color:var(--gray-600)">Kein Status verfügbar</div>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:1rem">
    <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem"><?= icon('plug') ?> ESB online</div>
    <?php if (($esbStats['bekannt'] ?? 0) > 0): ?>
      <div style="font-weight:700"><?= $esbStats['online'] ?> von <?= $esbStats['bekannt'] ?></div>
      <span class="badge badge-<?= $esbStats['online'] == $esbStats['bekannt'] ? 'green' : ($esbStats['online'] > 0 ? 'yellow' : 'red') ?>" style="margin-top:.25rem;display:inline-block">
        <?= $esbStats['online'] ?> online
      </span>
    <?php else: ?>
      <div style="font-weight:700;color:var(--gray-600)">Noch keine ESB</div>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:1rem">
    <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem"><?= icon('receipt') ?> Offene Rechnungen</div>
    <div style="font-weight:700"><?= $openInvoices['cnt'] ?? 0 ?></div>
    <?php if (($openInvoices['cnt'] ?? 0) > 0): ?>
      <span class="badge badge-yellow" style="margin-top:.25rem;display:inline-block"><?= number_format((float)($openInvoices['summe'] ?? 0), 2, ',', '.') ?> €</span>
    <?php else: ?>
      <span class="badge badge-green" style="margin-top:.25rem;display:inline-block">alles beglichen</span>
    <?php endif; ?>
  </div>
</div>

<!-- Live-Daten -->
<div class="grid-2" style="margin-bottom:2rem">
  <div class="card">
    <h3 style="margin-bottom:1rem"><?= icon('lightning') ?> Live-Leistung</h3>
    <div style="display:flex;gap:2rem">
      <div>
        <div style="font-size:1.75rem;font-weight:700;color:#dc2626"><?= number_format($live['bezug_w'] ?? 0, 0, ',', '.') ?> W</div>
        <div style="font-size:.8rem;color:var(--gray-600)">Bezug</div>
      </div>
      <div>
        <div style="font-size:1.75rem;font-weight:700;color:#16a34a"><?= number_format($live['einsp_w'] ?? 0, 0, ',', '.') ?> W</div>
        <div style="font-size:.8rem;color:var(--gray-600)">Einspeisung</div>
      </div>
    </div>
    <p style="margin-top:.75rem;font-size:.8rem;color:var(--gray-600)"><?= $live['active_meters'] ?> Zählpunkte aktiv in den letzten 2 Min.</p>
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem"><?= icon('clipboard-text') ?> Schnellzugriff</h3>
    <div style="display:flex;flex-direction:column;gap:.5rem">
      <a href="/portal/members" class="btn btn-secondary"><?= icon('users-three') ?> Mitgliederliste</a>
      <a href="/portal/eda/upload" class="btn btn-secondary"><?= icon('folder-open') ?> EDA-Daten importieren</a>
      <a href="/portal/billing" class="btn btn-secondary"><?= icon('currency-eur') ?> Abrechnung</a>
    </div>
  </div>
</div>

<!-- Abrechnungsstatus -->
<?php if ($openBilling): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('currency-eur') ?> Abrechnung <?= htmlspecialchars($openBilling['quartal']) ?></h3>
  <?php if ($openBilling['status'] === 'ready'): ?>
    <div class="alert alert-success">Alle Daten vollständig — Abrechnung kann freigegeben werden.</div>
    <a href="/portal/billing" class="btn btn-primary">Jetzt freigeben</a>
  <?php else: ?>
    <div class="alert alert-warning">
      Abrechnung noch nicht bereit. Freigabe frühestens: <?= date('d.m.Y', strtotime($openBilling['freigabe_nach'])) ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Letzter EDA-Import -->
<div class="card">
  <h3 style="margin-bottom:.75rem"><?= icon('folder-open') ?> Letzter EDA-Import</h3>
  <?php if ($lastImport): ?>
    <table>
      <tr><th>Datei</th><td><?= htmlspecialchars($lastImport['filename']) ?></td></tr>
      <tr><th>Zeitraum</th><td><?= htmlspecialchars($lastImport['period_from']) ?> – <?= htmlspecialchars($lastImport['period_to']) ?></td></tr>
      <tr><th>Datensätze</th><td><?= number_format($lastImport['records_imported'], 0, ',', '.') ?></td></tr>
      <tr><th>Status</th><td><span class="badge badge-<?= $lastImport['status'] === 'ok' ? 'green' : 'yellow' ?>"><?= $lastImport['status'] ?></span></td></tr>
      <tr><th>Importiert am</th><td><?= date('d.m.Y H:i', strtotime($lastImport['imported_at'])) ?></td></tr>
    </table>
  <?php else: ?>
    <p style="color:var(--gray-600);font-size:.875rem">Noch kein EDA-Import durchgeführt.</p>
    <a href="/portal/eda/upload" class="btn btn-primary" style="margin-top:.75rem">EDA-Daten importieren</a>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
