<?php $pageTitle = 'Neues Passwort vergeben'; ob_start(); ?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Neues Passwort vergeben</h1>

    <?php if (!$valid): ?>
      <div class="alert alert-error" style="margin-bottom:1rem">
        Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen Link an.
      </div>
      <p style="text-align:center;font-size:.875rem">
        <a href="/portal/forgot-password">Neuen Link anfordern</a>
      </p>
    <?php else: ?>
      <?php if (!empty($error)): ?>
        <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="/portal/reset-password">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? '') ?>">
        <div class="form-group">
          <label>Neues Passwort</label>
          <input type="password" name="password" required minlength="8" autofocus>
        </div>
        <div class="form-group">
          <label>Neues Passwort bestätigen</label>
          <input type="password" name="password2" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          Passwort speichern
        </button>
      </form>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1rem;font-size:.875rem">
      <a href="/portal/login">← Zurück zum Login</a>
    </p>
  </div>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
