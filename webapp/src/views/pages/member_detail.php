<?php $pageTitle = 'Mitglied: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:var(--gray-600);text-decoration:none">← Mitgliederliste</a>
  <div style="position:relative">
    <img src="<?= htmlspecialchars(memberAvatarUrl($member['id'], $member['photo_path'], $member['salutation'])) ?>"
         alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover">
    <button type="button" onclick="document.getElementById('photo-dialog').showModal()"
            title="Profilbild ändern"
            style="position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;border-radius:50%;background:var(--gray-100);border:1px solid var(--gray-200);line-height:1;cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center"><?= icon('pencil-simple') ?></button>
  </div>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-gray" style="font-weight:700;color:#15803d">KdNr <?= htmlspecialchars((string)($member['kundennummer'] ?? '—')) ?></span>
  <?php $statusLabelTop = ['active' => 'Aktiv', 'pending' => 'Ausstehend', 'inactive' => icon('archive') . ' Archiviert']; ?>
  <?php $statusBadgeTop = ['active' => 'green', 'pending' => 'yellow', 'inactive' => 'gray']; ?>
  <span class="badge badge-<?= $statusBadgeTop[$member['status']] ?? 'yellow' ?>"><?= $statusLabelTop[$member['status']] ?? htmlspecialchars($member['status']) ?></span>
  <?php if (!empty($application)): ?>
  <span class="badge badge-blue" title="Über das Online-Beitrittsformular eingereicht"><?= icon('globe') ?> Online</span>
  <?php else: ?>
  <span class="badge badge-gray" title="Manuell angelegt, z. B. Beitrittserklärung offline per E-Mail"><?= icon('envelope-simple') ?> Offline</span>
  <?php endif; ?>
  <?php if (empty($member['user_id'])): ?>
  <span class="badge badge-gray" title="Kein Login-Konto vorhanden">Kein Zugang</span>
  <?php elseif (empty($member['last_login_at'])): ?>
  <span class="badge badge-yellow" title="Login-Konto vorhanden, aber noch nie eingeloggt">Noch nicht angemeldet</span>
  <?php else: ?>
  <span class="badge badge-gray" title="Letzter Login">Zuletzt angemeldet: <?= date('d.m.Y H:i', strtotime($member['last_login_at'])) ?></span>
  <?php endif; ?>
  <div style="margin-left:auto;display:flex;gap:.5rem">
    <?php $hasConsumer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'consumer' && in_array($mp['active'], [true, 't', '1', 1], true) && !empty($mp['zaehlpunkt_nr']))); ?>
    <?php $hasProducer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'producer' && in_array($mp['active'], [true, 't', '1', 1], true) && !empty($mp['zaehlpunkt_nr']))); ?>
    <?php if ($hasConsumer && contractsEnabled($member['community_id'])): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/bezug" target="_blank"
       class="btn" style="background:#1d4ed8;color:#fff;font-size:.8rem"><?= icon('file-text') ?> Bezugsvereinbarung</a>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/bezug/send" style="display:inline"
          onsubmit="return confirm('Bezugsvereinbarung jetzt endgültig an <?= htmlspecialchars(addslashes($member['email'])) ?> senden?')">
      <button type="submit" class="btn" style="background:#eff6ff;color:#1d4ed8;font-size:.8rem"><?= icon('envelope-simple') ?> Jetzt senden</button>
    </form>
    <?php if (!empty($member['contract_bezug_sent_at'])): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/bezug/reset" style="display:inline"
          onsubmit="return confirm('Bezugsvereinbarung zurücksetzen? Beim nächsten Versand wird das Mitglied darauf hingewiesen, dass die zuvor gesendete Fassung ab dann ungültig ist.')">
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-600);font-size:.8rem"><?= icon('arrow-clockwise') ?> Zurücksetzen</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($hasProducer && contractsEnabled($member['community_id'])): ?>
    <a href="/portal/members/<?= $member['id'] ?>/contract/einspeisung" target="_blank"
       class="btn" style="background:#b45309;color:#fff;font-size:.8rem"><?= icon('sun') ?> Einspeisevereinbarung</a>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/einspeisung/send" style="display:inline"
          onsubmit="return confirm('Einspeisevereinbarung jetzt endgültig an <?= htmlspecialchars(addslashes($member['email'])) ?> senden?')">
      <button type="submit" class="btn" style="background:#fffbeb;color:#b45309;font-size:.8rem"><?= icon('envelope-simple') ?> Jetzt senden</button>
    </form>
    <?php if (!empty($member['contract_einspeisung_sent_at'])): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/einspeisung/reset" style="display:inline"
          onsubmit="return confirm('Einspeisevereinbarung zurücksetzen? Beim nächsten Versand wird das Mitglied darauf hingewiesen, dass die zuvor gesendete Fassung ab dann ungültig ist.')">
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-600);font-size:.8rem"><?= icon('arrow-clockwise') ?> Zurücksetzen</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($hasConsumer && $hasProducer && contractsEnabled($member['community_id'])): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/contract/send-both" style="display:inline"
          onsubmit="return confirm('Bezugs- und Einspeisevereinbarung gemeinsam in einer E-Mail an <?= htmlspecialchars(addslashes($member['email'])) ?> senden?')">
      <button type="submit" class="btn btn-tint-green" style="font-size:.8rem"><?= icon('envelope-simple') ?> Beide gemeinsam senden</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($application)): ?>
    <a href="/portal/applications/<?= $application['id'] ?>/formular" target="_blank"
       class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"><?= icon('printer') ?> Formular ausdrucken (PDF)</a>
    <a href="/portal/applications/<?= $application['id'] ?>"
       class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"
       title="Eingereichte Daten der Beitrittserklärung ansehen/korrigieren (z.B. Zählpunktnummer)"><?= icon('pencil-simple') ?> Beitrittserklärung-Daten</a>
    <?php endif; ?>
    <a href="/portal/members/<?= $member['id'] ?>/edit"
       class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"><?= icon('pencil-simple') ?> Bearbeiten</a>
    <a href="/portal/members/<?= $member['id'] ?>/dsgvo-export"
       class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"
       title="Alle gespeicherten Daten dieses Mitglieds als JSON (DSGVO-Auskunftsersuchen, Art. 15)"><?= icon('lock-key') ?> DSGVO-Export</a>
    <a href="/portal/members/<?= $member['id'] ?>/jahresuebersicht" target="_blank"
       class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"
       title="Alle Rechnungen eines Jahres als druckbare Übersicht"><?= icon('calendar-blank') ?> Jahresübersicht</a>
    <?php if (!empty($member['user_id'])): ?>
    <form method="post" action="/portal/members/<?= $member['id'] ?>/reset-password" style="display:inline">
      <button type="submit" class="btn btn-tint-blue" style="font-size:.8rem"><?= icon('key') ?> Passwort zurücksetzen</button>
    </form>
    <?php endif; ?>
    <?php if (Auth::isPlatformAdmin()): ?>
      <?php if ($member['status'] === 'inactive'): ?>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/reactivate" style="display:inline"
            onsubmit="return confirm('Mitgliedschaft von <?= htmlspecialchars(addslashes($member['first_name'] . ' ' . $member['last_name'])) ?> wieder freigeben?')">
        <button type="submit" class="btn btn-tint-green" style="font-size:.8rem"><?= icon('check-circle') ?> Freigeben</button>
      </form>
      <?php else: ?>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/delete-login" style="display:inline"
            onsubmit="return confirmDangerDelete('Login-Konto von <?= htmlspecialchars(addslashes($member['first_name'] . ' ' . $member['last_name'])) ?> (das Mitglied selbst bleibt bestehen)')">
        <button type="submit" class="btn btn-tint-amber" style="font-size:.8rem"><?= icon('lock-key') ?> Login löschen</button>
      </form>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/deactivate" style="display:inline"
            onsubmit="return confirmDangerDelete('Mitglied <?= htmlspecialchars(addslashes($member['first_name'] . ' ' . $member['last_name'])) ?> wirklich — Daten/Verträge/Dateien bleiben aus Aufbewahrungspflicht erhalten, der Login wird gesperrt und eine Benachrichtigung per E-Mail verschickt')">
        <button type="submit" class="btn btn-tint-red" style="font-size:.8rem"><?= icon('trash') ?> Wirklich löschen</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (($_GET['success'] ?? '') === 'reset_sent'): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Link zum Passwort-Zurücksetzen wurde per E-Mail verschickt (10 Minuten gültig).</div>
