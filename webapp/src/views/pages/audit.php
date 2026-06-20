<?php
$pageTitle = 'Ereignisprotokoll';
ob_start();

$actionLabels = [
    'login.success'          => '✅ Login erfolgreich',
    'login.failed'           => '❌ Login fehlgeschlagen',
    'member.create'          => '➕ Mitglied angelegt',
    'member.update'          => '✏️ Mitglied bearbeitet',
    'member.delete'          => '🗑 Mitglied gelöscht',
    'metering_point.create'  => '➕ Zählpunkt angelegt',
    'metering_point.update'  => '✏️ Zählpunkt bearbeitet',
    'metering_point.deactivate' => '🔌 Zählpunkt deaktiviert',
    'tariff.update'          => '💰 Tarif geändert',
    'tax.update'             => '🧾 Steuer geändert',
    'contract.sign'          => '📝 Vertrag unterzeichnet',
    'billing.release'        => '✅ Abrechnung freigegeben',
];
?>

<h2 style="margin-bottom:1.5rem">🗂 Ereignisprotokoll</h2>

<!-- Filter -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <form method="get" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <input type="text" name="q" placeholder="Aktion suchen…"
           value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
           style="flex:1;min-width:160px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
    <input type="date" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>"
           style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
    <span style="color:#9ca3af">–</span>
    <input type="date" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>"
           style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
    <button type="submit" class="btn btn-primary" style="padding:.4rem .9rem">Filtern</button>
    <?php if (!empty($_GET['q']) || !empty($_GET['from']) || !empty($_GET['to'])): ?>
      <a href="<?= $isAdmin ? '/admin/audit' : '/portal/audit' ?>" class="btn" style="background:#f3f4f6;color:#374151;padding:.4rem .9rem">
        × Zurücksetzen
      </a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto">
  <table style="font-size:.85rem">
    <thead>
      <tr>
        <th style="white-space:nowrap">Zeitpunkt</th>
        <th>Aktion</th>
        <th>Entität</th>
        <th>Akteur</th>
        <?php if ($isAdmin): ?><th>Community</th><?php endif; ?>
        <th>IP</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($entries)): ?>
      <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" style="text-align:center;color:#6b7280;padding:2rem">
        Keine Einträge gefunden.
      </td></tr>
    <?php endif; ?>
    <?php foreach ($entries as $e): ?>
      <tr>
        <td style="white-space:nowrap;color:#6b7280">
          <?= date('d.m.Y', strtotime($e['created_at'])) ?><br>
          <small><?= date('H:i:s', strtotime($e['created_at'])) ?></small>
        </td>
        <td style="white-space:nowrap">
          <?= htmlspecialchars($actionLabels[$e['action']] ?? $e['action']) ?>
        </td>
        <td>
          <code style="font-size:.8rem"><?= htmlspecialchars($e['entity_type']) ?></code>
          <?php if ($e['entity_id']): ?>
            <br><small style="color:#9ca3af"><?= htmlspecialchars(substr($e['entity_id'], 0, 16)) ?>…</small>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($e['actor_label']): ?>
            <span style="font-size:.8rem;color:#6b7280;font-family:monospace"><?= htmlspecialchars($e['actor_label']) ?></span>
          <?php elseif ($e['user_name']): ?>
            <?= htmlspecialchars($e['user_name']) ?>
            <?php if ($e['user_email']): ?>
              <br><small style="color:#9ca3af"><?= htmlspecialchars($e['user_email']) ?></small>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:#9ca3af">—</span>
          <?php endif; ?>
        </td>
        <?php if ($isAdmin): ?>
        <td style="font-size:.8rem;color:#6b7280">
          <?= htmlspecialchars($e['community_name'] ?? '—') ?>
        </td>
        <?php endif; ?>
        <td style="font-family:monospace;font-size:.8rem;color:#6b7280">
          <?= htmlspecialchars($e['ip'] ?? '—') ?>
        </td>
        <td>
          <?php if ($e['details']): ?>
            <?php $det = json_decode($e['details'], true); ?>
            <details style="cursor:pointer">
              <summary style="font-size:.75rem;color:#2563eb">Details</summary>
              <pre style="margin:.4rem 0 0;font-size:.72rem;background:#f3f4f6;padding:.5rem;border-radius:4px;max-width:320px;overflow:auto;white-space:pre-wrap"><?= htmlspecialchars(json_encode($det, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
          <?php else: ?>
            <span style="color:#9ca3af">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:.5rem;align-items:center;margin-top:1rem;justify-content:center">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <?php
      $qs = http_build_query(array_merge($_GET, ['page' => $p]));
      $url = ($isAdmin ? '/admin/audit' : '/portal/audit') . '?' . $qs;
    ?>
    <a href="<?= htmlspecialchars($url) ?>"
       style="padding:.3rem .65rem;border-radius:6px;border:1px solid #e5e7eb;text-decoration:none;
              <?= $p === $currentPage ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : 'color:#374151;' ?>">
      <?= $p ?>
    </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
