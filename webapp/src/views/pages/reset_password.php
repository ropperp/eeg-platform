<?php
// Neuer Look (Glas): gleiche dunkle Karte wie der Login, siehe layouts/auth_glass.php (14.11.2026).
if (function_exists('platformDesign') && platformDesign() === 'glas') {
    $pageTitle = 'Neues Passwort vergeben';
    ob_start(); ?>
    <div class="lg-stage">
      <span class="lg-accent lg-accent-tl" aria-hidden="true"></span>
      <span class="lg-accent lg-accent-br" aria-hidden="true"></span>
      <section class="lg-card">
        <h1>Neues <span>Passwort</span></h1>
        <?php if (!$valid): ?>
          <div class="lg-alert lg-alert-error" role="alert">Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen Link an.</div>
          <a href="/portal/forgot-password" class="lg-btn">Neuen Link anfordern <?= icon('arrow-right') ?></a>
        <?php else: ?>
          <p class="lg-sub">Mindestens 8 Zeichen. Danach melden Sie sich mit dem neuen Passwort an.</p>
          <?php if (!empty($error)): ?>
            <div class="lg-alert lg-alert-error" role="alert"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>
          <form method="post" action="/portal/reset-password" class="lg-form">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? '') ?>">
            <label for="password">Neues Passwort</label>
            <div class="lg-field">
              <?= icon('lock-key', 'lg-field-icon') ?>
              <input type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password" placeholder="••••••••">
            </div>
            <label for="password2">Neues Passwort bestätigen</label>
            <div class="lg-field">
              <?= icon('lock-key', 'lg-field-icon') ?>
              <input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password" placeholder="••••••••">
            </div>
            <button type="submit" class="lg-btn">Passwort speichern <?= icon('arrow-right') ?></button>
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
<?php $pageTitle = 'Neues Passwort vergeben'; $pageNoindex = true; ob_start(); ?>

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
