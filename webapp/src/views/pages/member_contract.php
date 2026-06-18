<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Mitgliedsvertrag – <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000; background: #fff; }
    .page { max-width: 210mm; margin: 0 auto; padding: 20mm 25mm; min-height: 297mm; }
    h1 { font-size: 16pt; font-weight: bold; text-align: center; margin-bottom: 6pt; }
    h2 { font-size: 12pt; font-weight: bold; margin-top: 18pt; margin-bottom: 4pt; border-bottom: 1px solid #000; padding-bottom: 2pt; }
    p { margin-top: 6pt; line-height: 1.5; text-align: justify; }
    .subtitle { text-align: center; font-size: 12pt; margin-bottom: 18pt; color: #333; }
    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin: 12pt 0; }
    .party-box { border: 1px solid #ccc; padding: 8pt; border-radius: 4pt; }
    .party-box strong { display: block; margin-bottom: 4pt; font-size: 10pt; text-transform: uppercase; color: #555; letter-spacing: .5pt; }
    table { width: 100%; border-collapse: collapse; margin-top: 8pt; font-size: 10pt; }
    table th, table td { border: 1px solid #ccc; padding: 4pt 6pt; text-align: left; }
    table th { background: #f5f5f5; font-weight: bold; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40pt; margin-top: 40pt; }
    .sig-line { border-top: 1px solid #000; padding-top: 4pt; font-size: 9pt; color: #555; }
    .page-break { page-break-before: always; }
    .legal-text { font-size: 9.5pt; color: #333; }
    .highlight { background: #fffbcc; padding: 2pt 4pt; border-radius: 2pt; }

    @media print {
      body { background: white; }
      .no-print { display: none !important; }
      .page { padding: 15mm 20mm; }
    }

    /* Screen-only controls */
    .print-bar {
      position: fixed; top: 0; left: 0; right: 0; background: #16a34a; color: #fff;
      padding: 10px 20px; display: flex; align-items: center; gap: 12px; z-index: 100;
      font-family: system-ui, sans-serif; font-size: 14px;
    }
    .print-bar a { color: #fff; text-decoration: none; opacity: .8; }
    .print-bar a:hover { opacity: 1; }
    .print-bar button {
      background: #fff; color: #16a34a; border: none; padding: 6px 16px;
      border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; margin-left: auto;
    }
    body { padding-top: 48px; }
    @media print { body { padding-top: 0; } .print-bar { display: none; } }
  </style>
</head>
<body>

<div class="print-bar no-print">
  <a href="/portal/members/<?= $member['id'] ?>">← Zurück</a>
  <span>Vertrag für <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
  <button onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
</div>

<div class="page">

  <!-- Kopf -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24pt">
    <div>
      <div style="font-weight:bold;font-size:13pt"><?= htmlspecialchars($community['name']) ?></div>
      <div style="font-size:10pt;color:#555"><?= htmlspecialchars($community['address'] ?? '') ?></div>
      <?php if ($community['zvr_number']): ?>
        <div style="font-size:9pt;color:#777">ZVR: <?= htmlspecialchars($community['zvr_number']) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;font-size:9pt;color:#555">
      Datum: <?= date('d.m.Y') ?><br>
      Vertragsnummer: <?= htmlspecialchars($member['id']) ?><br>
      <?php if ($community['marktpartner_id']): ?>
        Marktpartner-ID: <?= htmlspecialchars($community['marktpartner_id']) ?>
      <?php endif; ?>
    </div>
  </div>

  <h1>BEITRITTSVEREINBARUNG</h1>
  <div class="subtitle">Erneuerbare-Energie-Gemeinschaft gem. § 16c EAG</div>

  <!-- Vertragsparteien -->
  <h2>§ 1 Vertragsparteien</h2>
  <div class="parties">
    <div class="party-box">
      <strong>Erneuerbare-Energie-Gemeinschaft (EEG)</strong>
      <div><?= htmlspecialchars($community['name']) ?></div>
      <div style="font-size:9.5pt;color:#555;margin-top:4pt"><?= htmlspecialchars($community['address'] ?? '—') ?></div>
      <?php if ($community['zvr_number']): ?>
        <div style="font-size:9pt;color:#777">ZVR: <?= htmlspecialchars($community['zvr_number']) ?></div>
      <?php endif; ?>
      <?php if ($community['iban']): ?>
        <div style="font-size:9pt;margin-top:4pt">IBAN: <?= htmlspecialchars($community['iban']) ?></div>
      <?php endif; ?>
    </div>
    <div class="party-box">
      <strong>Mitglied</strong>
      <?php if ($member['company_name']): ?>
        <div><strong><?= htmlspecialchars($member['company_name']) ?></strong></div>
      <?php endif; ?>
      <div><?= htmlspecialchars($member['salutation'] ? $member['salutation'] . ' ' : '') ?><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></div>
      <div style="font-size:9.5pt;color:#555;margin-top:4pt">
        <?= htmlspecialchars($member['address']) ?><br>
        <?= htmlspecialchars($member['zip'] . ' ' . $member['city']) ?>
      </div>
      <?php if ($member['invoice_uid']): ?>
        <div style="font-size:9pt;color:#777;margin-top:4pt">UID: <?= htmlspecialchars($member['invoice_uid']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Gegenstand -->
  <h2>§ 2 Gegenstand und Zweck</h2>
  <p class="legal-text">
    Die EEG <?= htmlspecialchars($community['name']) ?> ist eine Erneuerbare-Energie-Gemeinschaft nach
    § 16c Elektrizitätswirtschafts- und -organisationsgesetz (ElWOG 2010) i.V.m. dem Erneuerbaren-Ausbau-Gesetz (EAG).
    Zweck der Gemeinschaft ist die gemeinschaftliche Erzeugung, Nutzung und Verteilung von elektrischer Energie
    aus erneuerbaren Quellen innerhalb des Gemeinschaftsgebietes des zuständigen Netzbetreibers.
  </p>
  <p class="legal-text">
    Das Mitglied tritt dieser Gemeinschaft mit Unterzeichnung dieser Vereinbarung bei und erklärt sich bereit,
    die Rechte und Pflichten gemäß Vereinsstatuten und dieser Vereinbarung zu übernehmen.
  </p>

  <!-- Zählpunkte -->
  <h2>§ 3 Registrierte Zählpunkte</h2>
  <p class="legal-text" style="margin-bottom:6pt">Folgende Zählpunkte des Mitglieds werden in die EEG eingebracht:</p>
  <?php if (empty($metering_points)): ?>
    <p class="legal-text" style="color:#c00">⚠️ Noch keine Zählpunkte registriert. Bitte vor Vertragsunterzeichnung eintragen.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Zählpunktnummer (AT...)</th>
          <th>Zählernummer</th>
          <th>Typ</th>
          <th>Registriert seit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($metering_points as $mp): ?>
        <tr>
          <td><code style="font-size:9pt"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
          <td><?= htmlspecialchars($mp['meter_code'] ?? '—') ?></td>
          <td><?= $mp['type'] === 'consumer' ? 'Bezug' : 'Einspeisung' ?></td>
          <td><?= $mp['registered_at'] ? date('d.m.Y', strtotime($mp['registered_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Tarife -->
  <h2>§ 4 Tarife und Vergütung</h2>
  <?php if ($tariff): ?>
  <table>
    <thead><tr><th>Leistung</th><th>Tarif</th><th>Gültig ab</th></tr></thead>
    <tbody>
      <tr>
        <td>Bezug aus der Gemeinschaft</td>
        <td><?= number_format($tariff['bezug_ct_kwh'], 4, ',', '.') ?> ct/kWh</td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
      <tr>
        <td>Einspeisung in die Gemeinschaft</td>
        <td><?= number_format($tariff['einspeisung_ct_kwh'], 4, ',', '.') ?> ct/kWh</td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
      <tr>
        <td>Mitgliedsbeitrag</td>
        <td><?= number_format($tariff['mitgliedsbeitrag_eur'], 2, ',', '.') ?> EUR/Quartal</td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
    </tbody>
  </table>
  <p class="legal-text" style="margin-top:6pt;font-size:9pt;color:#555">
    Tarife werden vom Vorstand der EEG festgelegt und können mit einer Frist von 6 Wochen geändert werden.
    Aktuelle Tarife sind jederzeit im Mitgliederportal einsehbar.
  </p>
  <?php else: ?>
    <p class="legal-text" style="color:#c00">⚠️ Kein Tarif hinterlegt. Bitte in den Einstellungen eintragen.</p>
  <?php endif; ?>

  <!-- Laufzeit -->
  <h2>§ 5 Laufzeit und Kündigung</h2>
  <p class="legal-text">
    Die Mitgliedschaft beginnt am
    <strong><?= $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '____.____.______' ?></strong>
    und läuft auf unbestimmte Zeit. Eine Kündigung ist unter Einhaltung einer Frist von 3 Monaten zum Quartalsende möglich.
    Die EEG kann die Mitgliedschaft bei schwerwiegenden Verstößen gegen die Vereinsstatuten mit sofortiger Wirkung beenden.
  </p>

  <!-- Bankverbindung -->
  <?php if ($member['member_iban']): ?>
  <h2>§ 6 Bankverbindung des Mitglieds (für Gutschriften)</h2>
  <p class="legal-text">
    IBAN: <strong><?= htmlspecialchars($member['member_iban']) ?></strong><?php if ($member['member_bic']): ?> &nbsp;|&nbsp; BIC: <strong><?= htmlspecialchars($member['member_bic']) ?></strong><?php endif; ?>
  </p>
  <?php endif; ?>

  <!-- Datenschutz -->
  <h2>§ <?= $member['member_iban'] ? 7 : 6 ?> Datenschutz</h2>
  <p class="legal-text">
    Die EEG verarbeitet personenbezogene Daten (Name, Adresse, Zählpunktdaten, Verbrauchswerte) ausschließlich
    zur Verwaltung der Gemeinschaft und zur Abrechnung mit dem Netzbetreiber gemäß Art. 6 Abs. 1 lit. b DSGVO.
    Eine Weitergabe an Dritte erfolgt nur soweit gesetzlich vorgeschrieben (z.B. Netzbetreiber, Behörden).
    Auskunfts- und Berichtigungsrechte gemäß Art. 15–17 DSGVO bleiben unberührt.
  </p>

  <!-- Unterschriften -->
  <div class="signatures" style="margin-top:50pt">
    <div>
      <div class="sig-line">
        Ort, Datum
      </div>
      <div style="margin-top:30pt" class="sig-line">
        Unterschrift Mitglied<br>
        <span style="font-size:9pt"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
      </div>
    </div>
    <div>
      <div class="sig-line">
        Ort, Datum
      </div>
      <div style="margin-top:30pt" class="sig-line">
        Für die EEG – Obmann/Obfrau<br>
        <span style="font-size:9pt"><?= htmlspecialchars($community['name']) ?></span>
      </div>
    </div>
  </div>

</div>
</body>
</html>
