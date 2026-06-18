<?php $pageTitle = 'Mitglieder'; ob_start(); ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <h2>👥 Mitglieder</h2>
  <a href="/portal/members/new" class="btn btn-primary">+ Mitglied anlegen</a>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>E-Mail</th>
        <th>Zählpunkte</th>
        <th>Mitglied seit</th>
        <th>Status</th>
        <th>Aktionen</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td>
          <?= htmlspecialchars(trim(($m['company_name'] ?: '') ?: ($m['first_name'] . ' ' . $m['last_name']))) ?>
        </td>
        <td><?= htmlspecialchars($m['email']) ?></td>
        <td><?= $m['metering_point_count'] ?></td>
        <td><?= $m['member_since'] ? date('d.m.Y', strtotime($m['member_since'])) : '—' ?></td>
        <td>
          <?php $badge = ['active' => 'green', 'pending' => 'yellow', 'inactive' => 'gray']; ?>
          <span class="badge badge-<?= $badge[$m['status']] ?? 'gray' ?>">
            <?= htmlspecialchars($m['status']) ?>
          </span>
        </td>
        <td>
          <a href="/portal/members/<?= $m['id'] ?>" style="font-size:.8rem">Details</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($members)): ?>
      <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Mitglieder.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
