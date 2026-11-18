<?php
// Erwartet optional: $sso (Keycloak erreichbar), $ssoDown (eingerichtet, aber nicht erreichbar),
// $abgemeldet, $fehler.
$title = 'Anmelden – Steuerzentrale';
$bare = true;
$sso ??= false; $ssoDown ??= false; $abgemeldet ??= false; $abgelaufen ??= false; $fehler ??= '';
ob_start(); ?>
<div class="hero">
  <section class="hero-card">
    <img class="logo" src="/logo-white.png" alt="strom für alle">
    <h1>Steuerzentrale</h1>
    <p>Wartungsmodus, Dienste und Logs der Plattform.</p>
    <?php if ($fehler !== ''): ?><p class="msg err"><?= $h($fehler) ?></p><?php endif; ?>
    <?php if ($abgemeldet): ?><p class="msg info">Sie sind abgemeldet.</p><?php endif; ?>
    <?php if ($abgelaufen): ?><p class="msg info">Ihre Sitzung ist abgelaufen. Die Aktion wurde nicht ausgeführt, bitte neu anmelden.</p><?php endif; ?>
    <?php if ($sso): ?>
      <a class="btn primary big" href="/anmelden/sso">Mit strom für alle anmelden</a>
      <p class="hint">Wer im Portal angemeldet ist, kommt ohne erneute Eingabe hinein. Nur für Platform-Admins.</p>
      <p class="hint" style="margin-top:1.25rem">Notzugang, falls der Anmeldedienst ausfällt:</p>
      <button type="button" class="btn" data-login>Mit Passkey anmelden</button>
    <?php else: ?>
      <?php if ($ssoDown): ?><p class="msg info">Der Anmeldedienst ist gerade nicht erreichbar. Notzugang per Passkey:</p><?php endif; ?>
      <button type="button" class="btn primary big" data-login>Mit Passkey anmelden</button>
    <?php endif; ?>
    <p class="msg" data-msg></p>
    <p class="hint">Passkeys für den Notzugang: im Portal unter Profil → Passkeys.</p>
  </section>
</div>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
