<?php
$pageTitle = 'Strom für alle — Gemeinschaftlich Energie erzeugen & teilen';
$pageDescription = 'Plattform für Energiegemeinschaften (EEG) in Österreich: Mitgliederverwaltung, Abrechnung mit EDA-Daten und Echtzeit-Daten vom Smart Meter.';
// Eigenes Hero-Foto (unter /admin/templates hochgeladen, siehe /hero-banner-image) hat Vorrang
// vor der mitgelieferten SVG-Illustration -- per Inline-<style>-Override, da app.css das Bild
// nicht kennt (die Datei kann jederzeit ohne Deploy ausgetauscht werden).
$customHeroBanner = adminFilePath('hero-banner.jpg');
// Patrick, 02.10.2026: Hero-Banner wirkt beim Seitenaufruf lange grau, bevor das Foto erscheint.
// Ursache: das Bild hängt nur als CSS-background-image an .hero (siehe unten) -- solche
// url()-Referenzen entdeckt der Browser-Preload-Scanner erst beim Aufbau der CSSOM, nicht schon
// beim ersten Scan des HTML wie bei einem <img src>. Ein <link rel="preload"> im <head> (muss
// dafür VOR dem eigentlichen Seiteninhalt bekannt sein, siehe $extraHead/base.php) lässt den
// Browser den Download parallel zu allem anderen anstoßen, statt erst nach dem CSS-Parsing.
// Responsiv (16.11.2026): 960 px fürs Handy, 1920 px ab Tablet-Breite, jeweils WebP mit JPEG-Rückfall
// (image-set; Browser ohne Unterstützung nehmen die erste, reine JPEG-Deklaration).
if ($customHeroBanner) {
    $heroV = (int) filemtime($customHeroBanner);
    $heroUrl = static fn(int $w, string $f): string => '/hero-banner-image?v=' . $heroV . '&w=' . $w . '&f=' . $f;
    $extraHead = '<link rel="preload" as="image" fetchpriority="high" type="image/webp" imagesizes="100vw" imagesrcset="'
        . htmlspecialchars($heroUrl(960, 'webp') . ' 960w, ' . $heroUrl(1920, 'webp') . ' 1920w') . '">';
    // Nur Ziffern/Buchstaben/?&= in den URLs -> im <style> ohne HTML-Escaping sicher.
    $heroCss = static function (string $selector, string $gradient, int $w) use ($heroUrl): string {
        return $selector . ' { background-image: ' . $gradient . ", url('" . $heroUrl($w, 'jpg') . "');"
            . ' background-image: ' . $gradient . ", image-set(url('" . $heroUrl($w, 'webp') . "') type(\"image/webp\"), url('"
            . $heroUrl($w, 'jpg') . "') type(\"image/jpeg\")); }";
    };
}
// Öffentliche Kennzahlen der Pilot-EEG serverseitig (Sichtbarkeits-Check 17.11.2026: KI-Crawler führen
// kein JavaScript aus und lasen bisher "0 Mitglieder, 0 % Autarkie"). Fällt die Datenbank aus, bleibt
// die Startseite trotzdem erreichbar -- dann ohne Zahlen.
try {
    $pilotFacts = (new App\Infrastructure\Database\PdoPublicFactsRepository())->forSlug('strompool-feldkirchen');
} catch (\Throwable $e) {
    $pilotFacts = null;
}
// schema.org: Plattform und Verein getrennt und verknüpft (marketingOrgJsonLd()).
$extraHead = ($extraHead ?? '') . "\n" . jsonLdScript(marketingOrgJsonLd(AppConfig::get()->marketingBaseUrl(), $pilotFacts));
ob_start();
?>

