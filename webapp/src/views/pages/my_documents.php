<?php $pageTitle = 'Meine Dokumente'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">📄 Meine Dokumente</h2>

<?php if ($hasConsumer || $hasProducer): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Meine Verträge</h3>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <?php if ($hasConsumer): ?>
      <a href="/portal/my/contract/bezug" target="_blank" class="btn" style="background:#1d4ed8;color:#fff">📄 Bezugsvereinbarung ansehen</a>
    <?php endif; ?>
    <?php if ($hasProducer): ?>
      <a href="/portal/my/contract/einspeisung" target="_blank" class="btn" style="background:#b45309;color:#fff">☀️ Einspeisevereinbarung ansehen</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($application)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Beitrittserklärung</h3>
  <a href="/portal/my/documents/formular" target="_blank" class="btn" style="background:#f3f4f6;color:#374151">🖨️ Beitrittserklärung ansehen (PDF)</a>
</div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-bottom:1rem">📎 Meine Dateien</h3>
  <?php if (empty($member_files)): ?>
    <p style="color:#6b7280;font-size:.875rem">Es liegen noch keine Dateien vor (z. B. Beitrittserklärung, Ausweis-Scan).</p>
  <?php else: ?>
    <table style="font-size:.85rem">
      <thead>
        <tr><th>Name</th><th>Hochgeladen am</th><th>Aktion</th></tr>
      </thead>
      <tbody>
      <?php foreach ($member_files as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
          <td><a href="/portal/my/documents/<?= $f['id'] ?>/download">Herunterladen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
