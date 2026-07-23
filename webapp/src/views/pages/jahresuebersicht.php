<?php
/**
 * Jahresübersicht eines Mitglieds (Manager- und Mitglied-Ansicht gemeinsam). Eigenständige,
 * druckbare Seite (Browser-Druck → PDF), damit keine eigene LaTeX-Vorlage nötig ist.
 * Erwartet: $member, $community, $jahre (int[]), $jahr (int), $rows[], $sum[], $backUrl.
 */
$anzeigeName = trim(($member['company_name'] ?? '') ?: (($member['titel'] ?? '') . ' ' . ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')));
$statusMeta = [
    'offen'          => ['offen', '#92400e'],
    'eingezogen'     => ['eingezogen', '#15803d'],
    'ueberwiesen'    => ['überwiesen', '#15803d'],
    'fehlgeschlagen' => ['fehlgeschlagen', '#b91c1c'],
];
$isSelf = ($backUrl === '/portal/my/documents');
$jahresBasis = $isSelf ? '/portal/my/jahresuebersicht' : '/portal/members/' . $member['id'] . '/jahresuebersicht';
?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Jahresübersicht <?= (int)$jahr ?> – <?= htmlspecialchars($anzeigeName) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #111; background: #f3f4f6; padding: 1.5rem; }
    .sheet { max-width: 800px; margin: 0 auto; background: #fff; padding: 2rem 2.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.12); border-radius: 6px; }
    .toolbar { max-width: 800px; margin: 0 auto .75rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .toolbar a, .toolbar button { font-size: 13px; text-decoration: none; color: #2563eb; background: none; border: none; cursor: pointer; }
    .years a { display: inline-block; padding: .2rem .55rem; border: 1px solid #d1d5db; border-radius: 5px; margin-left: .3rem; color: #374151; }
    .years a.active { background: #16a34a; color: #fff; border-color: #16a34a; }
    h1 { font-size: 18px; margin-bottom: .25rem; }
    .meta { display: flex; justify-content: space-between; gap: 2rem; flex-wrap: wrap; margin: 1rem 0 1.5rem; font-size: 12px; color: #374151; }
    .meta strong { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; margin-bottom: .15rem; }
    table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: .5rem .4rem; text-align: left; }
    th { font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    tfoot td { font-weight: 700; border-top: 2px solid #111; border-bottom: none; }
    .empty { text-align: center; color: #6b7280; padding: 2rem; }
    @media print {
      body { background: #fff; padding: 0; }
      .toolbar { display: none; }
      .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= htmlspecialchars($backUrl) ?>">← Zurück</a>
    <div class="years">Jahr:
      <?php if (empty($jahre)): ?>
        <span style="color:#6b7280"><?= (int)$jahr ?></span>
      <?php else: foreach ($jahre as $y): ?>
        <a href="<?= $jahresBasis . '/' . (int)$y ?>" class="<?= (int)$y === (int)$jahr ? 'active' : '' ?>"><?= (int)$y ?></a>
      <?php endforeach; endif; ?>
    </div>
    <button onclick="window.print()">🖨️ Drucken / als PDF speichern</button>
  </div>

  <div class="sheet">
    <h1>Jahresübersicht <?= (int)$jahr ?></h1>
    <div style="font-size:12px;color:#6b7280"><?= htmlspecialchars($community['name'] ?? '') ?></div>

    <div class="meta">
      <div>
        <strong>Mitglied</strong>
        <?= htmlspecialchars($anzeigeName) ?><br>
        <?= htmlspecialchars(trim(($member['address'] ?? '') . ', ' . ($member['zip'] ?? '') . ' ' . ($member['city'] ?? ''), ', ')) ?>
      </div>
      <div>
        <strong>Kundennummer</strong>
        <?= htmlspecialchars((string)($member['kundennummer'] ?? '—')) ?>
      </div>
      <div>
        <strong>Erstellt am</strong>
        <?= date('d.m.Y') ?>
      </div>
    </div>

    <?php if (empty($rows)): ?>
      <p class="empty">Für <?= (int)$jahr ?> liegen keine Rechnungen vor.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Quartal</th>
          <th>Rechnungsnummer</th>
          <th class="num">Netto</th>
          <th class="num">USt</th>
          <th class="num">Brutto</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php [$stLabel, $stColor] = $statusMeta[$r['payment_status'] ?? 'offen'] ?? [$r['payment_status'] ?? '', '#374151']; ?>
          <tr>
            <td><?= htmlspecialchars($r['quartal']) ?></td>
            <td><?= htmlspecialchars($r['rechnungsnummer']) ?></td>
            <td class="num"><?= number_format((float)$r['netto'], 2, ',', '.') ?> €</td>
            <td class="num"><?= number_format((float)$r['ust'], 2, ',', '.') ?> €</td>
            <td class="num"><?= number_format((float)$r['brutto'], 2, ',', '.') ?> €</td>
            <td>
              <span style="color:<?= $stColor ?>"><?= htmlspecialchars($stLabel) ?></span>
              <?php if ((int)($r['mahnstufe'] ?? 0) > 0): ?>
                <span style="color:#b91c1c;font-size:11px">· <?= htmlspecialchars(mahnstufeText((int)$r['mahnstufe'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2">Summe <?= (int)$jahr ?></td>
          <td class="num"><?= number_format((float)$sum['netto'], 2, ',', '.') ?> €</td>
          <td class="num"><?= number_format((float)$sum['ust'], 2, ',', '.') ?> €</td>
          <td class="num"><?= number_format((float)$sum['brutto'], 2, ',', '.') ?> €</td>
          <td></td>
        </tr>
        <?php if ((float)$sum['gebuehr'] > 0): ?>
        <tr>
          <td colspan="2" style="font-weight:normal;color:#6b7280">davon Mahngebühren</td>
          <td class="num" colspan="3" style="font-weight:normal;color:#6b7280"><?= number_format((float)$sum['gebuehr'], 2, ',', '.') ?> €</td>
          <td></td>
        </tr>
        <?php endif; ?>
      </tfoot>
    </table>
    <p style="margin-top:1rem;font-size:11px;color:#6b7280">
      Positiver Betrag = von Ihnen zu zahlen, negativer Betrag = Guthaben/Auszahlung.
      Diese Übersicht fasst die bereits erstellten Quartalsrechnungen zusammen und ersetzt diese nicht.
    </p>
    <?php endif; ?>
  </div>
</body>
</html>
