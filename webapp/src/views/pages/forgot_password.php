<?php $pageTitle = 'Passwort zurücksetzen'; ob_start(); ?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Passwort zurücksetzen</h1>
    <p style="color:#6b7280;font-size:.875rem;margin-bottom:1.5rem">
      Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link zum Zurücksetzen Ihres Passworts.
    </p>

    <?php if (!empty($success)): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php else: ?>
      <form method="post" action="/portal/forgot-password">
        <div class="form-group">
          <label>E-Mail</label>
          <input type="email" name="email" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          Reset-Link senden
        </button>
      </form>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1rem;font-size:.875rem">
      <a href="/portal/login">← Zurück zum Login</a>
    </p>
  </div>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/base.php';