<?php if ($customHeroBanner): ?>
<style>
<?php
$heroLight = 'linear-gradient(180deg, rgba(4,16,12,.62) 0%, rgba(4,16,12,.32) 45%, rgba(4,16,12,.72) 100%)';
$heroDark = 'linear-gradient(180deg, rgba(0,0,0,.78) 0%, rgba(0,0,0,.55) 45%, rgba(0,0,0,.85) 100%)';
echo $heroCss('.hero', $heroLight, 960), "\n", $heroCss('[data-theme="dark"] .hero', $heroDark, 960), "\n";
echo '@media (min-width: 961px) { ', $heroCss('.hero', $heroLight, 1920), ' ', $heroCss('[data-theme="dark"] .hero', $heroDark, 1920), " }\n";
?>
</style>
<?php endif; ?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>Erneuerbare Energie gemeinsam nutzen</h1>
    <p>Die Plattform für Erneuerbare-Energie-Gemeinschaften in Österreich – aus Kärnten.<br>
       Mitgliederverwaltung, Abrechnung mit EDA-Daten und Echtzeit-Daten vom Smart Meter.</p>
    <!-- Anmelden bleibt im Hero: am Handy steckt der Login sonst im Burger-Menü (Patrick, 17.11.2026). -->
    <a href="<?= htmlspecialchars(portalUrl('/portal/login')) ?>" class="btn btn-white btn-lg">Anmelden</a>
    <a href="/live" class="btn btn-outline btn-lg">Live-Anzeige</a>
  </div>
</section>

<!-- Features -->
<section class="features">
  <div class="container">
    <h2 class="reveal" style="text-align:center;font-size:1.75rem;margin-bottom:1rem">Was die Plattform bietet</h2>
    <p class="reveal" style="text-align:center;color:var(--gray-600);max-width:680px;margin:0 auto 2.5rem">
      Strom für alle deckt den Vereinsalltag einer Energiegemeinschaft ab: vom Online-Beitritt über Verträge mit
      digitaler Unterschrift bis zur Abrechnung und zur Live-Anzeige – für Vorstand und Mitglieder.
    </p>
    <div class="grid-3 reveal-grid">
      <a class="card info-card" href="/funktionen/echtzeit-daten">
        <div class="feature-icon"><?= icon('lightning') ?></div>
        <h3>Echtzeit-Monitoring</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Live sehen, wie viel Energie die Gemeinschaft gerade erzeugt und verbraucht – direkt vom Smart Meter.
        </p>
        <span class="info-more">Mehr erfahren →</span>
      </a>
      <a class="card info-card" href="/funktionen/abrechnung">
        <div class="feature-icon"><?= icon('currency-eur') ?></div>
        <h3>Automatische Abrechnung</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Abrechnung auf Basis der offiziellen EDA-Daten, Rechnungen als PDF, SEPA-Lastschrift und Mahnwesen.
        </p>
        <span class="info-more">Mehr erfahren →</span>
      </a>
      <a class="card info-card" href="/funktionen/mitgliederverwaltung">
        <div class="feature-icon"><?= icon('users-three') ?></div>
        <h3>Mitgliederverwaltung</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Online-Beitritt, Zählpunkte zuordnen, Verträge digital unterschreiben, Protokolle der Mitgliederversammlung.
        </p>
        <span class="info-more">Mehr erfahren →</span>
      </a>
      <div class="card">
        <div class="feature-icon"><?= icon('shield-check') ?></div>
        <h3>DSGVO-konform</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Energiedaten sind personenbezogen – jedes Mitglied sieht nur die eigenen Daten. Betrieb in Österreich.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon"><?= icon('buildings') ?></div>
        <h3>Mehrere EEGs</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Eine Plattform für beliebig viele Gemeinschaften, mit vollständig getrennten Daten je Gemeinschaft.
        </p>
      </div>
      <a class="card info-card" href="/funktionen/umstieg">
        <div class="feature-icon"><?= icon('arrow-right') ?></div>
        <h3>Einfacher Umstieg</h3>
        <p style="color:var(--gray-600);margin-top:.5rem;font-size:.9rem">
          Mitglieder per Import übernehmen, EDA-Datei einspielen, abrechnen. Importe passen wir für Sie an.
        </p>
        <span class="info-more">Mehr erfahren →</span>
      </a>
    </div>
  </div>
</section>

