<?php
$pageTitle = 'Strom für alle — Gemeinschaftlich Energie erzeugen & teilen';
ob_start();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>Erneuerbare Energie gemeinsam nutzen</h1>
    <p>Die einfachste Plattform zur Verwaltung Ihrer Erneuerbaren-Energie-Gemeinschaft.<br>
       Echtzeit-Daten, automatische Abrechnung, volle Transparenz.</p>
    <a href="/live" class="btn btn-white btn-lg">Live-Anzeige</a>
    <a href="<?= htmlspecialchars(portalUrl('/portal/login')) ?>" class="btn btn-outline btn-lg">Anmelden</a>
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
    <a href="/infoblatt.pdf" class="btn btn-secondary btn-lg" target="_blank" rel="noopener">Infoblatt (PDF)</a>
  </div>
</section>

<!-- Ausleseeinheit -->
<section style="padding:4rem 0;background:#fff;border-top:1px solid #e5e7eb">
  <div class="container" style="max-width:720px">
    <div style="text-align:center;margin-bottom:1.5rem">
      <div class="feature-icon" style="margin:0 auto">📡</div>
      <h2 style="font-size:1.5rem;margin-top:1rem">Echtzeit-Energie: sehen, wann Strom gebraucht wird</h2>
    </div>
    <p style="color:#6b7280;margin-bottom:1rem">
      Als Mitglied der EEG Strompool Feldkirchen Süd-West erhältst du unsere eigene
      <strong>Ausleseeinheit</strong> — entwickelt als Diplomarbeitsprojekt an der HTL1 Lastenstraße. Sie liest über
      die P1-Schnittstelle deines Smart Meters (Kärnten Netz) die Verbrauchsdaten in Echtzeit aus.
    </p>
    <p style="color:#6b7280;margin-bottom:.5rem">Damit siehst du live:</p>
    <ul style="color:#6b7280;margin:0 0 1rem 1.25rem">
      <li><strong>deinen eigenen</strong> Verbrauch und deine Einspeisung,</li>
      <li><strong>die Gesamtbilanz der Gemeinschaft</strong> — wann in der EEG Energie benötigt wird
        und wann Überschuss vorhanden ist.</li>
    </ul>
    <p style="color:#6b7280;margin-bottom:1rem">
      So kannst du z. B. Waschmaschine oder E-Auto genau dann laufen lassen, wenn die Gemeinschaft
      Überschuss hat — das erhöht die Eigenverbrauchsquote und senkt die Kosten für alle.
    </p>
    <p style="color:#6b7280;margin-bottom:1rem">
      <strong>Deine Privatsphäre:</strong> Du siehst nur deine eigenen Daten und die Summe der
      Gemeinschaft — niemals die Einzeldaten anderer Mitglieder.
    </p>
    <p style="color:#9ca3af;font-size:.85rem;font-style:italic;text-align:center">
      Die Ausleseeinheit ist derzeit in Entwicklung und wird in den kommenden Monaten an alle
      Mitglieder ausgegeben (voraussichtlich ca. € 20,–, Preis noch nicht endgültig).
    </p>
  </div>
</section>

<!-- Kontakt -->
<section style="padding:4rem 0">
  <div class="container" style="text-align:center">
    <h2 style="font-size:1.5rem;margin-bottom:1rem">Interesse an der Plattform?</h2>
    <p style="color:#6b7280;margin-bottom:1.5rem">
      Sie möchten Ihre EEG auf unserer Plattform verwalten? Kontaktieren Sie uns.
    </p>
    <a href="/rc108175/kontakt" class="btn btn-secondary btn-lg">Kontakt aufnehmen</a>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
