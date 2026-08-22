<?php
$pageTitle = 'Verwaltungs-Übersicht';
$communityId = Auth::activeCommunityId();
DB::setCommunity($communityId);

$community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
// is_demo = false: fiktive Demo-Mitglied-Identitäten (Präsentation/Diplomarbeit-Review, siehe
// migrate_20260905.sql) nicht in der echten Mitgliederstatistik mitzählen.
$memberCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM members WHERE community_id = ? AND status = ? AND is_demo = false', [$communityId, 'active'])['cnt'];
$pendingCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM members WHERE community_id = ? AND status = ? AND is_demo = false', [$communityId, 'pending'])['cnt'];
$mpCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM metering_points WHERE community_id = ? AND active = true', [$communityId])['cnt'];

$lastImport = DB::fetchOne('SELECT * FROM eda_imports WHERE community_id = ? ORDER BY imported_at DESC LIMIT 1', [$communityId]);
$openBilling = DB::fetchOne("SELECT * FROM billing_runs WHERE community_id = ? AND status IN ('pending','ready') ORDER BY quartal DESC LIMIT 1", [$communityId]);

// ─── Status-Kachelzeile: Betriebsreife auf einen Blick (letzter EDA-Import, letztes
// Backup, ESP online/offline, offene Rechnungen) -- siehe diplomarbeit-berater-Vorschlag. ───
// "online" heißt: esp_online UND esp_last_seen_at ist nicht älter als die konfigurierte
// Offline-Schwelle (espOfflineAfterMinutes(), Platform-Admin -> Einstellungen) --
// Sicherheitsnetz gegen ein hängengebliebenes Gerät, dessen MQTT-LWT nie auslöst.
$espOfflineMinutes = espOfflineAfterMinutes();
$espStats = DB::fetchOne(
    "SELECT COUNT(*) FILTER (WHERE esp_last_seen_at IS NOT NULL) AS bekannt,
            COUNT(*) FILTER (WHERE esp_online AND esp_last_seen_at > now() - (? || ' minutes')::interval) AS online,
            COUNT(*) FILTER (WHERE meter_last_seen_at IS NOT NULL) AS meter_bekannt,
            COUNT(*) FILTER (WHERE esp_online AND esp_last_seen_at > now() - (? || ' minutes')::interval AND NOT meter_reachable) AS meter_unreachable
     FROM metering_points WHERE community_id = ? AND active = true",
    [$espOfflineMinutes, $espOfflineMinutes, $communityId]
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

// Community-Gesamtleistung live -- siehe communityLivePower() in index.php (gemeinsam mit
// /portal/api/live-power genutzt, das das Energiefluss-Diagramm alle 5s per Fetch
// aktualisiert). $netzW (Erzeugung minus Verbrauch der ganzen Community) wird direkt im
// eingebundenen Partial berechnet, siehe partials/energy_flow.php.
$live = communityLivePower($communityId);

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
    <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem"><?= icon('plug') ?> ESP online</div>
    <?php if (($espStats['bekannt'] ?? 0) > 0): ?>
      <div style="font-weight:700"><?= $espStats['online'] ?> von <?= $espStats['bekannt'] ?></div>
      <span class="badge badge-<?= $espStats['online'] == $espStats['bekannt'] ? 'green' : ($espStats['online'] > 0 ? 'yellow' : 'red') ?>" style="margin-top:.25rem;display:inline-block">
        <?= $espStats['online'] ?> online
      </span>
    <?php else: ?>
      <div style="font-weight:700;color:var(--gray-600)">Noch keine ESP</div>
    <?php endif; ?>
    <?php if (($espStats['meter_unreachable'] ?? 0) > 0): ?>
      <div style="margin-top:.4rem">
        <span class="badge badge-red" title="ESP online, aber Zähler antwortet nicht -- möglicherweise Inselbetrieb/Stromausfall beim Mitglied, kein Plattform-Problem">
          <?= icon('warning-circle') ?> <?= $espStats['meter_unreachable'] ?> Zähler nicht erreichbar
        </span>
      </div>
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
  <?php require __DIR__ . '/../partials/energy_flow.php'; ?>

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
