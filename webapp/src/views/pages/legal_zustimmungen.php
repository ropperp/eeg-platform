<?php
$pageTitle = 'Zustimmungen zur Beitrittserklärung — ' . $community['name'];
$pageDescription = 'Vollständiger Wortlaut der rechtlichen Zustimmungen der Beitrittserklärung von ' . $community['name'] . ', inklusive früherer Fassungen.';
$rc = strtolower($community['marktpartner_id']);
$current = consentCurrentVersionFor($community);
ob_start();
?>

<div class="legal">
  <h1>Zustimmungen zur Beitrittserklärung</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?></div>

  <p>
    Vollständiger Wortlaut der sechs rechtlichen Zustimmungen, die mit der Beitrittserklärung
    erteilt werden. Jede Beitrittserklärung speichert, welche Fassung akzeptiert wurde; frühere
    Fassungen bleiben deshalb hier zum Nachlesen abrufbar.
  </p>

  <?php foreach (consentVersions() as $version => $v): ?>
    <?php if (!empty($v['community_id']) && $v['community_id'] !== $community['id']) continue; ?>
    <?php // Plattform-Fassung für den jeweils anderen Typ (mit/ohne Ausleseeinheit) betrifft diese EEG nicht. ?>
    <?php if ($version !== $current && $version === (communityUsesAusleseeinheit($community) ? 'v2-ohne-ae' : 'v2')) continue; ?>
    <?php $isCurrent = $version === $current; ?>
    <?php if (!$isCurrent): ?><details style="margin-top:1.5rem"><summary style="cursor:pointer;font-weight:600"><?php endif; ?>
    <?php if ($isCurrent): ?><h2><?php endif; ?>
      <?= htmlspecialchars($v['bezeichnung']) ?> &middot; Version <?= htmlspecialchars($version) ?>
      &middot; <?= $isCurrent ? 'gültig ab' : 'Fassung vom' ?> <?= consentValidFromDisplay($version) ?>
    <?= $isCurrent ? '</h2>' : '</summary>' ?>
    <dl>
      <?php foreach (consentFields() as $field => $title): ?>
        <dt style="font-weight:600;margin-top:1rem"><?= htmlspecialchars($title) ?></dt>
        <dd style="margin:.25rem 0 0 0"><?= consentHtml(consentText($version, $field, 'lang', $community)) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php if (!$isCurrent): ?></details><?php endif; ?>
  <?php endforeach; ?>

  <p style="margin-top:2rem">
    Siehe auch:
    <a href="/<?= htmlspecialchars($rc) ?>/statuten">Statuten</a> &middot;
    <a href="/<?= htmlspecialchars($rc) ?>/agb">AGB</a> &middot;
    <a href="/<?= htmlspecialchars($rc) ?>/preisliste">Preisliste</a> &middot;
    <a href="/<?= htmlspecialchars($rc) ?>/datenschutz">Datenschutzerklärung</a> &middot;
    <a href="/<?= htmlspecialchars($rc) ?>/widerruf">Widerrufsbelehrung</a>
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
