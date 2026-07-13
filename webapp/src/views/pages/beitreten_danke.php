<?php
$pageTitle = 'Beitrittserklärung übermittelt';
ob_start();
?>

<div class="legal">
  <h1>Vielen Dank!</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?></div>
  <p>
    Ihre Beitrittserklärung wurde erfolgreich übermittelt. Der Vorstand wurde benachrichtigt und
    prüft Ihre Angaben. Sobald Ihre Mitgliedschaft freigegeben ist, erhalten Sie Ihre Zugangsdaten
    für das Mitgliederportal per E-Mail.
  </p>
  <p>
    Bei Fragen wenden Sie sich gerne an
    <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/kontakt">unser Kontaktformular</a>.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