<?php elseif (($_GET['success'] ?? '') === 'live_reset'): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Live-ESP-Messdaten für alle Zählpunkte dieses Mitglieds wurden gelöscht.</div>
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
    <h3 style="color:#15803d;margin-bottom:.75rem"><?= icon('check-circle') ?> Freigegeben — Login-Daten</h3>
    <?php if (!empty($successInviteError)): ?>
      <p style="margin-bottom:.5rem;color:#b91c1c;font-size:.85rem">Einladungs-E-Mail konnte nicht verschickt werden: <code style="font-size:.78rem"><?= htmlspecialchars($successInviteError) ?></code></p>
    <?php endif; ?>
    <p style="margin-bottom:.5rem">Bitte teilen Sie dem Mitglied folgende Zugangsdaten mit:</p>
    <table>
      <tr><th>E-Mail</th><td><code><?= htmlspecialchars($successEmail) ?></code></td></tr>
      <tr><th>Temporäres Passwort</th><td><code style="font-size:1.1rem;color:#15803d"><?= htmlspecialchars($successTempPw) ?></code></td></tr>
    </table>
    <p style="margin-top:.75rem;font-size:.8rem;color:var(--gray-600)">Das Mitglied sollte das Passwort nach dem ersten Login ändern. Diese Anzeige erscheint nur einmal.</p>
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
      <p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1rem">Noch keine Zählpunkte registriert.</p>
    <?php else: ?>
      <table style="margin-bottom:1.25rem;font-size:.85rem">
        <thead>
          <tr>
            <th>Zählpunktnummer (AT...)</th>
            <th>Zählernummer</th>
            <th>Typ</th>
            <th>Details</th>
            <th>ESP</th>
            <th>Zähler</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php $espOfflineMinutes = espOfflineAfterMinutes(); ?>
        <?php foreach ($metering_points as $mp): ?>
          <?php
            // "online" = esp_last_seen_at liegt nicht länger als die konfigurierte Schwelle
            // zurück (Platform-Admin -> ESP32 / Ausleseeinheiten) -- sonst könnte ein
            // hängengebliebenes Gerät für immer als online angezeigt werden. NICHT mehr
            // zusätzlich auf esp_online geprüft (Patrick, 19.08.2026: Status blinkte trotz
            // durchgehender Live-Daten alle 5s) -- esp_last_seen_at wird seit dem Fix in
            // insert_measurement() (mqtt-subscriber) bei JEDER Live-Messung mitgezogen, ist
            // also das zuverlässigere Signal. esp_online selbst ist nur eine Momentaufnahme des
            // zuletzt empfangenen Status-Heartbeats und kann durch dessen MQTT-Last-Will-
            // Testament bei einem kurzen Verbindungsaussetzer bis zum NÄCHSTEN Heartbeat (nicht:
            // bis zur nächsten Live-Nachricht) auf false hängen bleiben -- ein zusätzliches
            // esp_online-Erfordernis hätte also weiterhin genau die Fehlanzeige verursacht, die
            // eigentlich behoben werden sollte.
            $espEffectivelyOnline = !empty($mp['esp_last_seen_at'])
                && (time() - strtotime($mp['esp_last_seen_at'])) < $espOfflineMinutes * 60;
            // ESP-/Zähler-Spalten unten zeigen nur etwas an, wenn eine Zählernummer hinterlegt
            // ist: esp_last_seen_at/meter_reachable bleiben auf dem Zählpunkt stehen, auch wenn
            // die Zählernummer später entfernt wurde (Zähler außer Betrieb) -- ohne diese Sperre
            // stand dort weiterhin "Erreichbar"/"WLAN-Info", obwohl gar kein Zähler mehr
            // eingetragen ist (Patrick, 09.08.2026).
          ?>
          <tr>
            <td><code style="font-size:.75rem"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
            <td>
              <?php if ($mp['meter_code']): ?>
                <code style="font-size:.75rem;color:#16a34a"><?= htmlspecialchars($mp['meter_code']) ?></code>
              <?php else: ?>
                <span style="color:var(--gray-600);font-size:.8rem">—</span>
              <?php endif; ?>
            </td>
            <td><?= $mp['type'] === 'consumer' ? icon('arrow-down') . ' Bezug' : icon('arrow-up') . ' Einspeisung' ?></td>
            <td style="font-size:.78rem;color:var(--gray-600)">
              <?php if ($mp['type'] === 'consumer'): ?>
                <?= $mp['jahresverbrauch_kwh'] ? number_format((float)$mp['jahresverbrauch_kwh'], 0, ',', '.') . ' kWh/Jahr' : '—' ?>
              <?php else: ?>
                <?= $mp['engpassleistung_kw'] ? number_format((float)$mp['engpassleistung_kw'], 2, ',', '.') . ' kWp' : '—' ?>
                <?= $mp['geplante_einspeisung_kwh'] ? ' · ' . number_format((float)$mp['geplante_einspeisung_kwh'], 0, ',', '.') . ' kWh/Jahr geplant' : '' ?>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap">
              <?php if (empty($mp['meter_code'])): ?>
                <span style="color:var(--gray-600)">—</span>
              <?php elseif ($espEffectivelyOnline): ?>
                <span class="badge badge-green"><?= icon('check-circle') ?> Online</span>
              <?php elseif (!empty($mp['esp_last_seen_at'])): ?>
                <span class="badge badge-gray" title="Zuletzt online: <?= date('d.m.Y H:i', strtotime($mp['esp_last_seen_at'])) ?>">
                  Offline seit <?= date('d.m.Y H:i', strtotime($mp['esp_last_seen_at'])) ?>
                </span>
              <?php else: ?>
                <span class="badge badge-gray" style="color:var(--gray-600)">Keine ESP-Daten</span>
              <?php endif; ?>
              <?php if (!empty($mp['meter_code']) && !empty($mp['esp_last_seen_at'])): ?>
                <br>
                <?php
                  // Firmwareversion aus dem ESP-Heartbeat (esp_firmware_version) gegen die
                  // neueste bekannte GitHub-Release-Version vergleichen (latestFirmwareVersion(),
                  // 1h gecacht) -- Patrick will auf einen Blick sehen, ob ein Vor-Ort-Termin zum
                  // manuellen Aktualisieren nötig ist (12.08.2026). "unbekannt" heißt: das Gerät
                  // ist online, aber die Firmware ist zu alt, um die Version selbst mitzuschicken.
                  $fwVersion = $mp['esp_firmware_version'] ?? null;
                ?>
                <?php if ($fwVersion && $latestFirmwareVersion): ?>
                  <?php if (version_compare($fwVersion, $latestFirmwareVersion, '>=')): ?>
                    <span class="badge badge-green" style="font-size:.68rem" title="Neueste bekannte Version: <?= htmlspecialchars($latestFirmwareVersion) ?>">FW <?= htmlspecialchars($fwVersion) ?> · aktuell</span>
                  <?php else: ?>
                    <span class="badge badge-yellow" style="font-size:.68rem" title="Update vor Ort einspielen oder Auto-Update abwarten">FW <?= htmlspecialchars($fwVersion) ?> · Update auf <?= htmlspecialchars($latestFirmwareVersion) ?> verfügbar</span>
                  <?php endif; ?>
                <?php elseif ($fwVersion): ?>
                  <span class="badge badge-gray" style="font-size:.68rem;color:var(--gray-600)">FW <?= htmlspecialchars($fwVersion) ?></span>
                <?php else: ?>
                  <span class="badge badge-gray" style="font-size:.68rem;color:var(--gray-600)" title="Zu alte Firmware, um die Version zu melden">FW unbekannt</span>
                <?php endif; ?>
                <?php if (!Auth::isDemo()): ?>
                <br>
                <button type="button" onclick="showWifiInfo('<?= $member['id'] ?>','<?= $mp['id'] ?>')"
                        style="background:none;border:none;cursor:pointer;color:var(--gray-600);font-size:.72rem;padding:.15rem 0;text-decoration:underline">
                  WLAN-Info anzeigen
                </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap">
              <?php if (empty($mp['meter_code']) || empty($mp['esp_last_seen_at'])): ?>
                <span style="color:var(--gray-600)">—</span>
              <?php elseif (!empty($mp['meter_reachable'])): ?>
                <span class="badge badge-green"><?= icon('check-circle') ?> Erreichbar</span>
              <?php elseif (!empty($mp['meter_last_seen_at'])): ?>
                <span class="badge badge-red" title="Zuletzt erreichbar: <?= date('d.m.Y H:i', strtotime($mp['meter_last_seen_at'])) ?>">
                  Nicht erreichbar
                </span>
                <br><small style="color:var(--gray-600)">evtl. Inselbetrieb/Stromausfall beim Mitglied</small>
              <?php else: ?>
                <span class="badge badge-gray" style="color:var(--gray-600)">Keine Daten</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <button onclick="openEditMp('<?= $mp['id'] ?>','<?= htmlspecialchars($mp['zaehlpunkt_nr'],ENT_QUOTES) ?>','<?= htmlspecialchars($mp['meter_code']??'',ENT_QUOTES) ?>','<?= $mp['type'] ?>','<?= htmlspecialchars((string)($mp['jahresverbrauch_kwh']??''),ENT_QUOTES) ?>','<?= htmlspecialchars((string)($mp['engpassleistung_kw']??''),ENT_QUOTES) ?>','<?= htmlspecialchars((string)($mp['geplante_einspeisung_kwh']??''),ENT_QUOTES) ?>')"
                      style="background:none;border:none;cursor:pointer;color:var(--gray-600)"><?= icon('pencil-simple') ?></button>
              <form method="post" action="/portal/members/<?= $member['id'] ?>/metering-points/<?= $mp['id'] ?>/delete" style="display:inline">
                <button type="submit" onclick="return confirm('Zählpunkt wirklich deaktivieren?')"
                        style="background:none;border:none;cursor:pointer;color:#ef4444"><?= icon('trash') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (platformTestMode() && !empty($metering_points)): ?>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/reset-live-data"
            style="margin-bottom:1.25rem;padding-top:.75rem;border-top:1px solid var(--gray-200)">
        <button type="submit" class="btn btn-secondary" style="color:#ef4444"
                onclick="return confirm('Wirklich ALLE Live-ESP-Messdaten dieses Mitglieds unwiderruflich löschen? Betrifft alle Zählpunkte dieses Mitglieds (Leistungswerte, Zählerstände, WLAN-Status).')">
          <?= icon('trash') ?> Live-Messdaten zurücksetzen (Testphase)
        </button>
        <p style="font-size:.72rem;color:var(--gray-600);margin-top:.35rem">
          Löscht alle gespeicherten ESP-Messwerte für alle Zählpunkte dieses Mitglieds unwiderruflich
          und setzt Online-/WLAN-Status zurück. Nur im Testmodus sichtbar (Platform-Admin → Plattform-Technik).
        </p>
      </form>
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
            <option value="consumer">Bezug</option>
            <option value="producer">Einspeisung</option>
            <option value="prosumer">Bezug + Einspeisung (ein Zähler)</option>
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
<div class="card" style="font-size:.8rem;color:var(--gray-600);margin-bottom:1.5rem">
  <strong>MQTT-Topics (Live-Daten):</strong>
  <?php foreach ($uniqueMeterCodes as $mc): ?>
    <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid #e5e7eb">
      <div style="margin-bottom:.3rem">
        <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mc) ?>/live</code>
        <span style="color:var(--gray-600);font-size:.72rem;margin-left:.4rem">Legacy · pp/pm/ep/em/znr</span>
      </div>
      <div>
        <code>eeg/<?= htmlspecialchars($mqttId) ?>/meter/<?= htmlspecialchars($mc) ?>/power</code>
        <span style="color:var(--gray-600);font-size:.72rem;margin-left:.4rem">ESP · power_w/meter_reading/ts</span>
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
<?php if (!empty($contractTypes) && contractsEnabled($member['community_id'])): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('clipboard-text') ?> Vertragsstatus</h3>
  <div class="<?= count($contractTypes) === 1 ? '' : 'grid-2' ?>">
    <?php
    $statusLabels = ['none' => 'Nicht erstellt', 'created' => 'Erstellt', 'signed' => 'Unterschrieben'];
    $statusBadge  = ['none' => 'gray', 'created' => 'yellow', 'signed' => 'green'];
    foreach ($contractTypes as $type => $info):
      $cur = $member['contract_' . $type . '_status'] ?? 'none';
    ?>
    <?php $sentAt = $member['contract_' . $type . '_sent_at'] ?? null; ?>
    <div style="border:1px solid var(--gray-200);border-radius:8px;padding:.75rem 1rem">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <strong style="font-size:.9rem"><?= $info['label'] ?></strong>
        <span class="badge badge-<?= $statusBadge[$cur] ?? 'gray' ?>"><?= $statusLabels[$cur] ?></span>
      </div>
      <?php $signedAt = $member['contract_' . $type . '_signed_at'] ?? null; ?>
      <?php if ($signedAt): ?>
        <p style="font-size:.78rem;color:#15803d;margin:0">
          <?= icon('signature') ?> Digital unterschrieben am <?= date('d.m.Y H:i', strtotime($signedAt)) ?> im Mitgliederportal — gültig und sicher abgelegt.
        </p>
      <?php elseif ($sentAt): ?>
        <p style="font-size:.78rem;color:var(--gray-600);margin:0">
          <?= icon('envelope-simple') ?> Versendet am <?= date('d.m.Y H:i', strtotime($sentAt)) ?> — wartet auf digitale Unterschrift durch das Mitglied.
          Für Korrekturen oben bei „Jetzt senden" auf „<?= icon('arrow-clockwise') ?> Zurücksetzen" klicken.
        </p>
      <?php else: ?>
      <form method="post" action="/portal/members/<?= $member['id'] ?>/contract-status" style="display:flex;gap:.5rem;align-items:center">
        <input type="hidden" name="type" value="<?= $type ?>">
        <select name="status" style="flex:1;padding:.3rem .5rem;border:1px solid var(--gray-200);border-radius:6px;font-size:.8rem">
          <?php foreach ($statusLabels as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $cur === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="padding:.3rem .75rem;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:6px;cursor:pointer;font-size:.8rem">Speichern</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Dateien -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('paperclip') ?> Dateien</h3>

  <?php if (empty($member_files)): ?>
    <p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1rem">Noch keine Dateien hochgeladen.</p>
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
<dialog id="photo-dialog" style="border:1px solid var(--gray-200);border-radius:12px;padding:1.5rem;min-width:340px;box-shadow:0 8px 32px rgba(0,0,0,.1)">
  <h3 style="margin-bottom:1rem">Profilbild ändern</h3>
  <form method="post" action="/portal/members/<?= $member['id'] ?>/photo" enctype="multipart/form-data">
    <div class="form-group">
      <input type="file" name="photo" id="member-photo-input" accept="image/png,image/jpeg,image/webp" required>
    </div>
    <div id="member-photo-crop-wrapper" style="display:none;flex-direction:column;align-items:center;gap:.5rem;margin-bottom:1rem">
      <div style="width:220px;height:220px;border-radius:50%;overflow:hidden;border:2px solid #e5e7eb">
        <canvas id="member-photo-canvas" width="220" height="220" style="cursor:grab"></canvas>
      </div>
      <label style="font-size:.78rem;color:var(--gray-600);display:flex;align-items:center;gap:.5rem">
        <?= icon('magnifying-glass') ?> Zoom
        <input type="range" id="member-photo-zoom" min="100" max="300" value="100">
      </label>
      <small style="color:var(--gray-600)">Zum Verschieben im Bild ziehen.</small>
    </div>
    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" onclick="document.getElementById('photo-dialog').close()" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Abbrechen</button>
      <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
  </form>
</dialog>

<!-- Edit-Modal -->
<dialog id="edit-mp-dialog" style="border:1px solid var(--gray-200);border-radius:12px;padding:1.5rem;min-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.1)">
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
        <option value="consumer">Bezug</option>
        <option value="producer">Einspeisung</option>
        <option value="prosumer">Bezug + Einspeisung (ein Zähler)</option>
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
      <button type="button" onclick="document.getElementById('edit-mp-dialog').close()" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Abbrechen</button>
    </div>
  </form>
</dialog>

<script src="/assets/js/avatar-crop.js"></script>
<script>
  initAvatarCropper({
    fileInputId: 'member-photo-input',
    wrapperId: 'member-photo-crop-wrapper',
    canvasId: 'member-photo-canvas',
    zoomId: 'member-photo-zoom',
  });

function toggleMpTypeFields(prefix) {
  const type = document.getElementById(prefix + '-mp-type').value;
  // prosumer = ein physischer Zähler mit Bezug UND Einspeisung -- beide Feldgruppen zeigen.
  document.getElementById(prefix + '-mp-consumer-fields').style.display = (type === 'producer') ? 'none' : '';
  document.getElementById(prefix + '-mp-producer-fields').style.display = (type === 'consumer') ? 'none' : '';
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

// WLAN-Diagnoseinfos (SSID/IP/Passwort) erst auf Klick abrufen -- landet so nicht unnötig im
// initialen HTML (siehe docs/ESP_IDEEN.md Punkt 1, Sicherheitshinweis zum WLAN-Passwort).
// Zurückgebaut auf die Popup-Variante (Patrick, 23.08.2026: "Platz sparen" -- SSID/IP/Passwort
// gemeinsam inline in der Tabelle war zu viel Text) -- für den Demo-Account fehlt der Button
// dafür jetzt komplett (siehe PHP oben), nicht nur der Inhalt: es soll nicht einmal erkennbar
// sein, dass sich WLAN-Zugangsdaten überhaupt nachsehen ließen.
async function showWifiInfo(memberId, mpId) {
  const res = await fetch('/portal/members/' + memberId + '/metering-points/' + mpId + '/wifi-info');
  const d = await res.json();
  if (d.error) { alert(d.error); return; }
  if (!d.ssid && !d.ip && !d.password) {
    alert('Noch keine WLAN-Diagnosedaten von diesem ESP übermittelt.');
    return;
  }
  let fwLine = 'Firmware: ' + (d.firmware_version || 'unbekannt (zu alte Firmware)');
  if (d.firmware_version && d.latest_version) {
    fwLine += d.firmware_version === d.latest_version ? ' (aktuell)' : ' (Update auf ' + d.latest_version + ' verfügbar)';
  }
  alert('WLAN-Diagnose\n\nSSID: ' + (d.ssid || '—') + '\nIP-Adresse: ' + (d.ip || '—') + '\nWLAN-Passwort: ' + (d.password || '—') + '\n' + fwLine);
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
