<?php
$pageTitle = 'Informieren und Beitreten — Strom für alle';
$pageDescription = 'Informieren Sie sich über Energiegemeinschaften auf Strom für alle und werden Sie online Mitglied.';
ob_start();
?>

<div class="legal">
  <h1>Informieren und Beitreten</h1>
  <p>
    Wählen Sie die Energiegemeinschaft, über die Sie sich informieren oder der Sie beitreten
    möchten. Anschließend finden Sie dort alle Unterlagen (Statuten, Datenschutz, AGBs,
    Preisliste) sowie die Möglichkeit, die Beitrittserklärung direkt online auszufüllen und zu
    unterschreiben.
  </p>
  <p class="alert" style="background:var(--green-light);font-size:.9rem">
    <?= icon('info') ?> Mitglieder erhalten eine <strong>Ausleseeinheit für den Smart Meter</strong>.
    Ihr Betrieb ist laut AGB (§ 7) verpflichtender Teil der Teilnahme; voraussichtlicher Preis
    ca. € 30,–. Die Ausleseeinheit befindet sich derzeit in Entwicklung.
    <a href="/agb">Zu den AGB</a>
  </p>

  <?php require __DIR__ . '/../partials/community_search.php'; ?>

  <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
    <?php foreach ($communities as $c): ?>
      <?php $hasLegalPages = in_array(strtolower($c['marktpartner_id'] ?? ''), $communitiesWithLegalPages, true); ?>
      <div class="card community-card" data-search="<?= htmlspecialchars(mb_strtolower($c['name'] . ' ' . ($c['address'] ?? ''))) ?>"
           style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
        <div>
          <strong style="font-size:1.05rem"><?= htmlspecialchars($c['name']) ?></strong>
          <?php if (!empty($c['address'])): ?>
            <div style="font-size:.85rem;color:var(--gray-600)"><?= htmlspecialchars($c['address']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($hasLegalPages): ?>
          <a href="/<?= htmlspecialchars(strtolower($c['marktpartner_id'])) ?>/beitreten" class="btn btn-primary">
            Informieren &amp; Beitreten
          </a>
        <?php else: ?>
          <span style="font-size:.85rem;color:var(--gray-600)">Informationen folgen in Kürze</span>
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
