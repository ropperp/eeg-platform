<?php
$pageTitle = 'Kontakt — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Kontakt</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <p>
    Sie haben Fragen — zur Mitgliedschaft, zur Abrechnung oder zu Ihren bei uns
    gespeicherten personenbezogenen Daten? Melden Sie sich gerne direkt bei uns.
  </p>

  <h2>Ansprechpartner</h2>
  <p>
    Patrick Ropper (Obmann)<br>
    E-Mail: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a><br>
    Telefon: <a href="tel:+436506044812">+43 650 6044812</a>
  </p>

  <h2>Vereinssitz</h2>
  <p>
    EEG Strompool Feldkirchen Süd-West<br>
    Eichenweg 2, 9560 St. Nikolai<br>
    Feldkirchen in Kärnten
  </p>

  <h2>Fragen zum Datenschutz</h2>
  <p>
    Für Anfragen zu Auskunft, Berichtigung oder Löschung Ihrer Daten siehe auch unsere
    <a href="/rc108175/datenschutz">Datenschutzerklärung</a> — oder schreiben Sie uns
    einfach direkt eine E-Mail.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
