<?php $pageTitle = 'Mitglied: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Mitgliederliste</a>
  <div style="position:relative">
    <img src="<?= htmlspecialchars(memberAvatarUrl($member['id'], $member['photo_path'], $member['salutation'])) ?>"
         alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover">
    <button type="button" onclick="document.getElementById('photo-dialog').showModal()"
            title="Profilbild ändern"
            style="position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;border-radius:50%;background:#f3f4f6;border:1px solid #e5e7eb;font-size:.6rem;line-height:1;cursor:pointer;padding:0">✏️</button>
  </div>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-gray" style="font-weight:700;color:#15803d">KdNr <?= htmlspecialchars((string)($member['kundennummer'] ?? '—')) ?></span>
  <span class="badge badge-<?= $member['status'] === 'active' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($member['status']) ?></span>
  <?php if (!empty($application)): ?>
  <span class="badge badge-blue" title="Über das Online-Beitrittsformular eingereicht">🌐 Online</span>
  <?php else: ?>
  <span class="badge badge-gray" title="Manuell angelegt, z. B. Beitrittserklärung offline per E-Mail">✉️ Offline</span>
  <?php endif; ?>
  <div style="margin-left:auto;display:flex;gap:.5rem">
    <?php $hasConsumer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'consumer' && in_array($mp['active'], [true, 't', '1', 1], true) && !empty($mp['zaehlpunkt_nr']))); ?>
    <?php $hasProducer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'producer' && in_array($mp['active'], [true, 't', '1', 1], true) && !empty($mp['zaehlpunkt_nr']))); ?>
    <?php if ($hasConsumer): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/bezug" target="_blank"
       class="btn" style="background:#1d4ed8;color:#fff;font-size:.8rem">📄 Bezugsvereinbarung</a>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/bezug/send" style="display:inline"
          onsubmit="return confirm('Bezugsvereinbarung jetzt endgültig an <?= htmlspecialchars(addslashes($member['email'])) ?> senden?')">
      <button type="submit" class="btn" style="background:#eff6ff;color:#1d4ed8;font-size:.8rem">✉️ Jetzt senden</button>
    </form>
    <?php endif; ?>
    <?php if ($hasProducer): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/einspeisung" target="_blank"
       class="btn" style="background:#b45309;color:#fff;font-size:.8rem">☀️ Einspeisevereinbarung</a>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/einspeisung/send" style="display:inline"
          onsubmit="return confirm('Einspeisevereinbarung jetzt endgültig an <?= htmlspecialchars(addslashes($member['email'])) ?> senden?')">
      <button type="submit" class="btn" style="background:#fffbeb;color:#b45309;font-size:.8rem">✉️ Jetzt senden</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($application)): ?>
    <a href="/portal/applications/<?= $application['id'] ?>/formular" target="_blank"
       class="btn" style="background:#f3f4f6;color:#374151;font-size:.8rem">🖨️ Formular ausdrucken (PDF)</a>
    <?php endif; ?>
    <a href="/portal/members/<?= $member['id'] ?>/edit"
       class="btn" style="background:#f3f4f6;color:#374151;font-size:.8rem">✏️ Bearbeiten</a>
    <?php if (!empty($member['user_id'])): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/reset-password" style="display:inline">
      <button type="submit" class="btn" style="background:#e0f2fe;color:#0369a1;font-size:.8rem">🔑 Passwort zurücksetzen</button>
    </form>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/delete-login" style="display:inline"
          onsubmit="return confirmDangerDelete('Login-Konto von <?= htmlspecialchars(addslashes($member['first_name'] . ' ' . $member['last_name'])) ?> (das Mitglied selbst bleibt bestehen)')">
      <button type="submit" class="btn" style="background:#fef3c7;color:#92400e;font-size:.8rem">🔒 Login löschen</button>
    </form>
    <?php endif; ?>
    <?php if (Auth::isPlatformAdmin()): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/delete" style="display:inline"
          onsubmit="return confirmDangerDelete('Mitglied <?= htmlspecialchars(addslashes($member['first_name'] . ' ' . $member['last_name'])) ?> (KdNr <?= htmlspecialchars((string)($member['kundennummer'] ?? '—')) ?>) inkl. aller Zählpunkte, Verträge und Rechnungen')">
      <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c;font-size:.8rem">🗑️ Löschen</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (($_GET['success'] ?? '') === 'reset_sent'): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Link zum Passwort-Zurücksetzen wurde per E-Mail verschickt (10 Minuten gültig).</div>
