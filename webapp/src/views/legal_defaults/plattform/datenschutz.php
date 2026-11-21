<?php
// Allgemeiner Teil der Datenschutzerklärung -- gilt für die ganze Plattform und steht auf jeder
// Datenschutz-Seite vorne (auch auf /datenschutz vor der EEG-Auswahl). Alles, was für alle EEGs
// gleich ist, steht hier (Patrick, 08.11.2026: "was übergreifend für alle gilt, vorne rauf");
// im Teil der jeweiligen EEG nur deren Besonderheiten (Verantwortlicher, Netzbetreiber, Ausleseeinheit).
// Standardfassung; Änderungen durch den Plattform-Admin unter /portal/dokumente.
$legalStand = '21. November 2026';
$hostingProvider = platformSettingText('hosting_provider');
$hostingNote = platformSettingText('hosting_provider_note');
?>
<h2>Geltungsbereich und Rollen</h2>
<p>
  Die Plattform „Strom für alle“ (stromfueralle.at) wird von mehreren Erneuerbare-Energie-
  Gemeinschaften (EEG) für ihre Mitgliederverwaltung, Abrechnung und Kommunikation genutzt.
  Verantwortlich im Sinne der DSGVO ist die jeweilige Energiegemeinschaft, deren Mitglied Sie sind
  oder werden möchten; deren Kontaktdaten finden Sie im Teil der jeweiligen Energiegemeinschaft.
  Die Plattform verarbeitet die Daten in deren Auftrag (Auftragsverarbeitung, Art. 28 DSGVO).
  Die Daten verschiedener Energiegemeinschaften sind technisch voneinander getrennt.
</p>
<p>
  Für die Website selbst und für Anfragen über das allgemeine Kontaktformular unter
  <a href="/kontakt">stromfueralle.at/kontakt</a> ist der Betreiber der Plattform verantwortlich
  (siehe <a href="/impressum">Impressum</a>).
</p>

<h2>Welche Daten auf der Plattform verarbeitet werden</h2>
<ul>
  <li>Stammdaten der Mitglieder (Name, Adresse, E-Mail, Telefon, ggf. UID-Nummer, Bankverbindung)</li>
  <li>Zählpunktdaten und Energiedaten (Bezug und Einspeisung), die vom Netzbetreiber (EDA)
      bereitgestellt werden</li>
  <li>Abrechnungs- und Vertragsdaten (Beitrittserklärung, Verträge, Rechnungen, Zahlungsstatus)</li>
  <li>Technische Zugangsdaten (Login-Session) zur Nutzung des Mitgliederportals sowie die
      IP-Adresse und der Zeitpunkt bei elektronischen Unterschriften (Nachweis)</li>
  <li>Sofern die jeweilige Energiegemeinschaft eine Ausleseeinheit für den Smart Meter einsetzt:
      deren Messdaten (Details im Teil der jeweiligen Energiegemeinschaft)</li>
</ul>

<h2>Zweck der Verarbeitung</h2>
<p>
  Die Daten der Mitglieder werden ausschließlich zur Verwaltung der jeweiligen
  Energiegemeinschaft verwendet: Zuordnung von Zählpunkten, Abrechnung von Bezug und Einspeisung,
  Erstellung von Vertragsunterlagen und Rechnungen sowie Kommunikation mit den Mitgliedern.
</p>

<h2>Rechtsgrundlage</h2>
<p>
  Die Verarbeitung erfolgt zur Erfüllung des Mitgliedschafts- bzw. Vertragsverhältnisses
  (Art. 6 Abs. 1 lit. b DSGVO) sowie zur Erfüllung rechtlicher Verpflichtungen nach dem
  Erneuerbaren-Ausbau-Gesetz und dem Elektrizitätswirtschafts- und -organisationsgesetz
  (Art. 6 Abs. 1 lit. c DSGVO). Anfragen über ein Kontaktformular werden zur Beantwortung der
  Anfrage verarbeitet (Art. 6 Abs. 1 lit. b bzw. f DSGVO).
</p>

<h2>Herkunft und Weitergabe der Daten</h2>
<p>
  Energiedaten (Viertelstundenwerte) übermittelt der zuständige Netzbetreiber über die
  Energiewirtschaftliche Datenaustausch-Plattform (EDA) an die Energiegemeinschaft. Eine
  Weitergabe an Dritte außerhalb der Energiegemeinschaft erfolgt nicht, außer an die unten
  genannten Auftragsverarbeiter, an den Netzbetreiber im Rahmen der An- und Abmeldung von
  Zählpunkten sowie wenn dies gesetzlich vorgeschrieben ist.
