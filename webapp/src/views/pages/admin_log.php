<?php $pageTitle = 'Aktivitätslog'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:var(--gray-600);text-decoration:none">← Admin</a>
  <h2 style="margin:0">📋 Aktivitätslog</h2>
</div>

<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <form method="get" style="display:flex;gap:.75rem;align-items:center">
    <select name="community_id" onchange="this.form.submit()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Energiegemeinschaften</option>
      <?php foreach ($communities as $c): ?>
        <option value="<?= $c['id'] ?>" <?= ($_GET['community_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <span style="font-size:.8rem;color:var(--gray-600)">Letzte 500 Einträge</span>
  </form>
</div>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Zeitpunkt</th>
        <th>EEG</th>
        <th>Benutzer</th>
        <th>Aktion</th>
        <th>Beschreibung</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
      <tr>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y H:i:s', strtotime($e['created_at'])) ?></td>
        <td style="font-size:.85rem"><?= htmlspecialchars($e['community_name'] ?? '—') ?></td>
        <td style="font-size:.85rem"><?= htmlspecialchars(trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['email'] ?? 'System')) ?></td>
        <td style="font-size:.8rem;color:var(--gray-600)"><?= htmlspecialchars($e['aktion']) ?></td>
        <td style="font-size:.85rem;<?= in_array($e['ist_fehler'], [true, 't', '1', 1], true) ? 'color:#b91c1c' : '' ?>">
          <?= in_array($e['ist_fehler'], [true, 't', '1', 1], true) ? '⚠️ ' : '' ?><?= htmlspecialchars($e['beschreibung']) ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($entries)): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Einträge.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
