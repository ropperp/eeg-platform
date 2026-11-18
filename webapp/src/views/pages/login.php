<?php
$pageTitle = 'Anmelden';
$pageNoindex = true;
ob_start();
?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Anmelden</h1>
    <p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">EEG-Mitgliederportal</p>

    <?php if (!empty($notice)): ?>
      <div class="alert alert-info"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
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

    <div data-passkey-login-wrap hidden style="margin-top:1rem">
      <div style="text-align:center;color:var(--gray-600);font-size:.8rem;margin-bottom:.5rem">oder</div>
      <button type="button" class="btn" data-passkey-login data-csrf="<?= htmlspecialchars(csrfToken()) ?>"
              style="width:100%;justify-content:center;padding:.65rem;border:1px solid var(--gray-300)">
        <?= icon('key') ?> Mit Passkey anmelden
      </button>
      <p data-passkey-msg role="alert" style="font-size:.85rem;margin-top:.5rem;text-align:center"></p>
    </div>

    <p style="text-align:center;margin-top:1rem;font-size:.875rem">
      <a href="/portal/forgot-password">Passwort vergessen?</a>
    </p>
  </div>
</div>

<script src="<?= htmlspecialchars(assetUrl('/assets/js/passkey.js')) ?>" defer></script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
