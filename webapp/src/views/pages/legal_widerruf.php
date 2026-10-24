<?php
$pageTitle = 'Widerrufsbelehrung — EEG Strompool Feldkirchen Süd-West';
$pageDescription = 'Widerrufsbelehrung für den Online-Beitritt zur EEG Strompool Feldkirchen Süd-West gemäß Fern- und Auswärtsgeschäfte-Gesetz (FAGG).';
ob_start();
?>

<div class="legal">
  <h1>Widerrufsbelehrung</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · Stand: Oktober 2026</div>

  <p>
    Diese Widerrufsbelehrung gilt für den Beitritt zur Erneuerbaren-Energie-Gemeinschaft
    Strompool Feldkirchen Süd-West über unser Online-Beitrittsformular (Fernabsatzvertrag gemäß
    Fern- und Auswärtsgeschäfte-Gesetz – FAGG), sofern Sie als Verbraucher im Sinne des
    Konsumentenschutzgesetzes beitreten.
  </p>

  <h2>Widerrufsrecht</h2>
  <p>
    Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu
    widerrufen.
  </p>
  <p>
    Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses, das ist der Tag,
    an dem Ihre Beitrittserklärung von uns bestätigt bzw. Ihre Mitgliedschaft freigeschaltet wird.
  </p>
  <p>
    Um Ihr Widerrufsrecht auszuüben, müssen Sie uns
  </p>
  <p>
    EEG Strompool Feldkirchen Süd-West<br>
    Eichenweg 2, 9560 St. Nikolai<br>
    E-Mail: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>
  </p>
  <p>
    mittels einer eindeutigen Erklärung (z. B. ein mit der Post versandter Brief oder eine E-Mail)
    über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Sie können dafür das unten
    stehende Muster-Widerrufsformular verwenden, das ist aber nicht vorgeschrieben.
  </p>
  <p>
    Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Mitteilung über die Ausübung des
    Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.
  </p>

  <h2>Folgen des Widerrufs</h2>
  <p>
    Wenn Sie diesen Vertrag widerrufen, endet Ihre Mitgliedschaft rückwirkend zum Zeitpunkt des
    Vertragsabschlusses; bereits erfasste Zählpunktdaten werden danach nicht mehr für eine
    Abrechnung herangezogen.
  </p>
  <p>
    Die Teilnahme an der Energiegemeinschaft ist zunächst kostenlos -- Kosten für tatsächlich
    bezogene oder eingespeiste Energie entstehen erst ab dem Beginn Ihrer Teilnahme (nach
    Freischaltung durch uns). Widerrufen Sie, bevor Ihre Teilnahme freigeschaltet wurde, haben Sie
    daher im Regelfall nichts zu bezahlen.
  </p>
  <p>
    Im Beitrittsformular können Sie uns ausdrücklich darum bitten, dass die Stromzuteilung bereits
    vor Ablauf der 14-tägigen Widerrufsfrist beginnt ("Beginn vor Ablauf der Rücktrittsfrist").
    Haben Sie dem zugestimmt und widerrufen Sie dennoch, nachdem die Teilnahme bereits begonnen
    hat, haben Sie uns einen angemessenen Betrag zu zahlen. Dieser entspricht dem Anteil der bis
    zu dem Zeitpunkt, zu dem Sie uns von der Ausübung des Widerrufsrechts unterrichten, bereits
    bezogenen bzw. eingespeisten Energiemenge im Vergleich zum Gesamtumfang der im Vertrag
    vorgesehenen Teilnahme.
  </p>

  <h2>Muster-Widerrufsformular</h2>
  <p style="font-style:italic;color:var(--gray-600)">
    (Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden
    Sie es zurück.)
  </p>
  <div class="card" style="background:var(--gray-50)">
    <p>
      An: EEG Strompool Feldkirchen Süd-West, Eichenweg 2, 9560 St. Nikolai,
      E-Mail: office@stromfueralle.at
    </p>
    <p>
      Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über die
      Mitgliedschaft bei der Erneuerbaren-Energie-Gemeinschaft Strompool Feldkirchen Süd-West.
    </p>
    <p>
      Bestellt am (*) / erhalten am (*): ______________________<br>
      Name des/der Verbraucher(s): ______________________<br>
      Anschrift des/der Verbraucher(s): ______________________<br>
      Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier): ______________________<br>
      Datum: ______________________
    </p>
    <p style="font-size:.8rem;color:var(--gray-600)">(*) Unzutreffendes streichen.</p>
  </div>

  <p style="margin-top:2rem;font-size:.85rem;color:var(--gray-600)">
    Diese Belehrung orientiert sich am gesetzlichen Muster gemäß Anlage 1 zu § 4 Abs. 1 FAGG. Bei
    Fragen zu Ihrem konkreten Widerruf wenden Sie sich bitte direkt an uns, siehe
    <a href="/rc108175/kontakt">Kontakt</a>.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
