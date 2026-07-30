<?php
$pageTitle = 'Mein Verbrauch';
$communityId = Auth::activeCommunityId();
DB::setCommunity($communityId);

$userId = Auth::userId();
$member = DB::fetchOne('SELECT * FROM members WHERE user_id = ? AND community_id = ? LIMIT 1', [$userId, $communityId]);
$meteringPoints = $member
    ? DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at', [$member['id']])
    : [];
$mpIds = array_column($meteringPoints, 'id');
$hasConsumer = (bool)array_filter($meteringPoints, fn($mp) => in_array($mp['type'], ['consumer', 'prosumer'], true));
$hasProducer = (bool)array_filter($meteringPoints, fn($mp) => in_array($mp['type'], ['producer', 'prosumer'], true));

// EDA-basierte Monatswerte -- KEIN Live-ESP-Zeitreihen-Chart hier, da die Ausleseeinheit beim
// Mitglied noch nicht produktionsreif im Feld ist (siehe docs/ESP_IDEEN.md). Die hier gezeigten
// Werte sind dieselben, die auch der Abrechnung zugrunde liegen (kwh_teilnahme = "Bezug aus der
// Gemeinschaft", kwh_erzeugung = eigene Erzeugung, siehe Billing::generateDrafts()) -- nur
// belastbare Qualitätsstufen (L1/L2), damit hier keine vorläufigen L3-Ersatzwerte auftauchen.
$monthly = [];
$currentMonth = ['teilnahme' => 0.0, 'erzeugung' => 0.0, 'label' => ''];
if ($mpIds) {
    $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
    $monthly = DB::fetchAll(
        "SELECT date_trunc('month', time) AS monat,
                COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme_kwh,
                COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung_kwh
         FROM eda_measurements
         WHERE community_id = ? AND metering_point_id IN ($placeholders) AND quality IN ('L1','L2')
         GROUP BY monat ORDER BY monat DESC LIMIT 6",
        array_merge([$communityId], $mpIds)
    );
    if ($monthly) {
        $currentMonth = [
            'teilnahme' => (float)$monthly[0]['teilnahme_kwh'],
            'erzeugung' => (float)$monthly[0]['erzeugung_kwh'],
            'label'     => monatsLabel((string)$monthly[0]['monat']),
        ];
    }
}
$maxMonthlyKwh = max(1.0, ...array_map(fn($m) => max((float)$m['teilnahme_kwh'], (float)$m['erzeugung_kwh']), $monthly ?: [['teilnahme_kwh' => 0, 'erzeugung_kwh' => 0]]));

$lastInvoice = $member ? DB::fetchOne(
    'SELECT * FROM invoices WHERE member_id = ? AND sent_at IS NOT NULL ORDER BY created_at DESC LIMIT 1',
    [$member['id']]
) : null;

ob_start();
?>

<h2 style="margin-bottom:1.5rem">Guten Tag, <?= htmlspecialchars($member['first_name'] ?? Auth::userName()) ?>!</h2>

<?php if (!$mpIds): ?>
<div class="card" style="margin-bottom:1.5rem;text-align:center;padding:2.5rem 1.5rem">
  <div style="font-size:2rem;margin-bottom:.5rem"><?= icon('chart-bar') ?></div>
  <h3 style="margin-bottom:.5rem">Noch keine Verbrauchsdaten</h3>
  <p style="color:var(--gray-600);font-size:.9rem;max-width:32rem;margin:0 auto">
    Sobald ein EDA-Import Daten für Ihren Zählpunkt enthält, sehen Sie hier Ihren monatlichen
    Bezug aus der Gemeinschaft. Ihre Rechnungen und Verträge finden Sie schon jetzt weiter unten.
  </p>
</div>
<?php else: ?>
<div class="grid-2" style="margin-bottom:1.5rem">
  <?php if ($hasConsumer): ?>
  <div class="card stat-card">
    <div class="stat-value"><?= number_format($currentMonth['teilnahme'], 0, ',', '.') ?> kWh</div>
    <div class="stat-label">Bezug aus der Gemeinschaft<?= $currentMonth['label'] ? ' (' . htmlspecialchars($currentMonth['label']) . ')' : '' ?></div>
  </div>
  <?php endif; ?>
  <?php if ($hasProducer): ?>
  <div class="card stat-card">
    <div class="stat-value"><?= number_format($currentMonth['erzeugung'], 0, ',', '.') ?> kWh</div>
    <div class="stat-label">Eigene Erzeugung<?= $currentMonth['label'] ? ' (' . htmlspecialchars($currentMonth['label']) . ')' : '' ?></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($monthly): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('chart-bar') ?> Verlauf der letzten Monate</h3>
  <div style="display:flex;flex-direction:column;gap:.6rem">
    <?php foreach (array_reverse($monthly) as $m): ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--gray-600);margin-bottom:.2rem">
          <span><?= monatsLabel((string)$m['monat']) ?></span>
          <span>
            <?php if ($hasConsumer): ?><?= number_format((float)$m['teilnahme_kwh'], 0, ',', '.') ?> kWh Bezug<?php endif; ?>
            <?php if ($hasConsumer && $hasProducer): ?> · <?php endif; ?>
            <?php if ($hasProducer): ?><?= number_format((float)$m['erzeugung_kwh'], 0, ',', '.') ?> kWh Erzeugung<?php endif; ?>
          </span>
        </div>
        <?php if ($hasConsumer): ?>
        <div style="background:var(--gray-100);border-radius:4px;height:8px;overflow:hidden;margin-bottom:2px">
          <div style="background:#dc2626;height:100%;width:<?= min(100, round((float)$m['teilnahme_kwh'] / $maxMonthlyKwh * 100)) ?>%"></div>
        </div>
        <?php endif; ?>
        <?php if ($hasProducer): ?>
        <div style="background:var(--gray-100);border-radius:4px;height:8px;overflow:hidden">
          <div style="background:#16a34a;height:100%;width:<?= min(100, round((float)$m['erzeugung_kwh'] / $maxMonthlyKwh * 100)) ?>%"></div>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem">Meine Zählpunkte</h3>
  <?php if (!$meteringPoints): ?>
    <p style="font-size:.875rem;color:var(--gray-600)">Noch keine Zählpunkte registriert.</p>
  <?php else: ?>
    <table style="font-size:.85rem">
      <?php foreach ($meteringPoints as $mp): ?>
        <tr>
          <th style="white-space:nowrap"><?= $mp['type'] === 'producer' ? icon('arrow-up') : icon('arrow-down') ?></th>
          <td><code style="font-size:.78rem"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:.75rem">Letzte Rechnung</h3>
  <?php if ($lastInvoice): ?>
    <p style="font-size:.875rem;color:var(--gray-600)">
      <?= htmlspecialchars($lastInvoice['rechnungsnummer']) ?> ·
      <?= number_format((float)$lastInvoice['saldo_eur'], 2, ',', '.') ?> € ·
      <?= date('d.m.Y', strtotime($lastInvoice['created_at'])) ?>
    </p>
  <?php else: ?>
    <p style="font-size:.875rem;color:var(--gray-600)">Noch keine Rechnung vorhanden.</p>
  <?php endif; ?>
  <p style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="/portal/invoices" class="btn btn-secondary"><?= icon('receipt') ?> Meine Rechnungen</a>
    <a href="/portal/my/documents" class="btn btn-secondary"><?= icon('file-text') ?> Meine Dokumente</a>
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
