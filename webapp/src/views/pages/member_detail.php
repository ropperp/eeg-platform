<?php $pageTitle = 'Mitglied: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Mitgliederliste</a>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-<?= $member['status'] === 'active' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($member['status']) ?></span>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php endif; ?>

<div class="grid-2" style="gap:1.5rem">
  <!-- Stammdaten -->
  <div class="card">
    <h3 style="margin-bottom:1rem">Stammdaten</h3>
    <table>
      <tr><th>E-Mail</th><td><?= htmlspecialchars($member['email']) ?></td></tr>
      <tr><th>Telefon</th><td><?= htmlspecialchars($member['phone'] ?? '—') ?></td></tr>
      <tr><th>Adresse</th><td><?= htmlspecialchars($member['address'] . ', ' . $member['zip'] . ' ' . $member['city']) ?></td></tr>
      <tr><th>UID</th><td><?= htmlspecialchars($member['invoice_uid'] ?? '—') ?></td></tr>
      <tr><th>Mitglied seit</th><td><?= $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '—' ?></td></tr>
    </table>
  </div>

  <!-- Zählpunkte -->
  <div class="card">
    <h3 style="margin-bottom:1rem">Zählpunkte</h3>

    <?php if (empty($metering_points)): ?>
      <p style="color:#6b7280;font-size:.875rem;margin-bottom:1rem">Noch keine Zählpunkte registriert.</p>
    <?php else: ?>
      <table style="margin-bottom:1rem">
        <thead><tr><th>Zählpunktnummer</th><th>Typ</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($metering_points as $mp): ?>
          <tr>
            <td><code style="font-size:.75rem"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
            <td><?= htmlspecialchars($mp['type']) ?></td>
            <td><span class="badge badge-<?= $mp['active'] ? 'green' : 'gray' ?>"><?= $mp['active'] ? 'aktiv' : 'inaktiv' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" action="/portal/members/<?= $member['id'] ?>/metering-points">
      <div style="display:flex;gap:.5rem;align-items:flex-end">
        <div class="form-group" style="margin-bottom:0;flex:1">
          <label style="font-size:.8rem">Zählpunktnummer (AT...)</label>
          <input type="text" name="zaehlpunkt_nr" placeholder="AT0010000000000000001000012345678" required
                 style="font-family:monospace;font-size:.8rem">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Typ</label>
          <select name="type">
            <option value="consumer">Bezug</option>
            <option value="producer">Einspeisung</option>
            <option value="prosumer">Prosumer</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:38px">+ Hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
