<?php
$pageTitle = 'AGB — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Allgemeine Geschäftsbedingungen</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · Stand: Juli 2026</div>

  <h2>1. Geltungsbereich</h2>
  <p>
    Diese AGB regeln die Mitgliedschaft und Teilnahme an der Erneuerbaren-Energie-Gemeinschaft
    „Strompool Feldkirchen Süd-West" (ZVR 1778816746) sowie die Nutzung der zugehörigen
    Online-Plattform.
  </p>

  <h2>2. Mitgliedschaft</h2>
  <p>
    Voraussetzung für die Teilnahme ist ein aktiver Zählpunkt im Netzgebiet der Gemeinschaft
    sowie die Zustimmung zu den Statuten. Die Anmeldung erfolgt über den zuständigen
    Netzbetreiber und die Registrierung als Mitglied in der Plattform.
  </p>

  <h2>3. Energiebezug &amp; Einspeisung</h2>
  <p>
    Grundlage der Abrechnung sind die vom Netzbetreiber übermittelten Zählpunktdaten (EDA).
    Die aktuell gültigen Tarife für Bezug und Einspeisung sind der Preisliste zu entnehmen.
    Tarifänderungen werden den Mitgliedern rechtzeitig vor Inkrafttreten mitgeteilt.
  </p>

  <h2>4. Abrechnung</h2>
  <p>
    Die Abrechnung erfolgt quartalsweise. Rechnungen werden über das Mitgliederportal zur
    Verfügung gestellt und sind innerhalb von 14 Tagen ab Rechnungsdatum zu begleichen.
  </p>

  <h2>5. Kündigung</h2>
  <p>
    Die Mitgliedschaft kann unter Einhaltung der in den Statuten festgelegten Frist gekündigt
    werden. Mit Ablauf der Mitgliedschaft endet auch die Teilnahme an der Energiegemeinschaft.
  </p>

  <h2>6. Haftung</h2>
  <p>
    Für die Verfügbarkeit von Netz und Zählpunkten sowie für Datenlieferungen des Netzbetreibers
    wird keine Haftung übernommen. Die Gemeinschaft haftet nur für Vorsatz und grobe
    Fahrlässigkeit.
  </p>

  <h2>7. Ausleseeinheit für Smart Meter (verpflichtend)</h2>
  <ol>
    <li>
      Die EEG Strompool Feldkirchen Süd-West stellt ihren Mitgliedern eine Ausleseeinheit zur
      Verfügung, die über die P1-Kundenschnittstelle des vom Netzbetreiber (Kärnten Netz GmbH)
      installierten Smart Meters Zählerstände und Echtzeit-Verbrauchsdaten erfasst und an die EEG
      übermittelt.
    </li>
    <li>
      Der Betrieb der Ausleseeinheit ist verpflichtender Bestandteil der Teilnahme an der EEG. Das
      Mitglied verpflichtet sich, die Ausleseeinheit nach Bereitstellung in Betrieb zu nehmen,
      dauerhaft betriebsbereit zu halten und die dafür erforderliche Freischaltung der
      P1-Kundenschnittstelle beim Netzbetreiber zu veranlassen.
    </li>
    <li>
      Die Ausleseeinheit befindet sich derzeit in Entwicklung. Der Zeitpunkt der Bereitstellung wird
      den Mitgliedern rechtzeitig bekannt gegeben; bis dahin erfolgt die Teilnahme ohne
      Ausleseeinheit. Ein bestimmter Bereitstellungszeitpunkt wird nicht zugesagt.
    </li>
    <li>
      Für die Ausleseeinheit wird ein Entgelt gemäß der jeweils gültigen Preisliste verrechnet
      (voraussichtlich ca. € 20,–). Der endgültige Preis wird dem Mitglied vor Übergabe bekannt
      gegeben.
    </li>
    <li>
      Die erfassten Daten dienen ausschließlich der Ermittlung von Energiebedarf und -überschuss
      innerhalb der EEG sowie der Visualisierung. Jedes Mitglied erhält Einsicht ausschließlich in
      seine eigenen Daten sowie in aggregierte Gesamtwerte der Gemeinschaft; Einzeldaten anderer
      Mitglieder werden nicht zugänglich gemacht.
    </li>
    <li>
      Die Abrechnung erfolgt unabhängig von der Ausleseeinheit auf Basis der vom Netzbetreiber
      übermittelten Viertelstundenwerte.
    </li>
  </ol>

  <h2>8. Schlussbestimmungen</h2>
  <p>
    Es gilt österreichisches Recht. Sollten einzelne Bestimmungen dieser AGB unwirksam sein,
    bleibt die Wirksamkeit der übrigen Bestimmungen davon unberührt.
  </p>

  <p style="margin-top:2rem">
    Kontakt: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
