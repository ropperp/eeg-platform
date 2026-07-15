<?php
$pageTitle = 'Anmelden';
ob_start();
?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Anmelden</h1>
    <p style="color:#6b7280;font-size:.875rem;margin-bottom:1.5rem">EEG-Mitgliederportal</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'password_reset'): ?>
      <div class="alert alert-success">Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.</div>
    <?php endif; ?>

    <form method="post" action="/portal/login">
      <div class="form-group">
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.65rem">
        Anmelden
      </button>
    </form>

    <p style="text-align:center;margin-top:1rem;font-size:.875rem">
      <a href="/portal/forgot-password">Passwort vergessen?</a>
    </p>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
