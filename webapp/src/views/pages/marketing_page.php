<?php
/**
 * Öffentliche Info-Seite (Inhalte: src/content/marketing_pages.php).
 * @var string $kind 'page' | 'faq' | 'overview'
 * @var string $slug (nur 'page')
 */
$base = AppConfig::get()->marketingBaseUrl();
$faqAll = marketingFaq();
$teasers = marketingFeatureTeasers();
$pages = marketingPages();
$crumbs = [['Startseite', $base . '/']];

if ($kind === 'faq') {
    $pageTitle = 'Häufige Fragen zu EEG-Software | Strom für alle';
    $pageDescription = 'Antworten zu Strom für alle: Kosten, Umstieg, Abrechnung mit EDA-Daten, Echtzeit-Daten vom Smart Meter, Datenschutz und Beitritt in Kärnten.';
    $h1 = 'Häufige Fragen';
    $lead = 'Die wichtigsten Antworten rund um die Plattform für Energiegemeinschaften – für Vorstände, Betreiber und Mitglieder.';
    $faqKeys = array_keys($faqAll);
    $crumbs[] = ['Häufige Fragen', $base . '/faq'];
} elseif ($kind === 'overview') {
    $pageTitle = 'Funktionen der EEG-Plattform | Strom für alle';
    $pageDescription = 'Alle Funktionen für Energiegemeinschaften im Überblick: Abrechnung mit EDA-Daten, Mitgliederverwaltung, Echtzeit-Daten vom Smart Meter, einfacher Umstieg.';
    $h1 = 'Funktionen für Energiegemeinschaften';
    $lead = 'Strom für alle deckt den ganzen Vereinsalltag einer Erneuerbare-Energie-Gemeinschaft ab – vom Beitritt über die Abrechnung bis zur Live-Anzeige.';
    $faqKeys = ['was', 'fuer-wen', 'kosten', 'oesterreich'];
    $crumbs[] = ['Funktionen', $base . '/funktionen'];
} else {
    $p = $pages[$slug];
    $pageTitle = $p['title'];
    $pageDescription = $p['description'];
    $h1 = $p['h1'];
    $lead = $p['lead'];
    $faqKeys = $p['faq'];
    if (isset($teasers[$slug])) {
        $crumbs[] = ['Funktionen', $base . '/funktionen'];
    }
    $crumbs[] = [$p['h1'], $base . '/' . ($slug === 'energiegemeinschaft-feldkirchen' ? $slug : 'funktionen/' . $slug)];
}
$pageCanonical = end($crumbs)[1];

$breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(
    fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1]],
    $crumbs, array_keys($crumbs)
)];
// FAQ-Daten (FAQPage) nur auf /faq: Google will dieselbe Frage nur an EINER Stelle ausgezeichnet sehen;
// die Info-Seiten zeigen dieselben Fragen als normalen Text.
$extraHead = jsonLdScript($breadcrumb) . ($kind === 'faq' ? "\n" . jsonLdScript(marketingFaqJsonLd($faqKeys)) : '');
$featureUrl = fn(string $s): string => $s === 'energiegemeinschaft-feldkirchen' ? '/' . $s : '/funktionen/' . $s;
ob_start();
?>
<section class="info-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Brotkrümel">
      <?php foreach ($crumbs as $i => $c): ?>
        <?php if ($i > 0): ?><span aria-hidden="true">›</span><?php endif; ?>
        <?php if ($i < count($crumbs) - 1): ?><a href="<?= htmlspecialchars($c[1]) ?>"><?= htmlspecialchars($c[0]) ?></a><?php else: ?><span><?= htmlspecialchars($c[0]) ?></span><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <h1><?= htmlspecialchars($h1) ?></h1>
    <p class="info-lead"><?= htmlspecialchars($lead) ?></p>
  </div>
</section>

<div class="container info-body">
  <?php if ($kind === 'overview'): ?>
    <div class="grid-2 info-cards">
      <?php foreach ($teasers as $s => $t): ?>
        <a class="card info-card" href="<?= $featureUrl($s) ?>">
          <div class="feature-icon"><?= icon($t['icon']) ?></div>
          <h2><?= htmlspecialchars($pages[$s]['h1']) ?></h2>
          <p><?= htmlspecialchars($t['text']) ?></p>
          <span class="info-more">Mehr erfahren →</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php elseif ($kind === 'page'): ?>
    <?php foreach ($p['sections'] as $sec): ?>
      <section class="info-section">
        <h2><?= htmlspecialchars($sec['h2']) ?></h2>
        <?php foreach ($sec['p'] ?? [] as $para): ?><p><?= htmlspecialchars($para) ?></p><?php endforeach; ?>
        <?php if (!empty($sec['list'])): ?>
          <ul><?php foreach ($sec['list'] as $li): ?><li><?= htmlspecialchars($li) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($sec['footnote'])): ?><p><small><?= htmlspecialchars($sec['footnote']) ?></small></p><?php endif; ?>
      </section>
    <?php endforeach; ?>
    <?php if (!empty($p['cta'])): ?>
      <p class="info-cta"><a class="btn btn-primary btn-lg" href="<?= htmlspecialchars($p['cta']['href']) ?>"><?= htmlspecialchars($p['cta']['label']) ?></a>
        <a class="btn btn-secondary btn-lg" href="/live">Live-Anzeige</a></p>
    <?php endif; ?>
  <?php endif; ?>

  <section class="info-section info-faq">
    <h2><?= $kind === 'faq' ? 'Alle Fragen' : 'Häufige Fragen' ?></h2>
    <?php foreach ($faqKeys as $k): if (!isset($faqAll[$k])) continue; ?>
      <details class="faq-item">
        <summary><?= htmlspecialchars($faqAll[$k]['q']) ?></summary>
        <p><?= htmlspecialchars($faqAll[$k]['a']) ?></p>
      </details>
    <?php endforeach; ?>
    <?php if ($kind !== 'faq'): ?><p><a href="/faq">Alle häufigen Fragen ansehen →</a></p><?php endif; ?>
  </section>

  <?php if ($kind === 'page' && !empty($p['related'])): ?>
    <section class="info-section">
      <h2>Passend dazu</h2>
      <div class="info-related">
        <?php foreach ($p['related'] as $r): ?>
          <a href="<?= $featureUrl($r) ?>"><?= htmlspecialchars($pages[$r]['h1']) ?> →</a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="card info-contact">
    <h2>Interesse an Strom für alle?</h2>
    <p>Die Kosten hängen vom Umfang ab – nur Verwaltung und Abrechnung oder zusätzlich Echtzeit-Daten mit Ausleseeinheit.
       Schreiben Sie uns, wir melden uns mit einem Angebot.</p>
    <a class="btn btn-primary btn-lg" href="/kontakt">Kontakt aufnehmen</a>
  </section>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
