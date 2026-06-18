<?php $pageTitle = 'Mitglieder'; ob_start(); ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <h2>👥 Mitglieder</h2>
  <a href="/portal/members/new" class="btn btn-primary">+ Mitglied anlegen</a>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Mitglied wurde gespeichert.</div>
<?php endif; ?>

<?php if (!empty($successTempPw)): ?>
  <div class="card" style="margin-bottom:1.5rem;border:2px solid #16a34a">
    <h3 style="color:#15803d;margin-bottom:.75rem">✅ Mitglied angelegt — Login-Daten</h3>
    <p style="margin-bottom:.5rem">Bitte teilen Sie dem Mitglied folgende Zugangsdaten mit:</p>
    <table>
      <tr><th>E-Mail</th><td><code><?= htmlspecialchars($successEmail) ?></code></td></tr>
      <tr><th>Temporäres Passwort</th><td><code style="font-size:1.1rem;color:#15803d"><?= htmlspecialchars($successTempPw) ?></code></td></tr>
    </table>
    <p style="margin-top:.75rem;font-size:.8rem;color:#6b7280">Das Mitglied sollte das Passwort nach dem ersten Login ändern. Diese Anzeige erscheint nur einmal.</p>
  </div>
<?php endif; ?>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <input type="text" id="search-input" placeholder="Name oder E-Mail suchen…"
           style="flex:1;min-width:200px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterMembers()">
    <select id="filter-status" onchange="filterMembers()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Status</option>
      <option value="active">Aktiv</option>
      <option value="pending">Ausstehend</option>
      <option value="inactive">Inaktiv</option>
    </select>
    <span id="result-count" style="font-size:.8rem;color:#6b7280"></span>
  </div>
</div>

<div class="card">
  <table id="member-table">
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
      <tr data-name="<?= htmlspecialchars(strtolower($m['first_name'] . ' ' . $m['last_name'] . ' ' . ($m['company_name'] ?? ''))) ?>"
          data-email="<?= htmlspecialchars(strtolower($m['email'])) ?>"
          data-status="<?= htmlspecialchars($m['status']) ?>">
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
          &nbsp;·&nbsp;
          <a href="/portal/members/<?= $m['id'] ?>/edit" style="font-size:.8rem;color:#6b7280">Bearbeiten</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($members)): ?>
      <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Mitglieder.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterMembers() {
  const q = document.getElementById('search-input').value.toLowerCase();
  const s = document.getElementById('filter-status').value;
  const rows = document.querySelectorAll('#member-table tbody tr[data-name]');
  let visible = 0;
  rows.forEach(row => {
    const nameMatch = !q || row.dataset.name.includes(q) || row.dataset.email.includes(q);
    const statusMatch = !s || row.dataset.status === s;
    const show = nameMatch && statusMatch;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('result-count').textContent = visible + ' Mitglied' + (visible !== 1 ? 'er' : '') + ' gefunden';
}
filterMembers();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
