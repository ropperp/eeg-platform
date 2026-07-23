<?php
$pageTitle = 'Bestätigung (2FA)';
ob_start();
?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">🔐 Bestätigung</h1>
    <p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
      Gib den aktuellen 6-stelligen Code aus deiner Authenticator-/Passwörter-App ein.
    </p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/portal/login/2fa">
      <div class="form-group">
        <label for="code">6-stelliger Code</label>
        <input type="text" id="code" name="code" required autofocus inputmode="numeric"
               autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
               style="letter-spacing:.4em;font-size:1.4rem;text-align:center" placeholder="000000">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.65rem">
        Bestätigen
      </button>
    </form>

    <p style="text-align:center;margin-top:1rem;font-size:.875rem">
      <a href="/portal/login">Abbrechen</a>
    </p>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