</p>

<h2>Weitere Auftragsverarbeiter</h2>
<p>
  <strong>Microsoft Ireland Operations Limited</strong> (Microsoft 365/Exchange Online, via
  Microsoft Graph API) für den Versand von System-E-Mails (z.B. Rechnungen, Beitrittsbestätigungen,
  Benachrichtigungen) -- dabei werden E-Mail-Adresse, Name und der jeweilige Nachrichteninhalt an
  Microsoft übermittelt. Die Auftragsverarbeitung erfolgt auf Basis des in die
  Microsoft-Produktbedingungen integrierten Data Protection Addendum (DPA).
</p>
<?php if ($hostingProvider !== ''): ?>
<p>
  <strong><?= htmlspecialchars($hostingProvider) ?></strong> (Hosting der Plattform in der EU)
  <?php if ($hostingNote !== ''): ?>-- <?= htmlspecialchars($hostingNote) ?><?php endif; ?>
  Mit dem Anbieter besteht ein Auftragsverarbeitungsvertrag nach Art. 28 DSGVO.
</p>
<?php endif; ?>

<h2>Speicherort &amp; Sicherheit</h2>
<p>
  <?php if ($hostingProvider !== ''): ?>
  Die Daten werden bei <?= htmlspecialchars($hostingProvider) ?> in der Europäischen Union gehostet.
  <?php else: ?>
  Die Daten werden auf einem eigenen Server in Österreich gehostet.
  <?php endif; ?>
  Die Übertragung erfolgt verschlüsselt
  (SSL/TLS). Passwörter werden ausschließlich als gehashte Werte gespeichert, besonders
  schützenswerte Zugangsdaten verschlüsselt. Jedes Mitglied sieht ausschließlich seine eigenen Daten.
</p>

<h2>Speicherdauer</h2>
<p>Die Daten werden je nach Kategorie unterschiedlich lange gespeichert:</p>
<ul>
  <li><strong>Stammdaten, Beitrittserklärung, Verträge:</strong> für die Dauer der Mitgliedschaft; danach
      nur noch, soweit Nachweis- oder Aufbewahrungspflichten bestehen (siehe nächster Punkt).</li>
  <li><strong>Abrechnungsdaten, Rechnungen und die zugrunde liegenden Viertelstundenwerte des
      Netzbetreibers:</strong> 7 Jahre (§ 132 BAO).</li>
  <li><strong>Echtzeitwerte der Ausleseeinheit</strong> (falls die Energiegemeinschaft eine einsetzt):
      die Einzelwerte (im 5-Sekunden-Takt) werden 400 Tage gespeichert und danach gelöscht. Eine
      Zusammenfassung in 15-Minuten-Fenstern (Mittel- und Spitzenleistung, letzter Zählerstand)
      bleibt für die Dauer der Teilnahme erhalten. Die Echtzeitwerte sind nicht Grundlage der
      Abrechnung.</li>
  <li><strong>Technische Zugangsdaten</strong> (Login-Session): bis zum Abmelden bzw. Ablauf der Session.</li>
  <li><strong>Nachrichten über ein Kontaktformular:</strong> bis die Anfrage erledigt ist, sofern keine
      Aufbewahrungspflicht besteht.</li>
</ul>

<h2>Cookies</h2>
<p>
  Für die Anmeldung im Mitgliederportal wird ein technisch notwendiges Session-Cookie
  verwendet. Die Einstellung „Hell/Dunkel“-Modus wird lokal im Browser (localStorage)
  gespeichert. Es werden keine Tracking- oder Marketing-Cookies eingesetzt.
</p>

<h2>Ihre Rechte</h2>
<p>
  Sie haben das Recht auf Auskunft, Berichtigung, Löschung und Einschränkung der Verarbeitung
  Ihrer Daten sowie auf Datenübertragbarkeit. Zudem steht Ihnen ein Beschwerderecht bei der
  österreichischen Datenschutzbehörde zu. Anfragen richten Sie bitte an die jeweilige
  Energiegemeinschaft (Kontaktdaten in deren Teil der Datenschutzerklärung) oder an
  <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>.
</p>
