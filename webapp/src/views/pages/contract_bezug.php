<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Bezugsvereinbarung – <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000; background: #fff; }
    .page { max-width: 210mm; margin: 0 auto; padding: 20mm 22mm; min-height: 297mm; }
    h1 { font-size: 15pt; font-weight: bold; text-align: center; margin-bottom: 4pt; letter-spacing: .5pt; }
    h2 { font-size: 11pt; font-weight: bold; margin-top: 16pt; margin-bottom: 4pt; }
    h3 { font-size: 11pt; font-weight: bold; text-decoration: underline; margin-top: 12pt; margin-bottom: 3pt; }
    p { margin-top: 6pt; line-height: 1.55; text-align: justify; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .underline { text-decoration: underline; }
    .indent { margin-left: 20pt; }
    table { width: 100%; border-collapse: collapse; margin-top: 6pt; font-size: 10.5pt; }
    table th, table td { border: 1px solid #999; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    table th { background: #f2f2f2; font-weight: bold; }
    .sig-block { margin-top: 40pt; display: grid; grid-template-columns: 1fr 1fr; gap: 40pt; }
    .sig-line { margin-top: 30pt; border-top: 1px solid #000; padding-top: 3pt; font-size: 9.5pt; }
    .parties-box { border: 1px solid #ccc; padding: 8pt 10pt; margin-top: 8pt; line-height: 1.6; }
    .section-num { font-weight: bold; margin-right: 6pt; }
    .small { font-size: 9.5pt; color: #444; }
    @media print {
      .no-print { display: none !important; }
      .page { padding: 12mm 18mm; }
      body { padding-top: 0; }
    }
    .print-bar {
      position: fixed; top: 0; left: 0; right: 0; background: #1d4ed8; color: #fff;
      padding: 9px 20px; display: flex; align-items: center; gap: 12px; z-index: 100;
      font-family: system-ui, sans-serif; font-size: 14px;
    }
    .print-bar a { color: #fff; text-decoration: none; opacity: .8; }
    .print-bar button { background: #fff; color: #1d4ed8; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; margin-left: auto; }
    body { padding-top: 46px; }
  </style>
</head>
<body>

<div class="print-bar no-print">
  <a href="/portal/members/<?= $member['id'] ?>">← Zurück</a>
  <span>Bezugsvereinbarung — <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
  <button onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
</div>

<div class="page">

  <!-- Titel -->
  <div class="center" style="margin-bottom:20pt">
    <p class="bold" style="font-size:9pt;color:#555;letter-spacing:1pt">VORSCHLAG FÜR EINE</p>
    <h1>ENERGIE- und LEISTUNGSBEZUGSVEREINBARUNG</h1>
    <p style="margin-top:8pt;font-size:10.5pt">gemäß §§ 79f EAG und §§ 16c ff ElWOG 2010</p>
  </div>

  <p class="center bold">abgeschlossen zwischen</p>

  <div class="parties-box" style="margin-top:12pt">
    <p><span class="bold"><?= htmlspecialchars($community['name']) ?></span><br>
    <?= htmlspecialchars($community['address'] ?? '') ?><br>
    <?php if ($community['zvr_number']): ?>ZVR-Zahl: <?= htmlspecialchars($community['zvr_number']) ?><br><?php endif; ?>
    <?php if ($community['marktpartner_id']): ?>Marktpartner-ID: <?= htmlspecialchars($community['marktpartner_id']) ?><br><?php endif; ?>
    als „<span class="bold">Erneuerbare-Energie-Gemeinschaft</span>" (<span class="bold">„EEG"</span>) gemäß § 7 Abs 1 Z 15a iVm §§ 16c ff ElWOG 2010 einerseits</p>

    <p style="margin-top:10pt">sowie</p>

    <p style="margin-top:10pt">
    <?= htmlspecialchars(($member['salutation'] ? $member['salutation'] . ' ' : '') . $member['first_name'] . ' ' . $member['last_name']) ?>
    <?php if ($member['company_name']): ?>, <?= htmlspecialchars($member['company_name']) ?><?php endif; ?><br>
    <?= htmlspecialchars($member['address']) ?>, <?= htmlspecialchars($member['zip'] . ' ' . $member['city']) ?><br>
    <?php if ($member['invoice_uid']): ?>UID-Nr.: <?= htmlspecialchars($member['invoice_uid']) ?><br><?php endif; ?>
    als „<span class="bold">Mitglied</span>", „Mitgliederseite" oder „<span class="bold">teilnehmende:r Netzbenutzer:in</span>" andererseits,
    </p>
  </div>

  <p class="center bold" style="margin-top:14pt">wie folgt:</p>

  <!-- §1 -->
  <h2>§ 1 Grundlagen der Leistungserbringung</h2>
  <p>1.1 Die EEG <?= htmlspecialchars($community['name']) ?> ist eine Erneuerbare-Energie-Gemeinschaft gemäß §§ 79f Erneuerbaren-Ausbau-Gesetz (EAG) iVm §§ 16c ff Elektrizitätswirtschafts- und -organisationsgesetz (ElWOG 2010) und verfügt über Energieerzeugungsanlagen, mit denen sie elektrische Energie aus erneuerbaren Quellen erzeugt, verbraucht, speichert und an ihre Mitglieder abgibt.</p>
  <p>1.2 Das Mitglied ist Mitglied der EEG. Es verfügt über eine Verbrauchsanlage mit folgender Zählpunktnummer:</p>
  <?php $consumer_points = array_filter($metering_points, fn($mp) => $mp['type'] === 'consumer'); ?>
  <?php if ($consumer_points): ?>
  <table style="margin-top:8pt">
    <thead><tr><th>Zählpunktnummer (AT...)</th><th>Zählernummer</th><th>Registriert seit</th></tr></thead>
    <tbody>
    <?php foreach ($consumer_points as $mp): ?>
      <tr>
        <td><code style="font-size:9.5pt"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
        <td><?= htmlspecialchars($mp['meter_code'] ?? '—') ?></td>
        <td><?= $mp['registered_at'] ? date('d.m.Y', strtotime($mp['registered_at'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:#c00;margin-top:6pt">⚠️ Noch kein Bezugs-Zählpunkt registriert (Typ: Bezug).</p>
  <?php endif; ?>

  <p>1.3 Das Mitglied stimmt ausdrücklich zu, dass der zuständige Netzbetreiber den Energiebezug der Verbrauchsanlage mit einem geeigneten Messgerät misst und diese Daten für die Energieverteilung und Verrechnung innerhalb der EEG verarbeitet.</p>

  <!-- §2 -->
  <h2>§ 2 Energiezuweisung und Aufteilung</h2>
  <p>2.1 Die virtuelle Zuweisung der von der EEG erzeugten oder dieser zugewiesenen Energie erfolgt nach dem tatsächlichen physikalischen Bezug (Messung am Zählpunkt) der Verbrauchsanlagen, im Verhältnis zum momentanen Verbrauchsverhalten der teilnehmenden Netzbenutzenden.</p>
  <p>2.2 Die Zuordnung ist mit dem Energieverbrauch des jeweiligen Mitglieds in der Viertelstunde begrenzt. Bei Nullverbrauch wird die Energie den anderen Mitgliedern zugeordnet. Die EEG arbeitet nach dem dynamischen Aufteilungsmodell gemäß § 16e Abs 3 ElWOG 2010.</p>
  <p>2.3 Die seitens des Netzbetreibers an die EEG zur Verfügung gestellten Daten zur Einspeisung der Erzeugungsanlagen und zum Bezug der teilnehmenden Netzbenutzenden bilden die Grundlage für die Verrechnung.</p>

  <!-- §3 -->
  <h2>§ 3 Energiebezugspreis und Zahlung</h2>
  <p>3.1 Das Mitglied verpflichtet sich, der EEG für den vom Netzbetreiber festgestellten Energiebezug aus der Energieerzeugungsanlage folgende Entgelte zu entrichten:</p>
  <?php if ($tariff): ?>
  <table style="margin-top:6pt">
    <thead><tr><th>Leistung</th><th>Preis (exkl. USt)</th><th>Gültig ab</th></tr></thead>
    <tbody>
      <tr>
        <td>Energiebezugspreis (Bezug aus EEG)</td>
        <td><strong><?= number_format($tariff['bezug_ct_kwh'], 4, ',', '.') ?> ct/kWh</strong></td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
      <tr>
        <td>Mitgliedsbeitrag</td>
        <td><strong><?= number_format($tariff['mitgliedsbeitrag_eur'], 2, ',', '.') ?> EUR/Quartal</strong></td>
        <td><?= date('d.m.Y', strtotime($tariff['valid_from'])) ?></td>
      </tr>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:#c00">⚠️ Kein Tarif hinterlegt.</p>
  <?php endif; ?>
  <p>3.2 Sämtliche genannten Entgelte verstehen sich exklusive der allenfalls anfallenden Umsatzsteuer sowie sonstiger öffentlicher Steuern, Abgaben und Gebühren.</p>
  <p>3.3 Abrechnung und Fälligkeit erfolgen gemäß den Bestimmungen der EEG quartalsweise. Bei Zahlungsverzug gelten 4 % Verzugszinsen p.a. als vereinbart.</p>
  <p>3.4 Tarife können durch Beschluss des Vorstandes oder der Generalversammlung der EEG mit Wirksamkeit zum Tag nach der Beschlussfassung geändert werden. Das Mitglied wird über Tarifänderungen mindestens 6 Wochen im Voraus über das Mitgliederportal informiert.</p>

  <!-- §4 -->
  <h2>§ 4 Betrieb, Haftung und Gewährleistung</h2>
  <p>4.1 Betrieb, Erhaltung und Wartung der Energieerzeugungsanlage sowie die Kostentragung hierfür liegen in der alleinigen Verantwortung der EEG. Die EEG haftet auch für Schäden aus der Energieerzeugungsanlage und hält das Mitglied gegen sämtliche Ansprüche Dritter schad- und klaglos.</p>
  <p>4.2 Die EEG leistet keine Gewähr für die Quantität, Art und den Umfang der über die Energieerzeugungsanlage erzeugten Energie. Ansprüche des Mitglieds gegen die EEG aus mangelnder Stromerzeugung sind ausgeschlossen.</p>
  <p>4.3 Die Verantwortlichkeit für die Verbrauchsanlage des Mitglieds richtet sich nach den allgemein anwendbaren Bestimmungen.</p>

  <!-- §5 -->
  <h2>§ 5 Laufzeit und Kündigung</h2>
  <p>5.1 Die vorliegende Vereinbarung tritt mit Unterzeichnung in Kraft und wird auf unbestimmte Zeit geschlossen.</p>
  <p>5.2 Das Mitglied kann die Vereinbarung unter Einhaltung einer Frist von 3 Monaten zum Quartalsende kündigen. Die EEG ist berechtigt, bei schwerwiegenden Verstößen gegen diese Vereinbarung oder die Vereinsstatuten mit sofortiger Wirkung zu kündigen.</p>
  <p>5.3 Mit dem Austritt aus der EEG erlischt auch die vorliegende Vereinbarung.</p>

  <!-- §6 Datenschutz -->
  <h2>§ 6 Datenschutz</h2>
  <p>6.1 Die EEG verarbeitet personenbezogene Daten (Name, Adresse, Zählpunktdaten, Verbrauchsmesswerte) des Mitglieds ausschließlich zur Verwaltung der Gemeinschaft und zur Abrechnung mit dem Netzbetreiber gemäß Art. 6 Abs. 1 lit. b DSGVO.</p>
  <p>6.2 Eine Weitergabe an Dritte erfolgt nur soweit gesetzlich erforderlich (Netzbetreiber, E-Control, Behörden). Auskunfts- und Berichtigungsrechte gemäß Art. 15–17 DSGVO bleiben unberührt.</p>

  <!-- §7 Bankverbindung -->
  <?php if ($member['member_iban'] || $community['iban']): ?>
  <h2>§ 7 Bankverbindungen</h2>
  <?php if ($community['iban']): ?>
  <p><strong>Konto der EEG</strong> (für Zahlungen des Mitglieds):<br>
  IBAN: <?= htmlspecialchars($community['iban']) ?><?php if ($community['bic']): ?> | BIC: <?= htmlspecialchars($community['bic']) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php if ($member['member_iban']): ?>
  <p style="margin-top:6pt"><strong>Konto des Mitglieds</strong> (für Gutschriften der EEG):<br>
  IBAN: <?= htmlspecialchars($member['member_iban']) ?><?php if ($member['member_bic']): ?> | BIC: <?= htmlspecialchars($member['member_bic']) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <!-- §8 Sonstiges -->
  <h2>§ <?= ($member['member_iban'] || $community['iban']) ? 8 : 7 ?> Sonstiges</h2>
  <p>Änderungen dieser Vereinbarung bedürfen der Schriftform. Mündliche Nebenabreden bestehen nicht. Gerichtsstand ist das sachlich zuständige Gericht am Sitz der EEG. Es gilt österreichisches Recht.</p>

  <!-- Unterschriften -->
  <div class="sig-block">
    <div>
      <p><?= htmlspecialchars($member['city'] ?? '___________') ?>, _________________</p>
      <div class="sig-line">
        Unterschrift Mitglied<br>
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
    Erstellt am <?= date('d.m.Y') ?> über das EEG-Verwaltungsportal. Vorlage basiert auf dem Muster der Koordinationsstelle für Energiegemeinschaften (energiegemeinschaften.gv.at).
  </p>

</div>
</body>
</html>
