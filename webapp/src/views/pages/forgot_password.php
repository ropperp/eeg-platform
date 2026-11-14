<?php
// Neuer Look (Glas): gleiche dunkle Karte wie der Login, siehe layouts/auth_glass.php (14.11.2026).
if (function_exists('platformDesign') && platformDesign() === 'glas') {
    $pageTitle = 'Passwort zurücksetzen';
    ob_start(); ?>
    <div class="lg-stage">
      <span class="lg-accent lg-accent-tl" aria-hidden="true"></span>
      <span class="lg-accent lg-accent-br" aria-hidden="true"></span>
      <section class="lg-card">
        <h1>Passwort <span>vergessen?</span></h1>
        <p class="lg-sub">Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link, über den Sie ein neues Passwort festlegen.</p>
        <?php if (!empty($success)): ?>
          <div class="lg-alert lg-alert-ok" role="status"><?= htmlspecialchars($success) ?></div>
        <?php else: ?>
          <form method="post" action="/portal/forgot-password" class="lg-form">
            <?= csrfField() ?>
            <label for="email">E-Mail-Adresse</label>
            <div class="lg-field">
              <?= icon('envelope-simple', 'lg-field-icon') ?>
              <input type="email" id="email" name="email" required autofocus autocomplete="username" placeholder="name@beispiel.at">
            </div>
            <button type="submit" class="lg-btn">Link senden <?= icon('arrow-right') ?></button>
          </form>
        <?php endif; ?>
        <p class="lg-switch"><a href="/portal/login">← Zurück zur Anmeldung</a></p>
      </section>
    </div>
<?php
    $authStage = ob_get_clean();
    require __DIR__ . '/../layouts/auth_glass.php';
    return;
}
?>
<?php $pageTitle = 'Passwort zurücksetzen'; $pageNoindex = true; ob_start(); ?>

<div style="min-height:80vh;display:flex;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:420px">
    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Passwort zurücksetzen</h1>
    <p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
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
