<?php
$pageTitle = 'Beitreten — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Beitreten</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <p>
    Sie möchten Mitglied der Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West
    werden? Laden Sie die Beitrittserklärung herunter, füllen Sie sie aus und senden Sie sie
    unterschrieben per E-Mail an
    <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a> oder postalisch an den
    Vereinssitz. Über die Aufnahme entscheidet der Vorstand.
  </p>

  <div style="margin:1.5rem 0 2.5rem;display:flex;flex-wrap:wrap;gap:1rem">
    <a href="/assets/docs/beitrittserklaerung-eeg-strompool-feldkirchen-suedwest.pdf" class="btn btn-primary" download>
      Beitrittserklärung als PDF herunterladen
    </a>
    <a href="/assets/docs/infoblatt-eeg-strompool-feldkirchen-suedwest.pdf" class="btn btn-secondary" target="_blank" rel="noopener">
      Infoblatt ansehen (PDF)
    </a>
  </div>

  <h2>Bevor Sie unterschreiben</h2>
  <p>Bitte lesen Sie vorab die folgenden Unterlagen:</p>
  <ul>
    <li><a href="/rc108175/statuten">Statuten</a> — Vereinszweck, Rechte und Pflichten der Mitglieder</li>
    <li><a href="/rc108175/datenschutz">Datenschutz</a> — Umgang mit Ihren personenbezogenen Daten</li>
    <li><a href="/rc108175/agb">AGBs</a> — allgemeine Geschäftsbedingungen der Mitgliedschaft</li>
    <li><a href="/rc108175/preisliste">Preisliste</a> — aktuelle Tarife und Mitgliedsbeitrag</li>
  </ul>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
