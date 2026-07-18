<?php $pageTitle = 'Neuanmeldungen'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">📥 Online-Beitrittserklärungen</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php endif; ?>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Eingegangen</th>
        <th>Name</th>
        <th>E-Mail</th>
        <th>Bezug</th>
        <th>Einspeisung</th>
        <th>Status</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($applications as $a): ?>
      <tr>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
        <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></td>
        <td style="font-size:.85rem"><?= htmlspecialchars($a['email']) ?></td>
        <td style="text-align:center"><?= in_array($a['bezug_gewuenscht'], [true, 't', '1', 1], true) ? '✓' : '—' ?></td>
        <td style="text-align:center"><?= in_array($a['einspeisung_gewuenscht'], [true, 't', '1', 1], true) ? '✓' : '—' ?></td>
        <td>
          <?php $sb = ['pending' => 'yellow', 'approved' => 'green', 'rejected' => 'gray']; ?>
          <span class="badge badge-<?= $sb[$a['status']] ?? 'gray' ?>"><?= htmlspecialchars($a['status']) ?></span>
        </td>
        <td><a href="/portal/applications/<?= $a['id'] ?>" style="font-size:.8rem">Ansehen</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($applications)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Beitrittserklärungen eingegangen.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
