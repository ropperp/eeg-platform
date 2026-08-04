<?php $pageTitle = 'Support: ' . $ticket['subject']; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:.5rem">
  <a href="/portal/my/support" style="color:var(--gray-600);text-decoration:none">← Support</a>
</div>
<h2 style="margin-bottom:.25rem"><?= icon('chat-circle-text') ?> <?= htmlspecialchars($ticket['subject']) ?></h2>
<p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1.5rem">
  <?= $ticket['category'] === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage' ?> ·
  <?php if ($ticket['status'] === 'offen'): ?>
    <span class="badge badge-yellow">Offen</span>
  <?php elseif ($ticket['status'] === 'in_bearbeitung'): ?>
    <span class="badge badge-yellow">In Bearbeitung</span>
  <?php else: ?>
    <span class="badge badge-green">Geschlossen</span>
  <?php endif; ?>
  · erstellt <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
</p>

<div class="card" style="margin-bottom:1.5rem;max-width:44rem">
  <?php foreach ($messages as $m): ?>
    <div class="<?= $m['is_staff'] ? '' : 'msg-bubble-member' ?>" style="margin-bottom:1rem;padding:.75rem 1rem;border-radius:8px;background:<?= $m['is_staff'] ? 'var(--gray-100)' : '' ?>">
      <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem">
        <strong><?= htmlspecialchars($m['author_label']) ?></strong><?= $m['is_staff'] ? ' (Verwaltung)' : '' ?> ·
        <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
      </div>
      <div style="white-space:pre-wrap"><?= htmlspecialchars($m['message']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="max-width:44rem">
  <h3 style="margin-bottom:1rem">Antworten</h3>
  <form method="post" action="/portal/my/support/<?= $ticket['id'] ?>/reply">
    <div class="form-group">
      <textarea name="message" required rows="4" placeholder="Deine Antwort..."></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Absenden</button>
  </form>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
