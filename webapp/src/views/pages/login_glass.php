<?php
// Login im neuen Look (Patrick, 14.11.2026: Stil eines Video-Vorbilds "Glassy Login Form", in
// Blau): dunkle Glas-Karte mit abgeschrägten Ecken vor Lichtstreifen; "Registrieren" dreht die
// Karte um. Ein eigenes Konto kann man hier bewusst NICHT anlegen -- Zugänge erstellt die
// Energiegemeinschaft nach dem Beitritt -- deshalb führt die Rückseite zur Beitrittserklärung
// und (klein) zum Plattform-Kontakt. Nur im Glas-Design (platformDesign() === 'glas'), sonst
// bleibt login.php. Erwartet optional: $error.
$beitretenUrl = marketingUrl('/beitreten');
$kontaktUrl = marketingUrl('/kontakt');
$pageTitle = 'Anmelden';
ob_start();
?>
    <div class="lg-stage<?= empty($error) ? '' : ' has-error' ?>" id="lg-stage">
      <span class="lg-accent lg-accent-tl" aria-hidden="true"></span>
      <span class="lg-accent lg-accent-br" aria-hidden="true"></span>
      <span class="lg-orb" aria-hidden="true"><i></i></span>

      <div class="lg-flip">
        <!-- Vorderseite: Anmelden -->
        <section class="lg-card lg-front" id="lg-login" aria-labelledby="lg-login-title">
          <h1 id="lg-login-title">Willkommen <span>zurück</span></h1>
          <p class="lg-sub">Melden Sie sich im Portal von „Strom für alle“ an.</p>

          <?php if (!empty($error)): ?>
            <div class="lg-alert lg-alert-error" role="alert"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>
          <?php if (($_GET['success'] ?? '') === 'password_reset'): ?>
            <div class="lg-alert lg-alert-ok" role="status">Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.</div>
          <?php endif; ?>

          <form method="post" action="/portal/login" class="lg-form">
            <?= csrfField() ?>
            <label for="email">E-Mail-Adresse</label>
            <div class="lg-field">
              <?= icon('envelope-simple', 'lg-field-icon') ?>
              <input type="email" id="email" name="email" required autocomplete="username" autofocus
                     placeholder="name@beispiel.at" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <label for="password">Passwort</label>
            <div class="lg-field">
              <?= icon('lock-key', 'lg-field-icon') ?>
              <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
            </div>
            <div class="lg-row">
              <a href="/portal/forgot-password">Passwort vergessen?</a>
            </div>
            <button type="submit" class="lg-btn">Anmelden <?= icon('arrow-right') ?></button>
          </form>

          <p class="lg-switch">Noch keinen Zugang? <a href="#registrieren" data-flip="register">Registrieren</a></p>
        </section>

        <!-- Rückseite: Registrieren -->
        <section class="lg-card lg-back" id="lg-register" aria-labelledby="lg-register-title">
          <h1 id="lg-register-title">Konto <span>erstellen</span></h1>
          <p class="lg-sub">
            Ihren Zugang zum Portal legt Ihre Energiegemeinschaft an, sobald Ihr Beitritt bestätigt
            ist. Sie bekommen dann eine E-Mail mit einem Link, über den Sie Ihr Passwort festlegen.
          </p>
          <ol class="lg-steps">
            <li><strong>Energiegemeinschaft wählen</strong> und Beitrittserklärung online ausfüllen.</li>
            <li><strong>Bestätigung</strong> durch den Vorstand der Energiegemeinschaft.</li>
            <li><strong>E-Mail mit Zugang</strong> kommt automatisch – Passwort festlegen, fertig.</li>
          </ol>
          <a href="<?= htmlspecialchars($beitretenUrl) ?>" class="lg-btn">Zur Energiegemeinschaft anmelden <?= icon('arrow-right') ?></a>
          <p class="lg-small">Sie betreiben selbst eine Energiegemeinschaft oder haben Fragen zur Plattform?
            <a href="<?= htmlspecialchars($kontaktUrl) ?>">Kontakt zur Plattform</a></p>
          <p class="lg-switch">Schon einen Zugang? <a href="#anmelden" data-flip="login">Anmelden</a></p>
        </section>
      </div>
    </div>

<?php
$authStage = ob_get_clean();
$authScripts = <<<'JS'
<script>
// Karte umdrehen (Anmelden <-> Registrieren), auch per #registrieren in der Adresse aufrufbar.
(function () {
  var stage = document.getElementById('lg-stage');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function show(side, animate) {
    var reg = side === 'register';
    if (stage.classList.contains('is-register') === reg) return;
    if (animate && !reduce) {
      stage.classList.add('is-flipping');
      setTimeout(function () { stage.classList.remove('is-flipping'); }, 900);
    }
    stage.classList.toggle('is-register', reg);
    document.getElementById('lg-login').inert = reg;
    document.getElementById('lg-register').inert = !reg;
    var target = document.querySelector(reg ? '#lg-register .lg-btn' : '#email');
    if (target) setTimeout(function () { target.focus({ preventScroll: true }); }, animate && !reduce ? 700 : 0);
  }
  document.querySelectorAll('[data-flip]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      history.replaceState(null, '', a.getAttribute('href'));
      show(a.dataset.flip, true);
    });
  });
  document.getElementById('lg-register').inert = true;
  if (location.hash === '#registrieren') show('register', false);
})();
</script>
JS;
require __DIR__ . '/../layouts/auth_glass.php';
