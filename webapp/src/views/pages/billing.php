<?php
$pageTitle = 'Abrechnung';
ob_start();
?>

<h2 style="margin-bottom:1.5rem">💶 Abrechnung</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success">Abrechnung erfolgreich freigegeben. PDFs werden generiert und versendet.</div>
<?php endif; ?>

<!-- Suche -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div style="display:flex;gap:.75rem;align-items:center">
    <input type="text" id="billing-search" placeholder="Quartal suchen (z.B. 2026-Q1)…"
           style="flex:1;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterBilling()">
    <select id="billing-status" onchange="filterBilling()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Status</option>
      <option value="pending">Ausstehend</option>
      <option value="ready">Bereit</option>
      <option value="released">Freigegeben</option>
      <option value="done">Abgeschlossen</option>
    </select>
  </div>
</div>

<div class="card">
  <table id="billing-table">
    <thead>
      <tr>
        <th>Quartal</th>
        <th>Zeitraum</th>
        <th>Status</th>
        <th>Freigabe ab</th>
        <th>Freigegeben am</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $run): ?>
      <tr data-quartal="<?= htmlspecialchars(strtolower($run['quartal'])) ?>" data-status="<?= htmlspecialchars($run['status']) ?>">
        <td><?= htmlspecialchars($run['quartal']) ?></td>
        <td><?= date('d.m.Y', strtotime($run['period_from'])) ?> – <?= date('d.m.Y', strtotime($run['period_to'])) ?></td>
        <td>
          <?php $badges = ['pending' => 'gray', 'ready' => 'green', 'released' => 'yellow', 'done' => 'green']; ?>
          <span class="badge badge-<?= $badges[$run['status']] ?? 'gray' ?>">
            <?= htmlspecialchars($run['status']) ?>
          </span>
        </td>
        <td><?= date('d.m.Y', strtotime($run['freigabe_nach'])) ?></td>
        <td><?= $run['released_at'] ? date('d.m.Y H:i', strtotime($run['released_at'])) : '—' ?></td>
        <td>
          <?php if ($run['status'] === 'ready'): ?>
            <form method="post" action="/portal/billing/release"
                  onsubmit="return confirm('Abrechnung wirklich freigeben? Dieser Schritt kann nicht rückgängig gemacht werden.')">
              <input type="hidden" name="billing_run_id" value="<?= $run['id'] ?>">
              <button class="btn btn-primary" style="padding:.35rem .75rem;font-size:.8rem">
                ✅ Freigeben
              </button>
            </form>
          <?php elseif ($run['status'] === 'pending'): ?>
            <span style="font-size:.8rem;color:var(--gray-600)">
              Noch <?= max(0, ceil((strtotime($run['freigabe_nach']) - time()) / 86400)) ?> Tage
            </span>
          <?php else: ?>
            <a href="/portal/billing/invoices?quartal=<?= urlencode($run['quartal']) ?>" style="font-size:.8rem">Rechnungen ansehen</a>
          <?php endif; ?>
          <?php if (Auth::isManager()): ?>
            <form method="post" action="/portal/billing/<?= $run['id'] ?>/delete" style="display:inline"
                  onsubmit="return confirmDangerDelete('Abrechnungslauf <?= htmlspecialchars(addslashes($run['quartal'])) ?> inkl. aller zugehörigen Rechnungen')">
              <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c;padding:.35rem .6rem;font-size:.8rem;margin-left:.4rem">🗑️</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($runs)): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Abrechnungsläufe vorhanden.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterBilling() {
  const q = document.getElementById('billing-search').value.toLowerCase();
  const s = document.getElementById('billing-status').value;
  document.querySelectorAll('#billing-table tbody tr[data-quartal]').forEach(row => {
    const qm = !q || row.dataset.quartal.includes(q);
    const sm = !s || row.dataset.status === s;
    row.style.display = qm && sm ? '' : 'none';
  });
}
</script>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem">ℹ️ Hinweis zum 60-Tage-Korrekturfenster</h3>
  <p style="font-size:.875rem;color:var(--gray-600)">
    Gemäß den EDA-Richtlinien und den Vereinsstatuten darf eine Abrechnung erst 60 Tage nach Quartalsende
    freigegeben werden. In dieser Zeit können Messwerte vom Netzbetreiber noch korrigiert werden (L1 → L2 → L3).
    Der Freigabe-Button erscheint automatisch sobald das Fenster abgelaufen ist und alle Daten vollständig sind.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
