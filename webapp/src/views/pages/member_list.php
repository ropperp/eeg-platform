<?php $pageTitle = 'Mitglieder'; ob_start();
$statusBadge = ['none' => 'gray', 'created' => 'yellow', 'signed' => 'green'];
$statusLabel = ['none' => '—', 'created' => 'Erstellt', 'signed' => '✓ Unterschr.'];
?>

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
           style="flex:1;min-width:180px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterMembers()">
    <select id="filter-status" onchange="filterMembers()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Status</option>
      <option value="active">Aktiv</option>
      <option value="pending">Ausstehend</option>
      <option value="inactive">Inaktiv</option>
    </select>
    <select id="filter-contract" onchange="filterMembers()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Verträge</option>
      <option value="none">Keine Verträge</option>
      <option value="open">Vertrag ausstehend</option>
      <option value="signed">Alles unterschrieben</option>
    </select>
    <span id="result-count" style="font-size:.8rem;color:#6b7280"></span>
  </div>
</div>

<div class="card" style="overflow-x:auto">
  <table id="member-table" style="min-width:900px">
    <thead>
      <tr>
        <th>Name</th>
        <th>E-Mail</th>
        <th>Mitglied seit</th>
        <th>Status</th>
        <th style="text-align:center">Bezugsvertr.</th>
        <th style="text-align:center">Einspeisevertr.</th>
        <th style="text-align:right">Offener Betrag</th>
        <th>Aktionen</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($members as $m):
      $bezug = $m['contract_bezug_status'] ?? 'none';
      $einsp = $m['contract_einspeisung_status'] ?? 'none';
      $allSigned = ($bezug === 'signed' && $einsp === 'signed') ? 'signed' : ($bezug === 'none' && $einsp === 'none' ? 'none' : 'open');
    ?>
      <tr data-name="<?= htmlspecialchars(strtolower($m['first_name'] . ' ' . $m['last_name'] . ' ' . ($m['company_name'] ?? ''))) ?>"
          data-email="<?= htmlspecialchars(strtolower($m['email'])) ?>"
          data-status="<?= htmlspecialchars($m['status']) ?>"
          data-contract="<?= $allSigned ?>">
        <td>
          <strong><?= htmlspecialchars(trim(($m['company_name'] ?: '') ?: ($m['first_name'] . ' ' . $m['last_name']))) ?></strong>
          <?php if ($m['company_name']): ?>
            <div style="font-size:.8rem;color:#6b7280"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:.85rem"><?= htmlspecialchars($m['email']) ?></td>
        <td style="font-size:.85rem;white-space:nowrap"><?= $m['member_since'] ? date('d.m.Y', strtotime($m['member_since'])) : '—' ?></td>
        <td>
          <?php $sb = ['active' => 'green', 'pending' => 'yellow', 'inactive' => 'gray']; ?>
          <span class="badge badge-<?= $sb[$m['status']] ?? 'gray' ?>"><?= htmlspecialchars($m['status']) ?></span>
        </td>
        <td style="text-align:center">
          <span class="badge badge-<?= $statusBadge[$bezug] ?>" style="font-size:.75rem"><?= $statusLabel[$bezug] ?></span>
        </td>
        <td style="text-align:center">
          <span class="badge badge-<?= $statusBadge[$einsp] ?>" style="font-size:.75rem"><?= $statusLabel[$einsp] ?></span>
        </td>
        <td style="text-align:right;font-size:.85rem;white-space:nowrap">
          <?php $amount = (float)($m['open_amount'] ?? 0); ?>
          <?php if ($amount > 0): ?>
            <span style="color:#dc2626;font-weight:600"><?= number_format($amount, 2, ',', '.') ?> €</span>
          <?php else: ?>
            <span style="color:#16a34a">—</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <a href="/portal/members/<?= $m['id'] ?>" style="font-size:.8rem">Details</a>
          &nbsp;·&nbsp;
          <a href="/portal/members/<?= $m['id'] ?>/edit" style="font-size:.8rem;color:#6b7280">Bearb.</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($members)): ?>
      <tr><td colspan="8" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Mitglieder.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterMembers() {
  const q = document.getElementById('search-input').value.toLowerCase();
  const s = document.getElementById('filter-status').value;
  const c = document.getElementById('filter-contract').value;
  const rows = document.querySelectorAll('#member-table tbody tr[data-name]');
  let visible = 0;
  rows.forEach(row => {
    const nm = !q || row.dataset.name.includes(q) || row.dataset.email.includes(q);
    const sm = !s || row.dataset.status === s;
    const cm = !c || row.dataset.contract === c;
    const show = nm && sm && cm;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('result-count').textContent = visible + ' Mitglied' + (visible !== 1 ? 'er' : '') + ' gefunden';
}
filterMembers();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
