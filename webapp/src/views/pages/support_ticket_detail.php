<?php $pageTitle = 'Support: ' . $ticket['subject']; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:.5rem">
  <a href="/portal/support" style="color:var(--gray-600);text-decoration:none">← Support-Tickets</a>
</div>
<h2 style="margin-bottom:.25rem"><?= icon('chat-circle-text') ?> <?= htmlspecialchars($ticket['subject']) ?></h2>
<p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1.5rem">
  von <?= htmlspecialchars(trim($ticket['first_name'] . ' ' . $ticket['last_name'])) ?>
  (<?= htmlspecialchars($ticket['email'] ?? '') ?>) ·
  <?= $ticket['category'] === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage' ?> ·
  erstellt <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
</p>

<div class="card" style="margin-bottom:1.5rem;max-width:44rem">
  <?php foreach ($messages as $m): ?>
    <div style="margin-bottom:1rem;padding:.75rem 1rem;border-radius:8px;background:<?= $m['is_staff'] ? 'var(--gray-100)' : '#eff6ff' ?>">
      <div style="font-size:.78rem;color:var(--gray-600);margin-bottom:.35rem">
        <strong><?= htmlspecialchars($m['author_label']) ?></strong><?= $m['is_staff'] ? ' (Verwaltung)' : '' ?> ·
        <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
      </div>
      <div style="white-space:pre-wrap"><?= htmlspecialchars($m['message']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="max-width:44rem">
  <h3 style="margin-bottom:1rem">Antworten &amp; Status</h3>
  <form method="post" action="/portal/support/<?= $ticket['id'] ?>/reply">
    <div class="form-group">
      <textarea name="message" rows="4" placeholder="Antwort an das Mitglied (optional, z. B. nur Status ändern)"></textarea>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select name="status">
        <option value="offen" <?= $ticket['status'] === 'offen' ? 'selected' : '' ?>>Offen</option>
        <option value="in_bearbeitung" <?= $ticket['status'] === 'in_bearbeitung' ? 'selected' : '' ?>>In Bearbeitung</option>
        <option value="geschlossen" <?= $ticket['status'] === 'geschlossen' ? 'selected' : '' ?>>Geschlossen</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
