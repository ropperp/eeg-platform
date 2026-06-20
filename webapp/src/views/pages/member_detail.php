<?php $pageTitle = 'Mitglied: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Mitgliederliste</a>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-<?= $member['status'] === 'active' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($member['status']) ?></span>
  <div style="margin-left:auto;display:flex;gap:.5rem">
    <?php $hasConsumer = !empty(array_filter($metering_points, fn($mp) => in_array($mp['type'], ['consumer', 'prosumer'], true) && in_array($mp['active'], [true, 't', '1', 1], true))); ?>
    <?php $hasProducer = !empty(array_filter($metering_points, fn($mp) => in_array($mp['type'], ['producer', 'prosumer'], true) && in_array($mp['active'], [true, 't', '1', 1], true))); ?>
    <?php if ($hasConsumer): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/bezug" target="_blank"
       class="btn" style="background:#1d4ed8;color:#fff;font-size:.8rem">📄 Bezugsvereinbarung</a>
    <?php endif; ?>
    <?php if ($hasProducer): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/einspeisung" target="_blank"
       class="btn" style="background:#b45309;color:#fff;font-size:.8rem">☀️ Einspeisevereinbarung</a>
    <?php endif; ?>
    <a href="/portal/members/<?= $member['id'] ?>/edit"
       class="btn" style="background:#f3f4f6;color:#374151;font-size:.8rem">✏️ Bearbeiten</a>
  </div>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
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
      <tr><th>Mitglied bis</th><td>
        <?php
          $until = $member['member_until'] ?? '';
          echo $until && $until !== '2099-12-31' ? date('d.m.Y', strtotime($until)) : 'aktiv';
        ?>
      </td></tr>
      <?php if (!empty($member['member_iban'])): ?>
      <tr><th>IBAN</th><td><code><?= htmlspecialchars($member['member_iban']) ?></code></td></tr>
      <?php endif; ?>
      <?php if (!empty($member['member_bic'])): ?>
      <tr><th>BIC</th><td><?= htmlspecialchars($member['member_bic']) ?></td></tr>
      <?php endif; ?>
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
            <th style="font-size:.75rem">EDA-Nr.</th>
            <th style="font-size:.75rem">ESP-Nr.</th>
            <th>Typ</th>
            <th>Aktionen</th>
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
                <span style="color:#9ca3af;font-size:.8rem">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($mp['zaehler_nr'] ?? null): ?>
                <code style="font-size:.75rem;color:#1d4ed8"><?= htmlspecialchars($mp['zaehler_nr']) ?></code>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:.8rem">—</span>
              <?php endif; ?>
            </td>
            <td><?= $mp['type'] === 'consumer' ? '⬇️ Bezug' : '⬆️ Einspeisung' ?></td>
            <td style="white-space:nowrap">
              <button onclick="openEditMp('<?= $mp['id'] ?>','<?= htmlspecialchars($mp['zaehlpunkt_nr'],ENT_QUOTES) ?>','<?= htmlspecialchars($mp['meter_code']??'',ENT_QUOTES) ?>','<?= $mp['type'] ?>','<?= htmlspecialchars($mp['zaehler_nr']??'',ENT_QUOTES) ?>')"
                      style="background:none;border:none;cursor:pointer;font-size:.8rem;color:#6b7280">✏️</button>
              <form method="post" action="/portal/members/<?= $member['id'] ?>/metering-points/<?= $mp['id'] ?>/delete" style="display:inline">
                <button type="submit" onclick="return confirm('Zählpunkt wirklich deaktivieren?')"
                        style="background:none;border:none;cursor:pointer;font-size:.8rem;color:#ef4444">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Zählpunkt hinzufügen -->
    <form method="post" action="/portal/members/<?= $member['id'] ?>/metering-points">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählpunktnummer (AT...) <span style="color:#ef4444">*</span></label>
          <input type="text" name="zaehlpunkt_nr" placeholder="AT001000000000000000..." required
                 style="font-family:monospace;font-size:.78rem">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">EDA-Zählernummer</label>
          <input type="text" name="meter_code" placeholder="1234567890123" maxlength="13" pattern="\d{13}"
                 style="font-family:monospace;font-size:.78rem" title="13-stellige Zählernummer aus EDA-XLSX">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählernummer (ESP)</label>
          <input type="text" name="zaehler_nr" placeholder="1234567890123" maxlength="13" pattern="\d{13}"
                 style="font-family:monospace;font-size:.78rem" title="13-stellige Zählernummer vom ESP32-Gerät (MQTT /power-Topic)">
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:flex-end">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Typ</label>
          <select name="type">
            <option value="consumer">⬇️ Bezug</option>
            <option value="producer">⬆️ Einspeisung</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:38px">+ Hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<!-- MQTT-Topics -->
