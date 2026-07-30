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
$producerMpIds = array_column(array_filter($meteringPoints, fn($mp) => in_array($mp['type'], ['producer', 'prosumer'], true)), 'id');

// Aktuelle Netto-Leistung (W): positiv = es wird gerade bezogen, negativ = es wird eingespeist
// (Vorzeichenkonvention auf Wunsch von Patrick, 30.07.2026) -- unabhängig vom gewählten
// Zeitraum unten, gilt immer "jetzt". null = kein Live-Wert vorhanden (kein ESP/gerade offline).
$currentNetPowerW = $mpIds ? memberCurrentNetPowerW($communityId, $mpIds) : null;

// Zeitraum-Auswahl für die Live-Kennzahl "Einspeisung in die Gemeinschaft" unten (Patrick,
// 30.07.2026) -- Whitelist gegen beliebige $_GET-Werte, ungültige Datumswerte fallen auf
// "heute" zurück statt einen Fehler zu werfen.
$range = $_GET['range'] ?? 'today';
if (!in_array($range, ['1h', '3h', '6h', '12h', '24h', 'today', 'week', 'month', 'year', 'day', 'custom'], true)) {
    $range = 'today';
}
$now = new DateTimeImmutable('now');
$rangeDay = $_GET['day'] ?? $now->format('Y-m-d');
$rangeFrom = $_GET['from'] ?? $now->format('Y-m-d');
$rangeTo = $_GET['to'] ?? $now->format('Y-m-d');
switch ($range) {
    case '1h':  $from = $now->modify('-1 hour');   $to = $now; $rangeLabel = 'letzte Stunde'; break;
    case '3h':  $from = $now->modify('-3 hours');  $to = $now; $rangeLabel = 'letzte 3 Stunden'; break;
    case '6h':  $from = $now->modify('-6 hours');  $to = $now; $rangeLabel = 'letzte 6 Stunden'; break;
    case '12h': $from = $now->modify('-12 hours'); $to = $now; $rangeLabel = 'letzte 12 Stunden'; break;
    case '24h': $from = $now->modify('-24 hours'); $to = $now; $rangeLabel = 'letzte 24 Stunden'; break;
    case 'week':
        $from = $now->modify('monday this week')->setTime(0, 0);
        $to = $now;
        $rangeLabel = 'diese Woche';
        break;
    case 'month':
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to = $now;
        $rangeLabel = 'dieser Monat';
        break;
    case 'year':
        $from = $now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0);
        $to = $now;
        $rangeLabel = 'dieses Jahr';
        break;
    case 'day':
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $rangeDay);
        $from = ($parsed ?: $now)->setTime(0, 0);
        $to = $from->modify('+1 day');
        $rangeLabel = $from->format('d.m.Y');
        break;
    case 'custom':
        $parsedFrom = DateTimeImmutable::createFromFormat('Y-m-d', $rangeFrom);
        $parsedTo = DateTimeImmutable::createFromFormat('Y-m-d', $rangeTo);
        $from = ($parsedFrom ?: $now)->setTime(0, 0);
        $to = ($parsedTo ?: $now)->modify('+1 day')->setTime(0, 0);
        if ($to <= $from) { $to = $from->modify('+1 day'); }
        $rangeLabel = $from->format('d.m.Y') . '–' . $to->modify('-1 day')->format('d.m.Y');
        break;
    default: // today
        $range = 'today';
        $from = $now->setTime(0, 0);
        $to = $now;
        $rangeLabel = 'heute';
        break;
}
// Live-Schätzung aus den ESP-Leistungsmesswerten, NICHT der amtliche Aufteilungsschlüssel des
// Netzbetreibers -- siehe ownEinspeisungInGemeinschaftKwh() in index.php und
// docs/AUFTEILUNGSSCHLUESSEL.md. Ergänzt die EDA-Monatskachel unten, ersetzt sie nicht.
$liveEinspeisungKwh = ($hasProducer && $producerMpIds)
    ? ownEinspeisungInGemeinschaftKwh($communityId, $producerMpIds, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'))
    : 0.0;

// EDA-basierte Monatswerte -- die hier gezeigten Werte sind dieselben, die auch der Abrechnung
// zugrunde liegen (kwh_teilnahme = "Bezug aus der Gemeinschaft", kwh_erzeugung = eigene
// Erzeugung, siehe Billing::generateDrafts()) -- nur belastbare Qualitätsstufen (L1/L2), damit
// hier keine vorläufigen L3-Ersatzwerte auftauchen. Bleibt die maßgebliche Grundlage für die
// Rechnung; die Live-Kennzahl "Einspeisung in die Gemeinschaft" oben ist eine zusätzliche,
// selbst berechnete Schätzung und ändert daran nichts.
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

<div class="card stat-card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem"><?= icon('lightning') ?> Aktuelle Leistung</h3>
  <?php if ($currentNetPowerW === null): ?>
    <p style="font-size:.85rem;color:var(--gray-600)">Keine Live-Daten verfügbar (Ausleseeinheit nicht installiert oder gerade offline).</p>
  <?php elseif ($currentNetPowerW >= 0): ?>
    <div class="stat-value" style="color:#dc2626"><?= icon('arrow-down') ?> <?= number_format($currentNetPowerW, 0, ',', '') ?> W</div>
    <div class="stat-label">wird gerade bezogen</div>
  <?php else: ?>
    <div class="stat-value" style="color:#16a34a"><?= icon('arrow-up') ?> <?= number_format(abs($currentNetPowerW), 0, ',', '') ?> W</div>
    <div class="stat-label">wird gerade eingespeist</div>
  <?php endif; ?>
</div>

<div class="grid-2" style="margin-bottom:1.5rem">
  <?php if ($hasConsumer): ?>
  <div class="card stat-card">
    <div class="stat-value"><?= number_format($currentMonth['teilnahme'], 0, ',', '') ?> kWh</div>
    <div class="stat-label">Bezug aus der Gemeinschaft<?= $currentMonth['label'] ? ' (' . htmlspecialchars($currentMonth['label']) . ')' : '' ?></div>
  </div>
  <?php endif; ?>
  <?php if ($hasProducer): ?>
  <div class="card stat-card">
    <div class="stat-value"><?= number_format($currentMonth['erzeugung'], 0, ',', '') ?> kWh</div>
    <div class="stat-label">Eigene Erzeugung<?= $currentMonth['label'] ? ' (' . htmlspecialchars($currentMonth['label']) . ')' : '' ?></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($hasProducer): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('lightning') ?> Einspeisung in die Gemeinschaft
    <span style="font-weight:400;font-size:.75rem;color:var(--gray-600)">(Live-Schätzung)</span>
  </h3>
  <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem">
    <select name="range" id="range-select" onchange="onDashboardRangeChange(this.value)"
            style="padding:.4rem .6rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="1h"    <?= $range === '1h' ? 'selected' : '' ?>>Letzte Stunde</option>
      <option value="3h"    <?= $range === '3h' ? 'selected' : '' ?>>Letzte 3 Stunden</option>
      <option value="6h"    <?= $range === '6h' ? 'selected' : '' ?>>Letzte 6 Stunden</option>
      <option value="12h"   <?= $range === '12h' ? 'selected' : '' ?>>Letzte 12 Stunden</option>
      <option value="24h"   <?= $range === '24h' ? 'selected' : '' ?>>Letzte 24 Stunden</option>
      <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Heute</option>
      <option value="week"  <?= $range === 'week' ? 'selected' : '' ?>>Diese Woche</option>
      <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>Dieser Monat</option>
      <option value="year"  <?= $range === 'year' ? 'selected' : '' ?>>Dieses Jahr</option>
      <option value="day"   <?= $range === 'day' ? 'selected' : '' ?>>Bestimmter Tag…</option>
      <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Bestimmter Zeitraum…</option>
    </select>
    <input type="date" name="day" value="<?= htmlspecialchars($rangeDay) ?>" id="range-day-input"
           style="display:<?= $range === 'day' ? 'inline-block' : 'none' ?>;padding:.35rem .5rem;border:1px solid #e5e7eb;border-radius:6px">
    <span id="range-custom-inputs" style="display:<?= $range === 'custom' ? 'inline-flex' : 'none' ?>;gap:.4rem;align-items:center">
      <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>" style="padding:.35rem .5rem;border:1px solid #e5e7eb;border-radius:6px">
      <span style="font-size:.8rem;color:var(--gray-600)">bis</span>
      <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>" style="padding:.35rem .5rem;border:1px solid #e5e7eb;border-radius:6px">
    </span>
    <button type="submit" class="btn btn-secondary" id="range-apply-btn"
            style="display:<?= in_array($range, ['day', 'custom'], true) ? 'inline-block' : 'none' ?>">Anwenden</button>
  </form>
  <div class="stat-value"><?= number_format($liveEinspeisungKwh, 2, ',', '') ?> kWh</div>
  <div class="stat-label">eigene Einspeisung, die <?= htmlspecialchars($rangeLabel) ?> von Mitgliedern dieser Gemeinschaft verbraucht wurde</div>
  <p style="font-size:.72rem;color:var(--gray-600);margin-top:.5rem">
    Selbst berechnete Live-Schätzung aus den Leistungsmesswerten Ihrer Ausleseeinheit -- nicht die
    amtliche Aufteilung des Netzbetreibers. Für die Rechnung zählt weiterhin ausschließlich der
    offizielle EDA-Import (siehe „Bezug aus der Gemeinschaft"/„Eigene Erzeugung" oben).
  </p>
</div>
<script>
function onDashboardRangeChange(v) {
  document.getElementById('range-day-input').style.display = v === 'day' ? 'inline-block' : 'none';
  document.getElementById('range-custom-inputs').style.display = v === 'custom' ? 'inline-flex' : 'none';
  document.getElementById('range-apply-btn').style.display = (v === 'day' || v === 'custom') ? 'inline-block' : 'none';
  if (v !== 'day' && v !== 'custom') document.getElementById('range-select').form.submit();
}
</script>
<?php endif; ?>

<?php if ($monthly): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('chart-bar') ?> Verlauf der letzten Monate</h3>
  <div style="display:flex;flex-direction:column;gap:.6rem">
    <?php foreach (array_reverse($monthly) as $m): ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--gray-600);margin-bottom:.2rem">
          <span><?= monatsLabel((string)$m['monat']) ?></span>
          <span>
            <?php if ($hasConsumer): ?><?= number_format((float)$m['teilnahme_kwh'], 0, ',', '') ?> kWh Bezug<?php endif; ?>
            <?php if ($hasConsumer && $hasProducer): ?> · <?php endif; ?>
            <?php if ($hasProducer): ?><?= number_format((float)$m['erzeugung_kwh'], 0, ',', '') ?> kWh Erzeugung<?php endif; ?>
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
