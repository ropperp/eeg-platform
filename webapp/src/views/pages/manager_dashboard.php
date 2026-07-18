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

<!-- Live-Daten -->
<div class="grid-2" style="margin-bottom:2rem">
  <div class="card">
    <h3 style="margin-bottom:1rem">⚡ Live-Leistung</h3>
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
    <h3 style="margin-bottom:1rem">📋 Schnellzugriff</h3>
    <div style="display:flex;flex-direction:column;gap:.5rem">
      <a href="/portal/members" class="btn btn-secondary">👥 Mitgliederliste</a>
      <a href="/portal/eda/upload" class="btn btn-secondary">📂 EDA-Daten importieren</a>
      <a href="/portal/billing" class="btn btn-secondary">💶 Abrechnung</a>
    </div>
  </div>
</div>

<!-- Abrechnungsstatus -->
<?php if ($openBilling): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem">💶 Abrechnung <?= htmlspecialchars($openBilling['quartal']) ?></h3>
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
  <h3 style="margin-bottom:.75rem">📂 Letzter EDA-Import</h3>
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
