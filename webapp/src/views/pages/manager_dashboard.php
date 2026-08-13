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
// /portal/api/live-power genutzt, das die Kachel unten alle 5s per Fetch aktualisiert).
$live = communityLivePower($communityId);
// Dritte Komponente fürs Energiefluss-Diagramm unten: bezug_w/einsp_w sind jeweils die Summe
// über die eigenen Zähler jedes Mitglieds (physischer Netzanschluss, kein interner Austausch
// zwischen Mitgliedern) -- die Differenz ist deshalb genau das, was die Community gerade
// GEMEINSAM entweder zusätzlich aus dem öffentlichen Netz zieht (Erzeugung < Verbrauch) oder
// als Überschuss ins öffentliche Netz einspeist (Erzeugung > Verbrauch). Patrick, 13.08.2026,
// nach einem Screenshot einer Fronius/Home-Assistant-Energiefluss-Ansicht: "wieviel wird
// eingespeist, wieviel wird bezogen, und als dritte Komponente noch das Netz".
$netzW = $live['einsp_w'] - $live['bezug_w'];

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
  <?php
    $netzDirClass = $netzW > 0 ? 'eflow-out' : ($netzW < 0 ? 'eflow-in' : '');
    $netzLabel    = $netzW > 0 ? 'Netz (Einspeisung)' : ($netzW < 0 ? 'Netz (Bezug)' : 'Netz');
    $pvActive     = ($live['einsp_w'] ?? 0) > 0;
    $verbActive   = ($live['bezug_w'] ?? 0) > 0;
    $netzActive   = $netzW != 0;
  ?>
  <div class="card">
    <h3 style="margin-bottom:1rem"><?= icon('lightning') ?> Energiefluss (Live)</h3>
    <div class="eflow" id="eflow">
      <div class="eflow-node">
        <div class="eflow-circle eflow-circle-pv"><?= icon('sun') ?></div>
        <div class="eflow-value" id="ef-pv"><?= number_format($live['einsp_w'] ?? 0, 0, ',', '.') ?> W</div>
        <div class="eflow-label">PV-Erzeugung</div>
      </div>
      <div class="eflow-connector eflow-connector-v<?= $pvActive ? ' active' : '' ?>" id="ef-line-pv"><span></span></div>
      <div class="eflow-middle">
        <div class="eflow-node">
          <div class="eflow-circle eflow-circle-netz <?= $netzDirClass ?>" id="ef-netz-circle"><?= icon('plug') ?></div>
          <div class="eflow-value" id="ef-netz"><?= number_format(abs($netzW), 0, ',', '.') ?> W</div>
          <div class="eflow-label" id="ef-netz-label"><?= $netzLabel ?></div>
        </div>
        <div class="eflow-connector eflow-connector-h<?= $netzActive ? ' active' : '' ?><?= $netzW > 0 ? ' reverse' : '' ?>" id="ef-line-netz"><span></span></div>
        <div class="eflow-hub"></div>
        <div class="eflow-connector eflow-connector-h<?= $verbActive ? ' active' : '' ?>" id="ef-line-verbrauch"><span></span></div>
        <div class="eflow-node">
          <div class="eflow-circle eflow-circle-verbrauch"><?= icon('buildings') ?></div>
          <div class="eflow-value" id="ef-verbrauch"><?= number_format($live['bezug_w'] ?? 0, 0, ',', '.') ?> W</div>
          <div class="eflow-label">Verbrauch</div>
        </div>
      </div>
    </div>
    <p style="margin-top:1rem;font-size:.8rem;color:var(--gray-600)"><span id="live-active-meters"><?= $live['active_meters'] ?></span> Zählpunkte aktiv in den letzten 2 Min.</p>
    <p id="live-disclaimer" style="margin-top:.5rem;font-size:.75rem;color:#b45309;display:<?= ($live['active_meters'] ?? 0) < ($live['total_meters'] ?? 0) ? 'block' : 'none' ?>">
      <?= icon('warning-circle') ?> Hinweis: Nicht alle Zählpunkte sind gerade online. Die angezeigten
      Gesamtwerte können daher geringfügig von der tatsächlichen Situation abweichen.
    </p>
    <p style="margin-top:.5rem;font-size:.72rem;color:var(--gray-600)">
      "Netz" zeigt die Differenz zwischen Erzeugung und Verbrauch der ganzen Community -- kein
      physischer Austausch zwischen Mitgliedern, sondern was gerade in Summe zusätzlich aus dem
      öffentlichen Netz kommt bzw. dorthin überschüssig eingespeist wird. Für die Abrechnung
      zählt weiterhin ausschließlich der offizielle EDA-Import, siehe Abrechnung.
    </p>
  </div>
  <style>
  .eflow { display:flex; flex-direction:column; align-items:center; padding:.5rem 0 0; }
  .eflow-node { display:flex; flex-direction:column; align-items:center; gap:.3rem; width:100px; }
  .eflow-circle {
    width:64px; height:64px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    border:3px solid var(--gray-200); background:var(--white);
    transition: border-color .3s ease, color .3s ease;
  }
  .eflow-circle .icon { width:28px; height:28px; }
  .eflow-circle-pv { border-color:#eab308; color:#eab308; }
  .eflow-circle-verbrauch { border-color:#3b82f6; color:#3b82f6; }
  .eflow-circle-netz { border-color:var(--gray-200); color:var(--gray-600); }
  .eflow-circle-netz.eflow-in  { border-color:#dc2626; color:#dc2626; }
  .eflow-circle-netz.eflow-out { border-color:#16a34a; color:#16a34a; }
  .eflow-value { font-weight:700; font-size:.95rem; color:var(--gray-800); }
  .eflow-label { font-size:.72rem; color:var(--gray-600); text-align:center; }
  .eflow-middle { display:flex; align-items:center; }
  .eflow-connector { position:relative; background:var(--gray-200); overflow:hidden; flex-shrink:0; color:var(--gray-600); }
  .eflow-connector-v { width:2px; height:26px; }
  .eflow-connector-h { width:52px; height:2px; }
  .eflow-connector span { position:absolute; inset:0; opacity:0; }
  .eflow-connector-v span { background: repeating-linear-gradient(to bottom, currentColor 0 6px, transparent 6px 12px); }
  .eflow-connector-h span { background: repeating-linear-gradient(to right, currentColor 0 6px, transparent 6px 12px); }
  .eflow-connector.active span { opacity:1; }
  .eflow-connector-v.active span { animation: eflow-dash-v .6s linear infinite; }
  .eflow-connector-h.active span { animation: eflow-dash-h .6s linear infinite; }
  .eflow-connector-h.reverse.active span { animation-direction: reverse; }
  @keyframes eflow-dash-h { from { background-position: 0 0; } to { background-position: -12px 0; } }
  @keyframes eflow-dash-v { from { background-position: 0 0; } to { background-position: 0 -12px; } }
  .eflow-hub { width:10px; height:10px; border-radius:50%; background:var(--gray-600); flex-shrink:0; }
  </style>
  <script>
  // Energiefluss-Grafik alle 5s per Fetch aktualisieren -- kein Seiten-Reload für Werte, die
  // sich laufend ändern (Patrick, 30.07.2026, erweitert 13.08.2026 um die Netz-Komponente nach
  // Vorbild einer Fronius/Home-Assistant-Energiefluss-Ansicht).
  setInterval(async () => {
    try {
      const res = await fetch('/portal/api/live-power');
      if (!res.ok) return;
      const d = await res.json();
      const netzW = d.einsp_w - d.bezug_w;

      document.getElementById('ef-pv').textContent = d.einsp_w.toLocaleString('de-AT') + ' W';
      document.getElementById('ef-verbrauch').textContent = d.bezug_w.toLocaleString('de-AT') + ' W';
      document.getElementById('ef-netz').textContent = Math.abs(netzW).toLocaleString('de-AT') + ' W';
      document.getElementById('ef-netz-label').textContent = netzW > 0 ? 'Netz (Einspeisung)' : (netzW < 0 ? 'Netz (Bezug)' : 'Netz');

      const netzCircle = document.getElementById('ef-netz-circle');
      netzCircle.classList.remove('eflow-in', 'eflow-out');
      if (netzW > 0) netzCircle.classList.add('eflow-out');
      else if (netzW < 0) netzCircle.classList.add('eflow-in');

      document.getElementById('ef-line-pv').classList.toggle('active', d.einsp_w > 0);
      document.getElementById('ef-line-verbrauch').classList.toggle('active', d.bezug_w > 0);
      const netzLine = document.getElementById('ef-line-netz');
      netzLine.classList.toggle('active', netzW !== 0);
      netzLine.classList.toggle('reverse', netzW > 0);

      document.getElementById('live-active-meters').textContent = d.active_meters;
      document.getElementById('live-disclaimer').style.display = (d.active_meters < d.total_meters) ? 'block' : 'none';
    } catch (e) { /* naechster Versuch in 5s */ }
  }, 5000);
  </script>

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
