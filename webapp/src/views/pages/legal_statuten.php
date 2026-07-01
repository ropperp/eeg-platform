<?php
$pageTitle = 'Statuten — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Statuten</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <div class="alert alert-warning">
    Die Vereinsstatuten werden hier in Kürze veröffentlicht. Bitte den finalen Text nachreichen,
    dann wird diese Seite befüllt.
  </div>

  <p>
    Bis zur Veröffentlichung können die Statuten der Erneuerbaren-Energie-Gemeinschaft
    „Strompool Feldkirchen Süd-West" auf Anfrage per E-Mail angefordert werden.
  </p>

  <p>Kontakt: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a></p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
