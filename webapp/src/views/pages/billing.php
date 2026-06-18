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

<div class="card">
  <table>
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
      <tr>
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
            <span style="font-size:.8rem;color:#9ca3af">
              Noch <?= max(0, ceil((strtotime($run['freigabe_nach']) - time()) / 86400)) ?> Tage
            </span>
          <?php else: ?>
            <a href="#" style="font-size:.8rem">Rechnungen ansehen</a>
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

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem">ℹ️ Hinweis zum 60-Tage-Korrekturfenster</h3>
  <p style="font-size:.875rem;color:#6b7280">
    Gemäß den EDA-Richtlinien und den Vereinsstatuten darf eine Abrechnung erst 60 Tage nach Quartalsende
    freigegeben werden. In dieser Zeit können Messwerte vom Netzbetreiber noch korrigiert werden (L1 → L2 → L3).
    Der Freigabe-Button erscheint automatisch sobald das Fenster abgelaufen ist und alle Daten vollständig sind.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
