<?php
// Generische Dokument-Adresse ohne RC-Nummer (z.B. stromfueralle.at/datenschutz): Auswahlseite
// aller aktiven EEGs, ein Klick führt auf /rc<RC>/<dokument>. Bewusst KEINE automatische
// Weiterleitung, auch wenn es nur eine EEG gibt -- es kommen weitere dazu, und jede hat ihre
// eigenen Rechtsdokumente (Patrick, 07.11.2026).
$pageTitle = $docTitle . ' — bitte Energiegemeinschaft wählen';
$pageDescription = $docTitle . ' der Energiegemeinschaften auf Strom für alle -- wählen Sie Ihre Energiegemeinschaft.';
ob_start();
?>

<div class="legal">
  <h1><?= htmlspecialchars($docTitle) ?> &ndash; bitte Energiegemeinschaft wählen</h1>
  <p>
    Jede Energiegemeinschaft auf Strom für alle hat ihre eigenen Rechtsdokumente. Bitte wählen
    Sie die Energiegemeinschaft, deren <?= htmlspecialchars($docTitle) ?> Sie lesen möchten.
  </p>

  <?php require __DIR__ . '/../partials/community_search.php'; ?>

  <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
    <?php foreach ($communities as $c): ?>
      <?php $rc = strtolower($c['marktpartner_id'] ?? ''); ?>
      <div class="card community-card" data-search="<?= htmlspecialchars(mb_strtolower($c['name'] . ' ' . ($c['address'] ?? ''))) ?>"
           style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
        <div>
          <strong style="font-size:1.05rem"><?= htmlspecialchars($c['name']) ?></strong>
          <?php if (!empty($c['address'])): ?>
            <div style="font-size:.85rem;color:var(--gray-600)"><?= htmlspecialchars($c['address']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (in_array($rc, communitiesWithLegalPages(), true)): ?>
          <a href="/<?= htmlspecialchars($rc) ?>/<?= htmlspecialchars($docSlug) ?>" class="btn btn-primary">
            <?= htmlspecialchars($docTitle) ?> öffnen
          </a>
        <?php else: ?>
          <span style="font-size:.85rem;color:var(--gray-600)">Unterlagen folgen in Kürze</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($communities)): ?>
      <p style="color:var(--gray-600)">Aktuell ist keine Energiegemeinschaft verfügbar.</p>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