<!-- Pilot EEG -->
<section style="background:var(--white);padding:4rem 0;border-top:1px solid var(--gray-200)">
  <div class="container reveal" style="text-align:center">
    <h2 style="font-size:1.5rem;margin-bottom:1rem">Unser Pilotprojekt in Feldkirchen (Kärnten)</h2>
    <p style="color:var(--gray-600);max-width:620px;margin:0 auto 1rem">
      Die <strong>EEG Strompool Feldkirchen Süd-West</strong> läuft als erste Gemeinschaft auf dieser Plattform
      und liefert live Daten aus Kärnten.
    </p>
    <?php if ($pilotFacts && $pilotFacts['members'] > 0): ?>
      <!-- Serverseitig, damit Suchmaschinen und KI-Crawler echte Zahlen lesen (kein JavaScript nötig). -->
      <?php $nM = $pilotFacts['members']; $nZ = $pilotFacts['meters']; ?>
      <p class="home-facts">
        Aktuell <strong><?= number_format($nM, 0, ',', '.') ?> <?= $nM === 1 ? 'Mitglied' : 'Mitglieder' ?></strong><?php if ($nZ > 0): ?>
        mit <strong><?= number_format($nZ, 0, ',', '.') ?> <?= $nZ === 1 ? 'Zählpunkt' : 'Zählpunkten' ?></strong><?php endif; ?>.
      </p>
    <?php endif; ?>
    <!-- Live-Leistung: lädt site-animations.js nach (/api/live/:slug) und zählt hoch. Vorher steht "–"
         statt "0", damit niemand (auch kein Crawler) eine leere Gemeinschaft liest. -->
    <div id="pilot-live-stats" style="max-width:640px;margin:0 auto 2rem;display:none">
      <div class="grid-3">
        <div>
          <div class="stat-value" data-live-value="bezug_w" style="font-size:1.5rem;color:#dc2626">–</div>
          <div style="font-size:.78rem;color:var(--gray-600)">W Bezug gerade</div>
        </div>
        <div>
          <div class="stat-value" data-live-value="einspeisung_w" style="font-size:1.5rem;color:#16a34a">–</div>
          <div style="font-size:.78rem;color:var(--gray-600)">W Einspeisung gerade</div>
        </div>
        <div>
          <div class="stat-value" data-live-value="autarkie_pct" style="font-size:1.5rem;color:var(--green-dark)">–</div>
          <div style="font-size:.78rem;color:var(--gray-600)">% Autarkie</div>
        </div>
      </div>
    </div>
    <a href="/energiegemeinschaft-feldkirchen" class="btn btn-primary btn-lg">Zur Energiegemeinschaft</a>
    <a href="/live?eeg=strompool-feldkirchen" class="btn btn-secondary btn-lg">Echtzeit-Daten ansehen</a>
    <a href="/infoblatt.pdf" class="btn btn-secondary btn-lg" target="_blank" rel="noopener">Infoblatt (PDF)</a>
  </div>
</section>

<!-- Ausleseeinheit -->
<section style="padding:4rem 0;background:var(--white);border-top:1px solid var(--gray-200)">
  <div class="container reveal" style="max-width:720px">
    <div style="text-align:center;margin-bottom:1.5rem">
      <div class="feature-icon" style="margin:0 auto"><?= icon('broadcast') ?></div>
      <h2 style="font-size:1.5rem;margin-top:1rem">Echtzeit-Energie: sehen, wann Strom gebraucht wird</h2>
    </div>
    <p style="color:var(--gray-600);margin-bottom:1rem">
      Die offiziellen EDA-Daten kommen erst am nächsten Tag. Unsere eigene <strong>Ausleseeinheit</strong> –
      entwickelt als Diplomarbeitsprojekt an der HTL1 Lastenstraße in Klagenfurt – liest über die
      P1-Schnittstelle des Smart Meters die Werte in Echtzeit aus.
    </p>
    <p style="color:var(--gray-600);margin-bottom:.5rem">Damit sehen Mitglieder live:</p>
    <ul style="color:var(--gray-600);margin:0 0 1rem 1.25rem">
      <li><strong>den eigenen</strong> Verbrauch und die eigene Einspeisung,</li>
      <li><strong>die Gesamtbilanz der Gemeinschaft</strong> – wann Energie benötigt wird und wann Überschuss vorhanden ist.</li>
    </ul>
    <p style="color:var(--gray-600);margin-bottom:1rem">
      So laufen Waschmaschine oder E-Auto genau dann, wenn die Gemeinschaft Überschuss hat. Das erhöht die
      Eigenverbrauchsquote und senkt die Kosten für alle. Jedes Mitglied sieht dabei nur die eigenen Daten
      und die Summe der Gemeinschaft, nie die Einzeldaten anderer.
    </p>
    <p style="color:var(--gray-600);font-size:.85rem;font-style:italic;text-align:center">
      Die Ausleseeinheit läuft im Pilotbetrieb in Feldkirchen und wird schrittweise an die Mitglieder ausgegeben
      (voraussichtlich ca. € 30,–, Preis noch nicht endgültig). <a href="/funktionen/echtzeit-daten">Mehr zu den Echtzeit-Daten</a>
    </p>
  </div>
