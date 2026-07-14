<?php $pageTitle = 'Dateien'; ob_start(); ?>

<div style="display:flex;align-items:center;margin-bottom:1.5rem">
  <h2>📁 Dateien</h2>
</div>

<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <input type="text" id="search-input" placeholder="Mitglied suchen (Name, E-Mail oder Kundennummer)…"
         style="width:100%;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
         oninput="filterFileMembers()" autocomplete="off">
</div>

<div class="card" style="overflow-x:auto">
  <table id="files-member-table">
    <thead>
      <tr>
        <th>KdNr</th>
        <th>Name</th>
        <th>E-Mail</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr data-search="<?= htmlspecialchars(strtolower(trim($m['first_name'] . ' ' . $m['last_name'] . ' ' . ($m['company_name'] ?? '') . ' ' . $m['email'] . ' ' . ($m['kundennummer'] ?? '')))) ?>">
        <td style="font-weight:600;color:#15803d"><?= htmlspecialchars((string)($m['kundennummer'] ?? '—')) ?></td>
        <td>
          <strong><?= htmlspecialchars(trim(($m['company_name'] ?: '') ?: ($m['first_name'] . ' ' . $m['last_name']))) ?></strong>
          <?php if ($m['company_name']): ?>
            <div style="font-size:.8rem;color:#6b7280"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:.85rem"><?= htmlspecialchars($m['email']) ?></td>
        <td><a href="/portal/files/<?= $m['id'] ?>" style="font-size:.85rem">📁 Dateien ansehen</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($members)): ?>
      <tr><td colspan="4" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Mitglieder.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterFileMembers() {
  const q = document.getElementById('search-input').value.toLowerCase().trim();
  document.querySelectorAll('#files-member-table tbody tr[data-search]').forEach(row => {
    row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
  });
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
