<?php
$pageTitle = 'Informieren und Beitreten';
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

  <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1.5rem">
    <?php foreach ($communities as $c): ?>
      <?php $hasLegalPages = in_array(strtolower($c['marktpartner_id'] ?? ''), $communitiesWithLegalPages, true); ?>
      <div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
        <div>
          <strong style="font-size:1.05rem"><?= htmlspecialchars($c['name']) ?></strong>
          <?php if (!empty($c['address'])): ?>
            <div style="font-size:.85rem;color:#6b7280"><?= htmlspecialchars($c['address']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($hasLegalPages): ?>
          <a href="/<?= htmlspecialchars(strtolower($c['marktpartner_id'])) ?>/beitreten" class="btn btn-primary">
            Informieren &amp; Beitreten
          </a>
        <?php else: ?>
          <span style="font-size:.85rem;color:#9ca3af">Informationen folgen in Kürze</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($communities)): ?>
      <p style="color:#6b7280">Aktuell ist keine Energiegemeinschaft verfügbar.</p>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
