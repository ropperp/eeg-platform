<?php
$title = 'Anmelden – Steuerzentrale';
ob_start(); ?>
<section class="card narrow">
  <h1>Anmelden</h1>
  <p>Zugang nur für Platform-Admins, ausschließlich mit Passkey. Es gelten dieselben Passkeys wie im Portal
     (einrichten unter Portal → Profil → Passkeys).</p>
  <button type="button" class="btn primary" data-login>Mit Passkey anmelden</button>
  <p class="msg" data-msg></p>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
