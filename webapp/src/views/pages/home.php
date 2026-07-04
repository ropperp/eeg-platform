<?php
$pageTitle = 'EEG-Plattform — Gemeinschaftlich Energie erzeugen & teilen';
ob_start();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>Erneuerbare Energie gemeinsam nutzen</h1>
    <p>Die einfachste Plattform zur Verwaltung Ihrer Erneuerbaren-Energie-Gemeinschaft.<br>
       Echtzeit-Daten, automatische Abrechnung, volle Transparenz.</p>
    <a href="/live" class="btn btn-white btn-lg">Live-Anzeige</a>
    <a href="/portal/login" class="btn btn-outline btn-lg">Anmelden</a>
  </div>
</section>

<!-- Features -->
<section class="features">
  <div class="container">
    <h2 style="text-align:center;font-size:1.75rem;margin-bottom:3rem">Was die Plattform bietet</h2>
    <div class="grid-3">
      <div class="card">
        <div class="feature-icon">⚡</div>
        <h3>Echtzeit-Monitoring</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Sehen Sie live, wie viel Energie Ihre Gemeinschaft gerade erzeugt und verbraucht — direkt vom Smart Meter.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon">💶</div>
        <h3>Automatische Abrechnung</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Quartalsabrechnung auf Basis offizieller EDA-Daten. PDF-Rechnungen werden automatisch generiert und versendet.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon">👥</div>
        <h3>Mitgliederverwaltung</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Mitglieder anlegen, Zählpunkte zuordnen, Vertragsunterlagen per Knopfdruck generieren und versenden.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon">🔒</div>
        <h3>DSGVO-konform</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Energiedaten sind personenbezogen — jeder sieht nur seine eigenen Daten. Hosting in Österreich/DACH.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon">🏢</div>
        <h3>Multi-EEG fähig</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Eine Plattform für beliebig viele Gemeinschaften. Vollständige Datentrennung zwischen den Mandanten.
        </p>
      </div>
      <div class="card">
        <div class="feature-icon">📊</div>
        <h3>Öffentliches Dashboard</h3>
        <p style="color:#6b7280;margin-top:.5rem;font-size:.9rem">
          Zeigen Sie der Öffentlichkeit Ihre Autarkie-Quote und Gesamterzeugung — ohne Personendaten.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- Pilot EEG -->
<section style="background:#fff;padding:4rem 0;border-top:1px solid #e5e7eb">
  <div class="container" style="text-align:center">
    <h2 style="font-size:1.5rem;margin-bottom:1rem">Unser Pilotprojekt</h2>
    <p style="color:#6b7280;max-width:600px;margin:0 auto 2rem">
      Die <strong>EEG Strompool Feldkirchen Süd-West</strong> (ZVR 1778816746) läuft als erste Gemeinschaft
      auf dieser Plattform und liefert live Daten aus Kärnten.
    </p>
    <a href="/live?eeg=strompool-feldkirchen" class="btn btn-primary btn-lg">Echtzeit-Daten ansehen</a>
    <a href="/assets/docs/infoblatt-eeg-strompool-feldkirchen-suedwest.pdf" class="btn btn-secondary btn-lg" target="_blank" rel="noopener">Infoblatt (PDF)</a>
  </div>
</section>

<!-- Kontakt -->
<section style="padding:4rem 0">
  <div class="container" style="text-align:center">
    <h2 style="font-size:1.5rem;margin-bottom:1rem">Interesse an der Plattform?</h2>
    <p style="color:#6b7280;margin-bottom:1.5rem">
      Sie möchten Ihre EEG auf unserer Plattform verwalten? Kontaktieren Sie uns.
    </p>
    <a href="mailto:patrick.ropper@gmail.com" class="btn btn-secondary btn-lg">Kontakt aufnehmen</a>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
