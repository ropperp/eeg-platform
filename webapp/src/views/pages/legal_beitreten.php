<?php
$pageTitle = 'Beitreten — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Beitreten</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <p>
    Sie möchten Mitglied der Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West
    werden? Füllen Sie die Beitrittserklärung direkt online aus und unterschreiben Sie sie mit
    der Maus oder dem Finger — der Vorstand erhält danach eine Benachrichtigung und schaltet
    Ihre Mitgliedschaft frei. Über die Aufnahme entscheidet der Vorstand.
  </p>

  <div style="margin:1.5rem 0 2.5rem;display:flex;flex-wrap:wrap;gap:1rem">
    <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/beitreten/formular" class="btn btn-primary">
      Online Beitrittserklärung ausfüllen &amp; unterschreiben
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
