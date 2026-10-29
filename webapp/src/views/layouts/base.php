<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Strom für alle') ?></title>
  <?php
  // SEO/Social-Meta: jede Seite kann $pageDescription/$pageCanonical/$pageOgImage/$pageNoindex
  // VOR ob_start() setzen (gleiches Muster wie $pageTitle) -- hier nur sinnvolle Fallbacks, damit
  // keine Seite diese Tags komplett vergisst. $pageCanonical ohne Query-String (z.B. ?success=...
  // nach einem Formular-Submit soll nicht als eigene, separat indexierbare URL gelten).
  $pageDescription = $pageDescription ?? 'Strom für alle -- die Plattform für Erneuerbare-Energie-Gemeinschaften (EEG) in Österreich: Mitgliederverwaltung, automatisierte Abrechnung und Echtzeit-Energiedaten.';
  $pageCanonical = $pageCanonical ?? ('https://stromfueralle.at' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
  $pageOgImage = $pageOgImage ?? 'https://stromfueralle.at/assets/images/og-image.png';
  ?>
  <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($pageCanonical) ?>">
  <?php if (!empty($pageNoindex)): ?>
  <meta name="robots" content="noindex, nofollow">
  <?php endif; ?>
  <!-- Open Graph / Twitter Card -- Vorschau, wenn ein Link zu dieser Seite verschickt wird
       (WhatsApp, Slack, Facebook, Twitter/X, ...). -->
  <meta property="og:site_name" content="Strom für alle">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="de_AT">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? 'Strom für alle') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDescription) ?>">
  <meta property="og:url" content="<?= htmlspecialchars($pageCanonical) ?>">
  <meta property="og:image" content="<?= htmlspecialchars($pageOgImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle ?? 'Strom für alle') ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($pageOgImage) ?>">
  <!-- Favicon: aus dem Blitz-Icon-Teil des Logos ausgeschnitten (webapp/public/assets/images/
       logo.png), siehe CHANGELOG.md. favicon.ico bündelt 16/32px als PNG-in-ICO (von jedem
       modernen Browser unterstützt, seit Windows Vista Standard). -->
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
  <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(ROOT . '/public/assets/css/app.css') ?: time() ?>">
  <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.setAttribute('data-theme','dark');})()</script>
  <!-- Markiert synchron (vor dem ersten Malen), dass JS verfügbar ist -- app.css nutzt das,
       um z.B. den Hero-Text erst dann per CSS auszublenden, wenn tatsächlich eine Animation
       (site-animations.js, unten) übernehmen wird. Ohne JS bleibt alles normal sichtbar. -->
  <script>document.documentElement.classList.add('js-anim');</script>
  <?php if (!empty($extraHead)): ?>
  <?= $extraHead ?>
  <?php endif; ?>
</head>
<body>

<header class="navbar">
  <div class="container inner">
    <a href="<?= htmlspecialchars(marketingUrl('/')) ?>" class="logo">
      <img src="<?= htmlspecialchars(logoAssetUrl('light')) ?>" alt="Strom für alle" class="logo-img logo-img-light">
      <img src="<?= htmlspecialchars(logoAssetUrl('dark')) ?>" alt="Strom für alle" class="logo-img logo-img-dark">
    </a>
    <nav>
      <button id="theme-toggle" onclick="toggleDark()" title="Hell/Dunkel umschalten" class="theme-toggle-btn">
        <?= icon('moon', 'theme-icon-moon') ?><?= icon('sun', 'theme-icon-sun') ?>
      </button>
      <a href="<?= htmlspecialchars(marketingUrl('/live')) ?>">Live-Anzeige</a>
      <a href="<?= htmlspecialchars(marketingUrl('/rc108175/kontakt')) ?>">Kontakt</a>
      <?php if (Auth::check()): ?>
        <a href="<?= htmlspecialchars(portalUrl('/portal/dashboard')) ?>">Portal</a>
        <?php if (Auth::isPlatformAdmin()): ?>
          <a href="<?= htmlspecialchars(portalUrl('/admin')) ?>">Admin</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(portalUrl('/portal/logout')) ?>">Abmelden (<?= htmlspecialchars(Auth::userName()) ?>)</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars(marketingUrl('/beitreten')) ?>" class="btn btn-secondary" style="padding:.4rem .9rem">Informieren und Beitreten</a>
        <a href="<?= htmlspecialchars(portalUrl('/portal/login')) ?>" class="btn btn-primary" style="padding:.4rem .9rem">Anmelden</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main><?= $content ?></main>

<footer>
  <div class="container">
    <div style="margin-bottom:.75rem">
      <a href="/rc108175/impressum">Impressum</a> ·
      <a href="/rc108175/statuten">Statuten</a> ·
      <a href="/rc108175/datenschutz">Datenschutz</a> ·
      <a href="/rc108175/agb">AGBs</a> ·
      <a href="/rc108175/widerruf">Widerrufsbelehrung</a> ·
      <a href="/rc108175/barrierefreiheit">Barrierefreiheit</a> ·
      <a href="/rc108175/preisliste">Preisliste</a> ·
      <a href="/rc108175/kontakt">Kontakt</a> ·
      <a href="https://kaerntennetz.at/erneuerbare-energiegemeinschaften-eeg.htm" target="_blank" rel="noopener">Kärnten Netz: Netzgebiet prüfen</a>
    </div>
    Strom für alle · Diplomarbeit HTL Kärnten 2026/27 · Patrick Ropper, Fabian Amlacher, Alexander Brunner
  </div>
</footer>

<script src="/assets/js/vendor/gsap.min.js"></script>
<script src="/assets/js/vendor/ScrollTrigger.min.js"></script>
<script src="/assets/js/site-animations.js"></script>
<script src="/assets/js/password-toggle.js?v=<?= @filemtime(ROOT . '/public/assets/js/password-toggle.js') ?: time() ?>"></script>
<script src="/assets/js/signature-pad-trim.js?v=<?= @filemtime(ROOT . '/public/assets/js/signature-pad-trim.js') ?: time() ?>"></script>
<script>
// CSRF-Schutz (OWASP-Audit 13.08.2026): fügt automatisch ein verstecktes Token-Feld in jedes
// method="post"-Formular der Seite ein, statt jede der ~70 Formular-Views einzeln anzufassen.
// Geprüft wird zentral in Router::dispatch() (siehe functions.php: csrfToken()/csrfValid()).
(function () {
  var token = <?= json_encode(csrfToken()) ?>;
  document.querySelectorAll('form[method="post" i]').forEach(function (form) {
    if (form.querySelector('input[name="csrf_token"]')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = token;
    form.appendChild(input);
  });
})();
function toggleDark() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('darkMode', next === 'dark' ? '1' : '0');
  // Icon-Umschaltung (Mond/Sonne) läuft rein über CSS anhand [data-theme], siehe app.css --
  // kein Textaustausch mehr nötig.
}

// Scroll-Reveal: Elemente mit .reveal/.reveal-grid blenden beim Scrollen sanft ein
// (siehe app.css). Läuft auf jeder Seite, die dieses Layout nutzt, ist aber ein reines
// No-Op, solange keine solchen Elemente vorkommen -- aktuell nur auf der Startseite genutzt.
(function () {
  var targets = document.querySelectorAll('.reveal, .reveal-grid');
  if (!targets.length) return;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion || !('IntersectionObserver' in window)) {
    targets.forEach(function (el) { el.classList.add('is-visible'); });
    return;
  }
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });
  targets.forEach(function (el) { observer.observe(el); });
})();
</script>
</body>
</html>