<?php
$hasLiveTopics  = array_filter(array_column($metering_points, 'meter_code'));
$hasPowerTopics = array_filter(array_map(fn($mp) => $mp['zaehler_nr'] ?? null, $metering_points));
?>
<?php if ($hasLiveTopics || $hasPowerTopics): ?>
<?php $mqttId = Auth::activeCommunityMqttId() ?? '…'; ?>
<div class="card" style="font-size:.8rem;color:#6b7280;margin-bottom:1.5rem">
  <strong>MQTT-Topics (Live-Daten):</strong>
  <?php if ($hasLiveTopics): ?>
  <div style="margin-top:.5rem">
    <span style="font-size:.75rem;color:#9ca3af">Legacy /live —
      Payload: <code>{"pp": W-Bezug, "pm": W-Einspeisung, "ep": Wh-Bezug, "em": Wh-Einspeisung, "znr": "..."}</code></span>
    <?php foreach ($metering_points as $mp): ?>
      <?php if ($mp['meter_code']): ?>
        <div style="margin-top:.3rem">
          <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mp['meter_code']) ?>/live</code>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($hasPowerTopics): ?>
  <div style="margin-top:.5rem">
    <span style="font-size:.75rem;color:#9ca3af">ESP /power —
      Payload: <code>{"power_w": W, "meter_reading": Wh, "ts": "ISO8601"}</code></span>
    <?php foreach ($metering_points as $mp): ?>
      <?php if ($mp['zaehler_nr'] ?? null): ?>
        <div style="margin-top:.3rem">
          <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mp['zaehler_nr']) ?>/power</code>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Vertragsstatus -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">📋 Vertragsstatus</h3>
  <div class="grid-2">
    <?php
    $contractTypes = [
      'bezug'       => ['label' => 'Bezugsvereinbarung',     'color' => '#1d4ed8'],
      'einspeisung' => ['label' => 'Einspeisevereinbarung',  'color' => '#b45309'],
    ];
    $statusLabels = ['none' => 'Nicht erstellt', 'created' => 'Erstellt', 'signed' => 'Unterschrieben'];
    $statusBadge  = ['none' => 'gray', 'created' => 'yellow', 'signed' => 'green'];
    foreach ($contractTypes as $type => $info):
      $cur = $member['contract_' . $type . '_status'] ?? 'none';
    ?>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:.75rem 1rem">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <strong style="font-size:.9rem"><?= $info['label'] ?></strong>
        <span class="badge badge-<?= $statusBadge[$cur] ?? 'gray' ?>"><?= $statusLabels[$cur] ?></span>
      </div>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/contract-status" style="display:flex;gap:.5rem;align-items:center">
        <input type="hidden" name="type" value="<?= $type ?>">
        <select name="status" style="flex:1;padding:.3rem .5rem;border:1px solid #e5e7eb;border-radius:6px;font-size:.8rem">
          <?php foreach ($statusLabels as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $cur === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="padding:.3rem .75rem;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:.8rem">Speichern</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Edit-Modal -->
<dialog id="edit-mp-dialog" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;min-width:420px;box-shadow:0 8px 32px rgba(0,0,0,.1)">
  <h3 style="margin-bottom:1rem">Zählpunkt bearbeiten</h3>
  <form method="post" id="edit-mp-form">
    <div class="form-group">
      <label>Zählpunktnummer (AT...)</label>
      <input type="text" name="zaehlpunkt_nr" id="edit-mp-znr" required style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>EDA-Zählernummer (13 Stellen)</label>
      <input type="text" name="meter_code" id="edit-mp-mc" maxlength="13" style="font-family:monospace"
             title="Zählernummer aus EDA-XLSX (MQTT /live-Topic)">
    </div>
    <div class="form-group">
      <label>Zählernummer (ESP, 13 Stellen)</label>
      <input type="text" name="zaehler_nr" id="edit-mp-zaehler" maxlength="13" style="font-family:monospace"
             title="13-stellige Zählernummer vom ESP32-Gerät (MQTT /power-Topic)">
    </div>
    <div class="form-group">
      <label>Typ</label>
      <select name="type" id="edit-mp-type">
        <option value="consumer">⬇️ Bezug</option>
        <option value="producer">⬆️ Einspeisung</option>
      </select>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary">Speichern</button>
      <button type="button" onclick="document.getElementById('edit-mp-dialog').close()" class="btn" style="background:#f3f4f6;color:#374151">Abbrechen</button>
    </div>
  </form>
</dialog>

<script>
function openEditMp(id, znr, mc, type, zaehlerNr) {
  document.getElementById('edit-mp-form').action = '/portal/members/<?= $member['id'] ?>/metering-points/' + id + '/edit';
  document.getElementById('edit-mp-znr').value = znr;
  document.getElementById('edit-mp-mc').value = mc;
  document.getElementById('edit-mp-type').value = type;
  document.getElementById('edit-mp-zaehler').value = zaehlerNr || '';
  document.getElementById('edit-mp-dialog').showModal();
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
