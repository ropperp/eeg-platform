<?php
$pageTitle = 'Abrechnung';
ob_start();
?>

<h2 style="margin-bottom:1.5rem">💶 Abrechnung</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($warnings)): ?>
  <div class="alert alert-warning">
    <strong>Berechnung abgeschlossen – mit Hinweisen:</strong><br>
    <?php foreach ($warnings as $w): ?>
      <?= htmlspecialchars($w) ?><br>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Neuen Abrechnungslauf anlegen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem">Neuen Abrechnungslauf anlegen</h3>
  <form method="post" action="/portal/billing" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <div>
      <label style="display:block;font-size:.8rem;color:#6b7280;margin-bottom:.25rem">Von</label>
      <input type="date" name="period_from" required
             style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:#6b7280;margin-bottom:.25rem">Bis</label>
      <input type="date" name="period_to" required
             style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
    </div>
    <button type="submit" class="btn btn-primary">+ Anlegen</button>
  </form>
  <p style="font-size:.8rem;color:#9ca3af;margin-top:.5rem">
    Standard-Quartale: Q1 = 01.01.–31.03. &nbsp;|&nbsp; Q2 = 01.04.–30.06. &nbsp;|&nbsp; Q3 = 01.07.–30.09. &nbsp;|&nbsp; Q4 = 01.10.–31.12.
  </p>
</div>

<!-- Filterzeile -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div style="display:flex;gap:.75rem;align-items:center">
    <input type="text" id="billing-search" placeholder="Quartal suchen (z.B. 2026-Q1)…"
           style="flex:1;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterBilling()">
    <select id="billing-status" onchange="filterBilling()"
            style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Status</option>
      <option value="pending">Ausstehend</option>
      <option value="ready">Bereit</option>
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
      <?php
        $statusBadge = ['pending' => 'gray', 'ready' => 'green', 'done' => 'green'];
        $statusLabel = ['pending' => 'Ausstehend', 'ready' => 'Bereit', 'done' => 'Abgeschlossen'];
      ?>
      <tr data-quartal="<?= htmlspecialchars(strtolower($run['quartal'])) ?>" data-status="<?= htmlspecialchars($run['status']) ?>">
        <td><?= htmlspecialchars($run['quartal']) ?></td>
        <td><?= date('d.m.Y', strtotime($run['period_from'])) ?> – <?= date('d.m.Y', strtotime($run['period_to'])) ?></td>
        <td>
          <span class="badge badge-<?= $statusBadge[$run['status']] ?? 'gray' ?>">
            <?= htmlspecialchars($statusLabel[$run['status']] ?? $run['status']) ?>
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
            <form method="post" action="/portal/billing/compute"
                  onsubmit="return confirm('Rechnungen jetzt berechnen? Bestehende Entwürfe werden überschrieben.')">
              <input type="hidden" name="billing_run_id" value="<?= $run['id'] ?>">
              <button class="btn btn-secondary" style="padding:.35rem .75rem;font-size:.8rem">
                🔄 Berechnen
              </button>
            </form>
          <?php elseif ($run['status'] === 'done'): ?>
            <span style="font-size:.8rem;color:#6b7280">Freigegeben</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($runs)): ?>
      <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Abrechnungsläufe vorhanden.</td></tr>
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
  <p style="font-size:.875rem;color:#6b7280">
    Gemäß den EDA-Richtlinien darf eine Abrechnung erst 60 Tage nach Periodenende freigegeben werden.
    In dieser Zeit können Messwerte vom Netzbetreiber noch korrigiert werden (L1 → L2 → L3).
    Der Button „Berechnen" erstellt Entwurfs-Rechnungen, die bis zur Freigabe neu berechnet werden können.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