</section>

<!-- Umstieg und Mitgestalten -->
<section style="padding:4rem 0;border-top:1px solid var(--gray-200)">
  <div class="container reveal" style="max-width:860px">
    <div class="grid-2">
      <div>
        <h2 style="font-size:1.35rem;margin-bottom:.75rem">Umstieg ohne Abtippen</h2>
        <p style="color:var(--gray-600)">
          Mitglieder werden über Importfunktionen angelegt, danach spielen Sie die EDA-Datei (XLSX) ein und erstellen
          die Abrechnung. Für Ihre bisherigen Daten – Excel oder eine andere Software – programmieren wir die
          Importfunktion bei Bedarf passend nach. <a href="/funktionen/umstieg">So läuft der Umstieg</a>
        </p>
      </div>
      <div>
        <h2 style="font-size:1.35rem;margin-bottom:.75rem">Gemeinsam weiterentwickeln</h2>
        <p style="color:var(--gray-600)">
          Strom für alle ist aus der Praxis einer Energiegemeinschaft entstanden. Vorstände, Betreiber und Mitglieder
          sind herzlich eingeladen, ihre Ideen einzubringen – viele Funktionen gehen auf Wünsche aus dem Vereinsalltag
          zurück.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- Häufige Fragen (Kurzfassung; ausgezeichnet als FAQPage nur auf /faq) -->
<section style="padding:4rem 0;background:var(--white);border-top:1px solid var(--gray-200)">
  <div class="container reveal info-faq" style="max-width:860px">
    <h2 style="font-size:1.5rem;margin-bottom:1rem;text-align:center">Häufige Fragen</h2>
    <?php $homeFaq = marketingFaq(); foreach (['kosten', 'umstieg', 'abrechnung', 'oesterreich'] as $k): ?>
      <details class="faq-item">
        <summary><?= htmlspecialchars($homeFaq[$k]['q']) ?></summary>
        <p><?= htmlspecialchars($homeFaq[$k]['a']) ?></p>
      </details>
    <?php endforeach; ?>
    <p style="text-align:center"><a href="/faq">Alle häufigen Fragen ansehen →</a></p>
  </div>
</section>

<!-- Kontakt -->
<section style="padding:4rem 0">
  <div class="container reveal" style="text-align:center">
    <h2 style="font-size:1.5rem;margin-bottom:1rem">Interesse an der Plattform?</h2>
    <p style="color:var(--gray-600);margin-bottom:1.5rem;max-width:640px;margin-left:auto;margin-right:auto">
      Sie möchten Ihre Energiegemeinschaft auf Strom für alle verwalten? Die Kosten hängen vom Umfang ab – nur
      Verwaltung und Abrechnung oder zusätzlich Echtzeit-Daten mit Ausleseeinheit. Wir melden uns mit einem Angebot.
    </p>
    <a href="/kontakt" class="btn btn-primary btn-lg">Kontakt aufnehmen</a>
    <a href="<?= htmlspecialchars(portalUrl('/portal/login')) ?>" class="btn btn-secondary btn-lg">Anmelden</a>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
