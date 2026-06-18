<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Einspeisevereinbarung – <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000; background: #fff; }
    .page { max-width: 210mm; margin: 0 auto; padding: 20mm 22mm; min-height: 297mm; }
    h1 { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 4pt; letter-spacing: .5pt; }
    h2 { font-size: 11pt; font-weight: bold; margin-top: 16pt; margin-bottom: 4pt; }
    p { margin-top: 6pt; line-height: 1.55; text-align: justify; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-top: 6pt; font-size: 10.5pt; }
    table th, table td { border: 1px solid #999; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    table th { background: #f2f2f2; font-weight: bold; }
    .sig-block { margin-top: 40pt; display: grid; grid-template-columns: 1fr 1fr; gap: 40pt; }
    .sig-line { margin-top: 30pt; border-top: 1px solid #000; padding-top: 3pt; font-size: 9.5pt; }
    .parties-box { border: 1px solid #ccc; padding: 8pt 10pt; margin-top: 8pt; line-height: 1.6; }
    .small { font-size: 9.5pt; color: #444; }
    @media print {
      .no-print { display: none !important; }
      .page { padding: 12mm 18mm; }
      body { padding-top: 0; }
    }
    .print-bar {
      position: fixed; top: 0; left: 0; right: 0; background: #b45309; color: #fff;
      padding: 9px 20px; display: flex; align-items: center; gap: 12px; z-index: 100;
      font-family: system-ui, sans-serif; font-size: 14px;
    }
    .print-bar a { color: #fff; text-decoration: none; opacity: .8; }
    .print-bar button { background: #fff; color: #b45309; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; margin-left: auto; }
    body { padding-top: 46px; }
  </style>
</head>
<body>

<div class="print-bar no-print">
  <a href="/portal/members/<?= $member['id'] ?>">← Zurück</a>
  <span>Einspeisevereinbarung — <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
  <button onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
</div>

<div class="page">

  <!-- Titel -->
  <div class="center" style="margin-bottom:20pt">
    <p class="bold" style="font-size:9pt;color:#555;letter-spacing:1pt">VORSCHLAG FÜR EINE</p>
    <h1>VEREINBARUNG über BESTAND und NUTZUNG<br>einer ENERGIEERZEUGUNGSANLAGE</h1>
    <p style="margin-top:6pt;font-size:10.5pt">(Typ: Überschusseinspeiser)</p>
    <p style="font-size:10pt;color:#555;margin-top:4pt">gemäß §§ 79f EAG und §§ 16c ff ElWOG 2010</p>
  </div>

  <p class="center bold">abgeschlossen zwischen</p>

  <div class="parties-box" style="margin-top:12pt">
    <p><span class="bold"><?= htmlspecialchars($community['name']) ?></span><br>
    <?= htmlspecialchars($community['address'] ?? '') ?><br>
    <?php if ($community['zvr_number']): ?>ZVR-Zahl: <?= htmlspecialchars($community['zvr_number']) ?><br><?php endif; ?>
    <?php if ($community['marktpartner_id']): ?>Marktpartner-ID: <?= htmlspecialchars($community['marktpartner_id']) ?><br><?php endif; ?>
    als „<span class="bold">Erneuerbare-Energie-Gemeinschaft</span>" (<span class="bold">„EEG"</span>) gemäß § 7 Abs 1 Z 6a iVm §§ 16c ff ElWOG 2010 einerseits</p>

    <p style="margin-top:10pt">sowie</p>

    <p style="margin-top:10pt">
    <?= htmlspecialchars(($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name']) ?>
    <?php if ($member['company_name']): ?>, <?= htmlspecialchars($member['company_name']) ?><?php endif; ?><br>
    <?= htmlspecialchars($member['address']) ?>, <?= htmlspecialchars($member['zip'] . ' ' . $member['city']) ?><br>
    <?php if ($member['invoice_uid']): ?>UID-Nr.: <?= htmlspecialchars($member['invoice_uid']) ?><br><?php endif; ?>
    als „<span class="bold">Eigentümer:in</span>" der Energieerzeugungsanlage andererseits,
    </p>
  </div>

  <p class="center bold" style="margin-top:14pt">wie folgt:</p>

  <!-- Präambel -->
  <h2>Präambel</h2>
  <p><?= htmlspecialchars($member['salutation'] ? $member['salutation'] . ' ' : '') . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?> ist Eigentümer:in der nachstehend beschriebenen Energieerzeugungsanlage sowie Mitglied der EEG <?= htmlspecialchars($community['name']) ?>.</p>
  <p>Mit der vorliegenden Vereinbarung wird der EEG die Betriebs- und Verfügungsgewalt über die Energieerzeugungsanlage im festgelegten Umfang übertragen. Die EEG ist damit in der Lage, im Rahmen der gesetzlichen Bestimmungen elektrische Energie zu erzeugen und für ihre Mitglieder Energiedienstleistungen zu erbringen.</p>

  <!-- §1 -->
  <h2>§ 1 Bestandgegenstand — Energieerzeugungsanlage</h2>
  <p>1.1 Gegenstand der vorliegenden Vereinbarung ist die im Eigentum des/der Eigentümer:in stehende Energieerzeugungsanlage mit folgenden Einspeise-Zählpunkten:</p>
  <?php $producer_points = array_filter($metering_points, fn($mp) => $mp['type'] === 'producer'); ?>
  <?php if ($producer_points): ?>
  <table style="margin-top:6pt">
    <thead><tr><th>Nr.</th><th>Zählpunktnummer (AT...)</th><th>Zählernummer</th><th>Registriert seit</th></tr></thead>
    <tbody>
    <?php $i = 1; foreach ($producer_points as $mp): ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><code style="font-size:9.5pt"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
        <td><?= htmlspecialchars($mp['meter_code'] ?? '—') ?></td>
        <td><?= $mp['registered_at'] ? date('d.m.Y', strtotime($mp['registered_at'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:#c00;margin-top:6pt">⚠️ Noch kein Einspeise-Zählpunkt registriert (Typ: Einspeisung).</p>
  <?php endif; ?>

  <p>1.2 Der Eigenverbrauch des/der Eigentümer:in ist mangels Einspeisung in das öffentliche Netz von der weiteren Verteilung ausgeschlossen. Allfällig verbleibende Überschussenergie (nach Verbrauch durch die teilnehmenden Netzbenutzenden) wird dem Erzeugungszählpunkt des/der Eigentümer:in zugeordnet.</p>

  <!-- §2 -->
  <h2>§ 2 Dauer und Kündigung</h2>
  <p>2.1 Das Bestandverhältnis beginnt am
  <strong><?= $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '__.__._____' ?></strong>
  und wird auf unbestimmte Zeit geschlossen. Eine Kündigung ist mit einer Frist von 3 Monaten zum Quartalsende möglich.</p>
  <p>2.2 Das Recht zur sofortigen Auflösung aus wichtigem Grund (§§ 1117, 1118 ABGB) bleibt beiden Seiten vorbehalten.</p>
  <p>2.3 Das Bestandverhältnis erlischt, wenn über das Vermögen einer der Vertragsparteien ein Insolvenzverfahren eröffnet wird und nicht innerhalb von 120 Tagen ein Sanierungsplan wirksam wird.</p>

  <!-- §3 -->
  <h2>§ 3 Bestandzins — Vergütung für die Einspeisung</h2>
  <p>3.1 Der monatliche Bestandzins ist dynamisch von der der EEG zugewiesenen Energiemenge abhängig und beträgt:</p>
  <?php if ($tariff): ?>
  <table style="margin-top:6pt">
    <thead><tr><th>Leistung</th><th>Vergütung (exkl. USt)</th><th>Gültig ab</th></tr></thead>
    <tbody>
      <tr>
        <td>Einspeisung in die EEG</td>
        <td><strong><?= number_format($tariff['einspeisung_ct_kwh'], 4, ',', '.') ?> ct/kWh</strong></td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:#c00">⚠️ Kein Tarif hinterlegt.</p>
  <?php endif; ?>
  <p>3.2 Auszahlung des Bestandzinses quartalsweise bis zum 15. des auf das Quartal folgenden Monats auf das Konto des/der Eigentümer:in:
  <?php if ($member['member_iban']): ?>
  <strong>IBAN: <?= htmlspecialchars($member['member_iban']) ?><?php if ($member['member_bic']): ?> | BIC: <?= htmlspecialchars($member['member_bic']) ?><?php endif; ?></strong>
  <?php else: ?><em>[IBAN des Mitglieds bitte im Portal eintragen]</em><?php endif; ?></p>
  <p>3.3 Bei Zahlungsverzug der EEG gelten 4 % Verzugszinsen p.a. als vereinbart.</p>

  <!-- §4 -->
  <h2>§ 4 Betriebs- und Verfügungsgewalt; Betriebsführung</h2>
  <p>4.1 Der/die Eigentümer:in überträgt der EEG die Betriebs- und Verfügungsgewalt an der Energieerzeugungsanlage im Umfang der von der EEG und deren teilnehmenden Netzbenutzenden verbrauchten, höchstens jedoch der ins öffentliche Netz eingespeisten Energie (Überschusseinspeiser).</p>
  <p>4.2 Es ist dem/der Eigentümer:in untersagt, die der EEG zugewiesene Energiemenge an andere natürliche oder juristische Personen zu verkaufen, zu übertragen oder sonst zur Verfügung zu stellen.</p>
  <p>4.3 Wartung und Instandhaltung der Energieerzeugungsanlage obliegen dem/der Eigentümer:in auf eigene Kosten. Der EEG ist im Rahmen der Verfügungsgewalt auf vorherige Ankündigung Zugang zur Anlage zu gewähren.</p>

  <!-- §5 -->
  <h2>§ 5 Zählpunktmanagement</h2>
  <p>5.1 Der/die Eigentümer:in verbleibt Inhaber:in der mit der Erzeugungsanlage verbundenen Zählpunkte und Vertragspartner:in des Netzbetreibers.</p>
  <p>5.2 Der/die Eigentümer:in stellt der EEG sämtliche für die Erfüllung der gesetzlichen Aufgaben gemäß §§ 16c ff ElWOG und §§ 79f EAG erforderlichen Daten zur Verfügung und erteilt der EEG Vollmacht für alle zur Vertragsumsetzung erforderlichen Rechtsgeschäfte.</p>

  <!-- §6 Datenschutz -->
  <h2>§ 6 Datenschutz</h2>
  <p>Die EEG verarbeitet personenbezogene Daten des/der Eigentümer:in (Name, Adresse, Zählpunktdaten, Einspeisemesswerte) ausschließlich zur Verwaltung der Gemeinschaft und zur Abrechnung gemäß Art. 6 Abs. 1 lit. b DSGVO. Eine Weitergabe an Dritte erfolgt nur soweit gesetzlich erforderlich.</p>

  <!-- §7 Sonstiges -->
  <h2>§ 7 Sonstiges</h2>
  <p>Änderungen dieser Vereinbarung bedürfen der Schriftform. Gerichtsstand ist das sachlich zuständige Gericht am Sitz der EEG. Es gilt österreichisches Recht.</p>

  <!-- Unterschriften -->
  <div class="sig-block">
    <div>
      <p><?= htmlspecialchars($member['city'] ?? '___________') ?>, _________________</p>
      <div class="sig-line">
        Unterschrift Eigentümer:in<br>
        <span class="small"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
      </div>
    </div>
    <div>
      <p><?= htmlspecialchars(explode(',', $community['address'] ?? '')[0] ?? '___________') ?>, _________________</p>
      <div class="sig-line">
        Für die EEG — Obmann/Obfrau<br>
        <span class="small"><?= htmlspecialchars($community['name']) ?></span>
      </div>
    </div>
  </div>

  <p class="small" style="margin-top:30pt;color:#888;text-align:center">
    Erstellt am <?= date('d.m.Y') ?> über das EEG-Verwaltungsportal. Vorlage basiert auf dem Muster der Koordinationsstelle für Energiegemeinschaften (energiegemeinschaften.gv.at), August 2022.
  </p>

</div>
</body>
</html>
