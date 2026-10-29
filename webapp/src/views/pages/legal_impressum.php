<?php
$pageTitle = 'Impressum — EEG Strompool Feldkirchen Süd-West';
$pageDescription = 'Impressum der Erneuerbaren-Energie-Gemeinschaft Strompool Feldkirchen Süd-West gemäß § 5 ECG und § 25 Mediengesetz.';
// NGO-Schema (schema.org/JSON-LD) mit den Pflichtangaben aus dem Impressum selbst (Name, ZVR,
// Anschrift) -- ergänzt das generische Organization-Schema der Startseite um die konkreten
// Vereinsdaten, die Suchmaschinen/KI-Systeme sonst nur aus dem Fließtext heraus erraten müssten.
$extraHead = '
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "NGO",
  "name": "Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West",
  "alternateName": "Strom für alle",
  "url": "https://stromfueralle.at/",
  "identifier": { "@type": "PropertyValue", "name": "ZVR-Zahl", "value": "1778816746" },
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Eichenweg 2",
    "postalCode": "9560",
    "addressLocality": "St. Nikolai, Gemeinde Feldkirchen",
    "addressRegion": "Kärnten",
    "addressCountry": "AT"
  },
  "email": "office@stromfueralle.at"
}
</script>';
ob_start();
?>

<div class="legal">
  <h1>Impressum</h1>
  <div class="legal-meta">Angaben gemäß § 5 ECG und § 25 Mediengesetz</div>

  <h2>Medieninhaber und Betreiber</h2>
  <p>
    Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West (Verein)<br>
    ZVR-Zahl: 1778816746<br>
    Sitz: Eichenweg 2, 9560 St. Nikolai, Gemeinde Feldkirchen, Kärnten<br>
    Zuständige Vereinsbehörde: Bezirkshauptmannschaft Feldkirchen
  </p>

  <h2>Vertretungsbefugtes Organ</h2>
  <p>Patrick Ropper (Obmann)</p>

  <h2>Kontakt</h2>
  <p>
    E-Mail: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a><br>
    Kontaktformular: <a href="/rc108175/kontakt">rc108175/kontakt</a>
  </p>

  <h2>Vereinszweck</h2>
  <p>
    Gemeinsame Erzeugung und Nutzung von Energie aus erneuerbaren Quellen sowie nicht
    gewinnorientierte Verteilung dieser Energie innerhalb der Erneuerbaren-Energie-Gemeinschaft
    gemäß §§ 79 ff EAG und §§ 16c ff ElWOG 2010. Details siehe
    <a href="/rc108175/statuten">Statuten</a>.
  </p>

  <h2>Blattlinie (§ 25 Mediengesetz)</h2>
  <p>
    Diese Website dient der Information der Mitglieder und der Öffentlichkeit über die Tätigkeit
    der Erneuerbaren-Energie-Gemeinschaft Strompool Feldkirchen Süd-West sowie über die
    technische Plattform zur Verwaltung, Abrechnung und Darstellung von Energiedaten.
  </p>

  <h2>Haftung für Inhalte</h2>
  <p>
    Die Inhalte dieser Website wurden mit größtmöglicher Sorgfalt erstellt. Für die Richtigkeit,
    Vollständigkeit und Aktualität der Inhalte kann jedoch keine Gewähr übernommen werden. Als
    Diensteanbieter sind wir gemäß § 16 ECG nicht verpflichtet, übermittelte oder gespeicherte
    fremde Informationen zu überwachen.
  </p>

  <h2>Haftung für Links</h2>
  <p>
    Diese Website enthält Links zu externen Websites Dritter, auf deren Inhalte kein Einfluss
    besteht. Für diese fremden Inhalte kann daher keine Gewähr übernommen werden. Für die Inhalte
    der verlinkten Seiten ist stets der jeweilige Anbieter verantwortlich.
  </p>

  <h2>Urheberrecht</h2>
  <p>
    Die auf dieser Website erstellten Inhalte unterliegen dem österreichischen Urheberrecht.
    Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen
    des Urheberrechts bedürfen der schriftlichen Zustimmung des Vereins.
  </p>

  <h2>Streitschlichtung</h2>
  <p>
    Der Verein ist nicht verpflichtet und nicht bereit, an einem Streitbeilegungsverfahren vor
    einer Verbraucherschlichtungsstelle teilzunehmen.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
