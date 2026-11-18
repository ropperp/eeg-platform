<?php
// Fehler bei der Anmeldung über Keycloak (App\Http\Controllers\KeycloakAuthController). Erwartet: $message.
$pageTitle = 'Anmeldung nicht möglich'; $pageNoindex = true; ob_start(); ?>
<div style="text-align:center;padding:6rem 2rem">
  <div style="font-size:4rem;margin-bottom:1rem"><?= icon('warning-circle') ?></div>
  <h1 style="font-size:2rem;margin-bottom:.5rem">Anmeldung nicht möglich</h1>
  <p style="color:var(--gray-600);margin-bottom:1.5rem"><?= htmlspecialchars($message) ?></p>
  <a href="/portal/login" class="btn btn-primary">Erneut anmelden</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
