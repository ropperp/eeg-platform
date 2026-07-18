<?php $pageTitle = 'Postfach'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">📬 Postfach</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php endif; ?>

<?php
$refLinks = [
  'membership_application' => '/portal/applications/',
  'member'                 => '/portal/members/',
];
?>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Eingegangen</th>
        <th>Typ</th>
        <th>Titel</th>
        <th>Text</th>
        <th>Status</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($notifications as $n): ?>
      <tr>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></td>
        <td style="font-size:.8rem;color:var(--gray-600)"><?= htmlspecialchars($n['typ']) ?></td>
        <td>
          <?php if (!empty($n['referenz_typ']) && !empty($refLinks[$n['referenz_typ']])): ?>
            <a href="<?= $refLinks[$n['referenz_typ']] . $n['referenz_id'] ?>"><?= htmlspecialchars($n['titel']) ?></a>
          <?php else: ?>
            <?= htmlspecialchars($n['titel']) ?>
          <?php endif; ?>
        </td>
        <td style="font-size:.85rem;color:var(--gray-600)"><?= htmlspecialchars($n['text'] ?? '') ?></td>
        <td>
          <span class="badge badge-<?= $n['status'] === 'offen' ? 'yellow' : 'gray' ?>"><?= htmlspecialchars($n['status']) ?></span>
        </td>
        <td>
          <?php if ($n['status'] === 'offen'): ?>
            <form method="post" action="/portal/postfach/<?= $n['id'] ?>/erledigt">
              <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem;padding:.3rem .6rem">Als erledigt markieren</button>
            </form>
          <?php else: ?>
            <span style="font-size:.8rem;color:var(--gray-600)">
              erledigt <?= $n['erledigt_am'] ? date('d.m.Y H:i', strtotime($n['erledigt_am'])) : '' ?>
            </span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--gray-600);padding:2rem">Keine Benachrichtigungen.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