<?php elseif (($_GET['success'] ?? '') === 'invite_sent'): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Freigegeben — Einladung mit Erstlogin-Link wurde per E-Mail verschickt.</div>
<?php elseif (($_GET['error'] ?? '') === 'mail'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">
    E-Mail-Versand fehlgeschlagen<?php if (!empty($_GET['detail'])): ?>: <code style="font-size:.78rem"><?= htmlspecialchars($_GET['detail']) ?></code><?php endif; ?>
  </div>
<?php elseif (!empty($_GET['success']) && $_GET['success'] !== '1'): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['success']) ?></div>
<?php elseif (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php elseif (($_GET['error'] ?? '') === 'upload'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Datei-Upload fehlgeschlagen.</div>
<?php elseif (($_GET['error'] ?? '') === 'phototype'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Profilbild: nur JPG, PNG oder WEBP erlaubt.</div>
<?php elseif (($_GET['error'] ?? '') === 'upload_db'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">
    Datei-Upload fehlgeschlagen (Datenbankfehler)<?php if (!empty($_GET['detail'])): ?>: <code style="font-size:.78rem"><?= htmlspecialchars($_GET['detail']) ?></code><?php endif; ?>
  </div>
<?php elseif (($_GET['error'] ?? '') === 'znr_duplicate'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">
    Diese Zählpunktnummer ist bereits vergeben<?php if (!empty($_GET['znr_owner'])): ?> — an <?= htmlspecialchars($_GET['znr_owner']) ?><?php endif; ?>.
  </div>
<?php elseif (($_GET['error'] ?? '') === 'znr'): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Zählernummer fehlt oder ist ungültig.</div>
<?php elseif (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if (!empty($successTempPw)): ?>
  <div class="card" style="margin-bottom:1.5rem;border:2px solid #16a34a">
    <h3 style="color:#15803d;margin-bottom:.75rem">✅ Freigegeben — Login-Daten</h3>
    <?php if (!empty($successInviteError)): ?>
      <p style="margin-bottom:.5rem;color:#b91c1c;font-size:.85rem">Einladungs-E-Mail konnte nicht verschickt werden: <code style="font-size:.78rem"><?= htmlspecialchars($successInviteError) ?></code></p>
    <?php endif; ?>
    <p style="margin-bottom:.5rem">Bitte teilen Sie dem Mitglied folgende Zugangsdaten mit:</p>
    <table>
      <tr><th>E-Mail</th><td><code><?= htmlspecialchars($successEmail) ?></code></td></tr>
      <tr><th>Temporäres Passwort</th><td><code style="font-size:1.1rem;color:#15803d"><?= htmlspecialchars($successTempPw) ?></code></td></tr>
    </table>
    <p style="margin-top:.75rem;font-size:.8rem;color:#6b7280">Das Mitglied sollte das Passwort nach dem ersten Login ändern. Diese Anzeige erscheint nur einmal.</p>
  </div>
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
            <th>Zählernummer</th>
            <th>Typ</th>
            <th>Details</th>
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
            <td><?= $mp['type'] === 'consumer' ? '⬇️ Bezug' : '⬆️ Einspeisung' ?></td>
            <td style="font-size:.78rem;color:#6b7280">
              <?php if ($mp['type'] === 'consumer'): ?>
                <?= $mp['jahresverbrauch_kwh'] ? number_format((float)$mp['jahresverbrauch_kwh'], 0, ',', '.') . ' kWh/Jahr' : '—' ?>
              <?php else: ?>
                <?= $mp['engpassleistung_kw'] ? number_format((float)$mp['engpassleistung_kw'], 2, ',', '.') . ' kWp' : '—' ?>
                <?= $mp['geplante_einspeisung_kwh'] ? ' · ' . number_format((float)$mp['geplante_einspeisung_kwh'], 0, ',', '.') . ' kWh/Jahr geplant' : '' ?>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <button onclick="openEditMp('<?= $mp['id'] ?>','<?= htmlspecialchars($mp['zaehlpunkt_nr'],ENT_QUOTES) ?>','<?= htmlspecialchars($mp['meter_code']??'',ENT_QUOTES) ?>','<?= $mp['type'] ?>','<?= htmlspecialchars((string)($mp['jahresverbrauch_kwh']??''),ENT_QUOTES) ?>','<?= htmlspecialchars((string)($mp['engpassleistung_kw']??''),ENT_QUOTES) ?>','<?= htmlspecialchars((string)($mp['geplante_einspeisung_kwh']??''),ENT_QUOTES) ?>')"
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
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählpunktnummer (AT...) <span style="color:#ef4444">*</span></label>
          <input type="text" name="zaehlpunkt_nr" placeholder="AT001000000000000000..." required
                 style="font-family:monospace;font-size:.78rem">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Zählernummer (13 Stellen)</label>
          <input type="text" name="meter_code" placeholder="1234567890123" maxlength="13" pattern="\d{13}"
                 style="font-family:monospace;font-size:.78rem">
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:flex-end;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Typ</label>
          <select name="type" id="add-mp-type" onchange="toggleMpTypeFields('add')">
            <option value="consumer">⬇️ Bezug</option>
            <option value="producer">⬆️ Einspeisung</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:38px">+ Hinzufügen</button>
      </div>
      <div id="add-mp-consumer-fields" style="display:grid;grid-template-columns:1fr;gap:.5rem;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Jahresverbrauch (kWh)</label>
          <input type="text" name="jahresverbrauch_kwh" placeholder="z. B. 3500" style="font-size:.78rem">
        </div>
      </div>
      <div id="add-mp-producer-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Leistung PV-Anlage (kWp)</label>
          <input type="text" name="engpassleistung_kw" placeholder="z. B. 9,90" style="font-size:.78rem">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-size:.8rem">Geplante Einspeisung (kWh/Jahr)</label>
          <input type="text" name="geplante_einspeisung_kwh" placeholder="z. B. 8000" style="font-size:.78rem">
        </div>
      </div>
    </form>
  </div>
</div>

<!-- MQTT-Topics -->
<?php
$uniqueMeterCodes = [];
foreach ($metering_points as $mp) {
    if ($mp['meter_code'] && !in_array($mp['meter_code'], $uniqueMeterCodes)) {
        $uniqueMeterCodes[] = $mp['meter_code'];
    }
}
?>
<?php if ($uniqueMeterCodes): ?>
<?php $mqttId = Auth::activeCommunityMqttId() ?? '…'; ?>
<div class="card" style="font-size:.8rem;color:#6b7280;margin-bottom:1.5rem">
  <strong>MQTT-Topics (Live-Daten):</strong>
  <?php foreach ($uniqueMeterCodes as $mc): ?>
    <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid #e5e7eb">
      <div style="margin-bottom:.3rem">
        <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mc) ?>/live</code>
        <span style="color:#9ca3af;font-size:.72rem;margin-left:.4rem">Legacy · pp/pm/ep/em/znr</span>
      </div>
      <div>
        <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mc) ?>/power</code>
        <span style="color:#9ca3af;font-size:.72rem;margin-left:.4rem">ESP · power_w/meter_reading/ts</span>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Vertragsstatus -->
<?php
$contractTypes = [];
if ($hasConsumer) $contractTypes['bezug']       = ['label' => 'Bezugsvereinbarung',    'color' => '#1d4ed8'];
if ($hasProducer) $contractTypes['einspeisung'] = ['label' => 'Einspeisevereinbarung', 'color' => '#b45309'];
?>
<?php if (!empty($contractTypes)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">📋 Vertragsstatus</h3>
  <div class="<?= count($contractTypes) === 1 ? '' : 'grid-2' ?>">
    <?php
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
<?php endif; ?>

<!-- Dateien -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">📎 Dateien</h3>

  <?php if (empty($member_files)): ?>
    <p style="color:#6b7280;font-size:.875rem;margin-bottom:1rem">Noch keine Dateien hochgeladen.</p>
  <?php else: ?>
    <table style="margin-bottom:1.25rem;font-size:.85rem">
      <thead>
        <tr><th>Name</th><th>Hochgeladen am</th><th>Aktionen</th></tr>
      </thead>
      <tbody>
      <?php foreach ($member_files as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
          <td>
            <a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download" style="font-size:.8rem">Herunterladen</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" action="/portal/members/<?= $member['id'] ?>/files" enctype="multipart/form-data">
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:flex-end">
      <div class="form-group" style="margin-bottom:0">
        <label style="font-size:.8rem">Bezeichnung (optional)</label>
        <input type="text" name="name" list="file-name-suggestions" placeholder="z. B. Ausweis, Beitrittserklärung …">
        <datalist id="file-name-suggestions">
          <option value="Beitrittserklärung">
          <option value="Bezugsvereinbarung">
          <option value="Einspeisevereinbarung">
          <option value="Personalausweis">
          <option value="Reisepass">
        </datalist>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label style="font-size:.8rem">Datei</label>
        <input type="file" name="file" required>
      </div>
      <button type="submit" class="btn btn-primary" style="height:38px">Hochladen</button>
    </div>
  </form>
</div>

<!-- Profilbild-Modal -->
<dialog id="photo-dialog" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;min-width:340px;box-shadow:0 8px 32px rgba(0,0,0,.1)">
  <h3 style="margin-bottom:1rem">Profilbild ändern</h3>
  <form method="post" action="/portal/members/<?= $member['id'] ?>/photo" enctype="multipart/form-data">
    <div class="form-group">
      <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
    </div>
    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" onclick="document.getElementById('photo-dialog').close()" class="btn" style="background:#f3f4f6;color:#374151">Abbrechen</button>
      <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
  </form>
</dialog>

<!-- Edit-Modal -->
<dialog id="edit-mp-dialog" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;min-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.1)">
  <h3 style="margin-bottom:1rem">Zählpunkt bearbeiten</h3>
  <form method="post" id="edit-mp-form">
    <div class="form-group">
      <label>Zählpunktnummer (AT...)</label>
      <input type="text" name="zaehlpunkt_nr" id="edit-mp-znr" required style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>Zählernummer (13 Stellen)</label>
      <input type="text" name="meter_code" id="edit-mp-mc" maxlength="13" style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>Typ</label>
      <select name="type" id="edit-mp-type" onchange="toggleMpTypeFields('edit')">
        <option value="consumer">⬇️ Bezug</option>
        <option value="producer">⬆️ Einspeisung</option>
      </select>
    </div>
    <div id="edit-mp-consumer-fields" class="form-group">
      <label>Jahresverbrauch (kWh)</label>
      <input type="text" name="jahresverbrauch_kwh" id="edit-mp-jahresverbrauch" placeholder="z. B. 3500">
    </div>
    <div id="edit-mp-producer-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
      <div class="form-group">
        <label>Leistung PV-Anlage (kWp)</label>
        <input type="text" name="engpassleistung_kw" id="edit-mp-kwp" placeholder="z. B. 9,90">
      </div>
      <div class="form-group">
        <label>Geplante Einspeisung (kWh/Jahr)</label>
        <input type="text" name="geplante_einspeisung_kwh" id="edit-mp-geplant" placeholder="z. B. 8000">
      </div>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary">Speichern</button>
      <button type="button" onclick="document.getElementById('edit-mp-dialog').close()" class="btn" style="background:#f3f4f6;color:#374151">Abbrechen</button>
    </div>
  </form>
</dialog>

<script>
function toggleMpTypeFields(prefix) {
  const isConsumer = document.getElementById(prefix + '-mp-type').value === 'consumer';
  document.getElementById(prefix + '-mp-consumer-fields').style.display = isConsumer ? '' : 'none';
  document.getElementById(prefix + '-mp-producer-fields').style.display = isConsumer ? 'none' : '';
}
toggleMpTypeFields('add');

function openEditMp(id, znr, mc, type, jahresverbrauch, kwp, geplant) {
  document.getElementById('edit-mp-form').action = '/portal/members/<?= $member['id'] ?>/metering-points/' + id + '/edit';
  document.getElementById('edit-mp-znr').value = znr;
  document.getElementById('edit-mp-mc').value = mc;
  document.getElementById('edit-mp-type').value = type;
  document.getElementById('edit-mp-jahresverbrauch').value = jahresverbrauch;
  document.getElementById('edit-mp-kwp').value = kwp;
  document.getElementById('edit-mp-geplant').value = geplant;
  toggleMpTypeFields('edit');
  document.getElementById('edit-mp-dialog').showModal();
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
