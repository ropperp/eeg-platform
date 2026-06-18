<?php $pageTitle = 'Mitglied: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Mitgliederliste</a>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-<?= $member['status'] === 'active' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($member['status']) ?></span>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Zählpunkt gespeichert.</div>
<?php elseif (isset($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Zählernummer fehlt oder ist ungültig.</div>
<?php endif; ?>

<div class="grid-2" style="gap:1.5rem;margin-bottom:1.5rem">
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
    <h3 style="margin-bottom:1rem">Zählpunkte & Smart Meter</h3>

    <?php if (empty($metering_points)): ?>
      <p style="color:#6b7280;font-size:.875rem;margin-bottom:1rem">Noch keine Zählpunkte registriert.</p>
    <?php else: ?>
      <table style="margin-bottom:1.25rem;font-size:.85rem">
        <thead>
          <tr>
            <th>Zählpunktnummer (AT...)</th>
            <th>Zählernummer</th>
            <th>Typ</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($metering_points as $mp): ?>
          <tr>
            <td><code style="font-size:.75rem"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
            <td>
              <?php if ($mp['meter_code']): ?>
                <code style="font-size:.75rem;color:#16a34a"><?= htmlspecialchars($mp['meter_code']) ?></code>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:.8rem">nicht zugewiesen</span>
              <?php endif; ?>
            </td>
            <td>
              <?= $mp['type'] === 'consumer' ? '⬇️ Bezug' : '⬆️ Einspeisung' ?>
            </td>
            <td><span class="badge badge-<?= $mp['active'] ? 'green' : 'gray' ?>"><?= $mp['active'] ? 'aktiv' : 'inaktiv' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" action="/portal/members/<?= $member['id'] ?>/metering-points">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählpunktnummer (AT...) <span style="color:#ef4444">*</span></label>
          <input type="text" name="zaehlpunkt_nr" placeholder="AT001000000000000000..." required
                 style="font-family:monospace;font-size:.78rem">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählernummer (13 Stellen) für ESP32</label>
          <input type="text" name="meter_code" placeholder="1234567890123" maxlength="13" pattern="\d{13}"
                 style="font-family:monospace;font-size:.78rem">
          <small style="color:#6b7280;font-size:.72rem">MQTT-Identifikation für Live-Daten</small>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Typ</label>
          <select name="type">
            <option value="consumer">⬇️ Bezug</option>
            <option value="producer">⬆️ Einspeisung</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:38px;margin-top:1.1rem">+ Zählpunkt hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<div class="card" style="font-size:.8rem;color:#6b7280">
  <strong>MQTT-Topic für dieses Mitglied:</strong><br>
  <?php foreach ($metering_points as $mp): ?>
    <?php if ($mp['meter_code']): ?>
      <code>eeg/<?= htmlspecialchars(Auth::activeCommunitySlug() ?? '…') ?>/meter/<?= htmlspecialchars($mp['meter_code']) ?>/live</code>
      (<?= $mp['type'] === 'consumer' ? 'Bezug' : 'Einspeisung' ?>)<br>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!array_filter(array_column($metering_points, 'meter_code'))): ?>
    <em>Kein Zählpunkt mit Zählernummer registriert.</em>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
