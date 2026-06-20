<?php
$pageTitle = 'Postfach';
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <h2 style="margin:0">📬 Postfach</h2>
  <?php if ($unreadCount > 0): ?>
    <span class="badge badge-green"><?= $unreadCount ?> ungelesen</span>
  <?php endif; ?>
  <?php if (!empty($notifications)): ?>
    <form method="post" action="/portal/postfach/mark-all-read" style="margin-left:auto">
      <button class="btn" style="background:#f3f4f6;color:#374151;font-size:.8rem">
        ✓ Alle als gelesen markieren
      </button>
    </form>
  <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
  <div class="card" style="text-align:center;padding:3rem;color:#6b7280">
    <div style="font-size:2.5rem;margin-bottom:.75rem">📭</div>
    <p style="margin:0">Keine Nachrichten vorhanden.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php foreach ($notifications as $n): ?>
      <?php $unread = !((bool)($n['is_read'])); ?>
      <div class="card" style="padding:1rem 1.25rem;<?= $unread ? 'border-left:3px solid #2563eb;' : 'opacity:.8;' ?>">
        <div style="display:flex;align-items:flex-start;gap:1rem">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.25rem">
              <?php if ($unread): ?>
                <span style="width:8px;height:8px;border-radius:50%;background:#2563eb;flex-shrink:0"></span>
              <?php endif; ?>
              <strong style="font-size:.95rem"><?= htmlspecialchars($n['title']) ?></strong>
              <span style="font-size:.75rem;color:#9ca3af;margin-left:auto;white-space:nowrap">
                <?= date('d.m.Y H:i', strtotime($n['created_at'])) ?>
              </span>
            </div>
            <?php if ($n['body']): ?>
              <p style="margin:.25rem 0 0;font-size:.875rem;color:#374151"><?= nl2br(htmlspecialchars($n['body'])) ?></p>
            <?php endif; ?>
            <div style="margin-top:.4rem">
              <span style="font-size:.75rem;color:#9ca3af;background:#f3f4f6;padding:.15rem .5rem;border-radius:4px">
                <?= htmlspecialchars($n['type']) ?>
              </span>
            </div>
          </div>
          <?php if ($unread): ?>
            <form method="post" action="/portal/postfach/mark-read" style="flex-shrink:0">
              <input type="hidden" name="id" value="<?= htmlspecialchars($n['id']) ?>">
              <button class="btn" style="padding:.2rem .6rem;font-size:.75rem;background:#f3f4f6;color:#374151">
                Als gelesen
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
