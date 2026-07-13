<?php
$pageTitle = 'Datenschutzerklärung — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Datenschutzerklärung</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · Stand: Juli 2026</div>

  <h2>1. Verantwortlicher</h2>
  <p>
    Verantwortlich für die Datenverarbeitung im Rahmen dieser Plattform ist die
    EEG Strompool Feldkirchen Süd-West (ZVR 1778816746). Kontakt:
    <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>.
  </p>

  <h2>2. Welche Daten wir verarbeiten</h2>
  <ul>
    <li>Stammdaten der Mitglieder (Name, Adresse, E-Mail, ggf. UID-Nummer, IBAN)</li>
    <li>Zählpunktdaten und Energiedaten (Bezug und Einspeisung), die vom Netzbetreiber (EDA)
        bereitgestellt werden</li>
    <li>Abrechnungs- und Vertragsdaten (Verträge, Rechnungen, Zahlungsstatus)</li>
    <li>Technische Zugangsdaten (Login-Session) zur Nutzung des Mitgliederportals</li>
  </ul>

  <h2>3. Zweck der Verarbeitung</h2>
  <p>
    Die Daten werden ausschließlich zur Verwaltung der Energiegemeinschaft verwendet: Zuordnung
    von Zählpunkten, quartalsweise Abrechnung von Bezug und Einspeisung, Erstellung von
    Vertragsunterlagen und Rechnungen sowie Kommunikation mit den Mitgliedern.
  </p>

  <h2>4. Rechtsgrundlage</h2>
  <p>
    Die Verarbeitung erfolgt zur Erfüllung des Mitgliedschafts- bzw. Vertragsverhältnisses
    (Art. 6 Abs. 1 lit. b DSGVO) sowie zur Erfüllung rechtlicher Verpflichtungen im Zusammenhang
    mit dem Energiegemeinschaftengesetz (Art. 6 Abs. 1 lit. c DSGVO).
  </p>

  <h2>5. Weitergabe von Daten</h2>
  <p>
    Energiedaten werden vom zuständigen Netzbetreiber an die Plattform übermittelt. Eine
    Weitergabe an Dritte außerhalb der Gemeinschaft erfolgt nicht, außer wenn dies gesetzlich
    vorgeschrieben ist. Jedes Mitglied sieht ausschließlich seine eigenen Daten.
  </p>

  <h2>6. Speicherort &amp; Sicherheit</h2>
  <p>
    Die Daten werden auf Servern in Österreich gehostet. Die Übertragung erfolgt verschlüsselt
    (SSL/TLS). Passwörter werden ausschließlich als gehashte Werte gespeichert.
  </p>

  <h2>7. Speicherdauer</h2>
  <p>
    Personenbezogene Daten werden für die Dauer der Mitgliedschaft sowie darüber hinaus im
    Rahmen der gesetzlichen Aufbewahrungsfristen (insb. für Abrechnungsunterlagen) gespeichert.
  </p>

  <h2>8. Cookies</h2>
  <p>
    Für die Anmeldung im Mitgliederportal wird ein technisch notwendiges Session-Cookie
    verwendet. Die Einstellung „Hell/Dunkel"-Modus wird lokal im Browser (localStorage)
    gespeichert. Es werden keine Tracking- oder Marketing-Cookies eingesetzt.
  </p>

  <h2>9. Echtzeit-Zählerdaten über die Ausleseeinheit</h2>
  <p>
    Als Teil der Teilnahme an der EEG Strompool Feldkirchen Süd-West wird bei jedem Mitglied eine
    Ausleseeinheit betrieben, die über die P1-Kundenschnittstelle des Smart Meters folgende Daten
    ausliest und an uns übermittelt: Zählerstände (Bezug und ggf. Einspeisung), Momentanleistung
    sowie zugehörige Zeitstempel. Aus hochauflösenden Verbrauchsdaten können Rückschlüsse auf das
    Nutzungsverhalten (z. B. Anwesenheitszeiten) möglich sein.
  </p>
  <p>
    <strong>Zwecke:</strong> Ermittlung, wann innerhalb der EEG Energie benötigt wird und wann
    Überschuss besteht; Visualisierung des eigenen Verbrauchs in Echtzeit; Optimierung der
    Energienutzung in der Gemeinschaft. Die Abrechnung erfolgt davon unabhängig über die
    Viertelstundenwerte des Netzbetreibers.
  </p>
  <p>
    <strong>Rechtsgrundlage:</strong> Art. 6 Abs. 1 lit. b DSGVO (Erfüllung des Teilnahmevertrags —
    der Betrieb der Ausleseeinheit ist Vertragsbestandteil gemäß unseren AGB) sowie Art. 6 Abs. 1
    lit. f DSGVO (berechtigtes Interesse an der Optimierung der Energieverteilung in der
    Gemeinschaft).
  </p>
  <p>
    <strong>Zugriff und Empfänger:</strong> Jedes Mitglied sieht ausschließlich seine eigenen Daten
    sowie aggregierte Gesamtwerte der Gemeinschaft. Einzeldaten werden anderen Mitgliedern nicht
    zugänglich gemacht und nicht an Dritte weitergegeben. Die Verarbeitung erfolgt auf denselben
    Servern in Österreich wie unter Punkt 6 beschrieben.
  </p>
  <p>
    <strong>Speicherdauer:</strong> Es gilt dieselbe Speicherdauer wie unter Punkt 7 beschrieben
    (Dauer der Mitgliedschaft sowie gesetzliche Aufbewahrungsfristen), danach Löschung bzw.
    Anonymisierung/Aggregation.
  </p>
  <p>
    Diese Funktion befindet sich in Entwicklung; die Verarbeitung beginnt erst mit Inbetriebnahme
    der Ausleseeinheit.
  </p>

  <h2>10. Ihre Rechte</h2>
  <p>
    Sie haben das Recht auf Auskunft, Berichtigung, Löschung und Einschränkung der Verarbeitung
    Ihrer Daten sowie auf Datenübertragbarkeit. Zudem steht Ihnen ein Beschwerderecht bei der
    österreichischen Datenschutzbehörde zu. Anfragen richten Sie bitte an
    <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
