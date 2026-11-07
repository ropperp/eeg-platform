<?php
// Reiterleiste für lange Einstellungsseiten (Patrick, 08.11.2026: "nach der Art ... wo man hin
// und her springen kann ... mit einem schönen Animationsdings"). Erwartet:
//   $tabsKey = eindeutiger Name der Seite (merkt sich den zuletzt offenen Reiter)
//   $tabs    = ['id' => ['label' => '...', 'icon' => 'phosphor-name'], ...]
// Zu jedem Reiter gehört ein <section class="tab-panel" id="tab-<id>" role="tabpanel"
// aria-labelledby="tabbtn-<id>">; der erste ist aktiv, alle weiteren tragen "hidden".
// Das Umschalten übernimmt /assets/js/tabs.js (am Seitenende einbinden).
$firstTab = array_key_first($tabs);
?>
<div class="tabs" role="tablist" data-tabs="<?= htmlspecialchars($tabsKey) ?>" aria-label="Bereiche">
  <?php foreach ($tabs as $tabId => $tab): ?>
    <button type="button" class="tab-btn" role="tab" id="tabbtn-<?= htmlspecialchars($tabId) ?>"
            data-tab="<?= htmlspecialchars($tabId) ?>" aria-controls="tab-<?= htmlspecialchars($tabId) ?>"
            aria-selected="<?= $tabId === $firstTab ? 'true' : 'false' ?>" tabindex="<?= $tabId === $firstTab ? '0' : '-1' ?>">
      <?php if (!empty($tab['icon'])): ?><?= icon($tab['icon']) ?><?php endif; ?>
      <span><?= htmlspecialchars($tab['label']) ?></span>
    </button>
  <?php endforeach; ?>
  <span class="tab-indicator" aria-hidden="true"></span>
</div>
