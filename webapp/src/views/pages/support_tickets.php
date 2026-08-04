<?php $pageTitle = 'Support-Tickets'; ob_start(); ?>

<h2 style="margin-bottom:.5rem"><?= icon('chat-circle-text') ?> Support-Tickets</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
  Probleme und Feature-Vorschläge, die Mitglieder über „Support" im Mitgliederportal gemeldet haben.
</p>

<div style="display:flex;gap:.5rem;margin-bottom:1rem">
  <?php $statusFilter = $_GET['status'] ?? ''; ?>
  <a href="/portal/support" class="btn <?= $statusFilter === '' ? 'btn-primary' : '' ?>" style="background:<?= $statusFilter === '' ? '' : 'var(--gray-100)' ?>;color:<?= $statusFilter === '' ? '' : 'var(--gray-700)' ?>;font-size:.82rem">Alle</a>
  <a href="/portal/support?status=offen" class="btn <?= $statusFilter === 'offen' ? 'btn-primary' : '' ?>" style="background:<?= $statusFilter === 'offen' ? '' : 'var(--gray-100)' ?>;color:<?= $statusFilter === 'offen' ? '' : 'var(--gray-700)' ?>;font-size:.82rem">Offen</a>
  <a href="/portal/support?status=in_bearbeitung" class="btn <?= $statusFilter === 'in_bearbeitung' ? 'btn-primary' : '' ?>" style="background:<?= $statusFilter === 'in_bearbeitung' ? '' : 'var(--gray-100)' ?>;color:<?= $statusFilter === 'in_bearbeitung' ? '' : 'var(--gray-700)' ?>;font-size:.82rem">In Bearbeitung</a>
  <a href="/portal/support?status=geschlossen" class="btn <?= $statusFilter === 'geschlossen' ? 'btn-primary' : '' ?>" style="background:<?= $statusFilter === 'geschlossen' ? '' : 'var(--gray-100)' ?>;color:<?= $statusFilter === 'geschlossen' ? '' : 'var(--gray-700)' ?>;font-size:.82rem">Geschlossen</a>
</div>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Betreff</th>
        <th>Mitglied</th>
        <th>Art</th>
        <th>Status</th>
        <th>Zuletzt aktualisiert</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr style="cursor:pointer" onclick="location.href='/portal/support/<?= $t['id'] ?>'">
        <td>
          <?php if (in_array($t['hat_ungelesen'] ?? false, [true, 't', '1', 1], true)): ?>
            <span title="Ungelesene Nachricht" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:.5rem"></span>
          <?php endif; ?>
          <?= htmlspecialchars($t['subject']) ?>
        </td>
        <td><?= htmlspecialchars(trim($t['first_name'] . ' ' . $t['last_name'])) ?></td>
        <td><?= $t['category'] === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage' ?></td>
        <td>
          <?php if ($t['status'] === 'offen'): ?>
            <span class="badge badge-yellow">Offen</span>
          <?php elseif ($t['status'] === 'in_bearbeitung'): ?>
            <span class="badge badge-yellow">In Bearbeitung</span>
          <?php else: ?>
            <span class="badge badge-green">Geschlossen</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($t['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($tickets)): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--gray-600);padding:2rem">Keine Tickets.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
