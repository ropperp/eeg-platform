<?php
$title = 'Anmelden – Steuerzentrale';
$bare = true;
ob_start(); ?>
<div class="hero">
  <section class="hero-card">
    <img class="logo" src="/logo-white.png" alt="strom für alle">
    <h1>Steuerzentrale</h1>
    <p>Wartungsmodus, Dienste und Logs der Plattform.</p>
    <button type="button" class="btn primary big" data-login>Mit Passkey anmelden</button>
    <p class="msg" data-msg></p>
    <p class="hint">Nur für Platform-Admins. Es gelten dieselben Passkeys wie im Portal
      (einrichten unter Profil → Passkeys).</p>
  </section>
</div>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
