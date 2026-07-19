<?php $pageTitle = 'EEG-Einstellungen'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">⚙️ EEG-Einstellungen</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Einstellungen gespeichert.</div>
<?php endif; ?>

<!-- Stammdaten -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Stammdaten</h3>
  <form method="post" action="/portal/settings/community">
    <div class="grid-2">
      <div class="form-group">
        <label>Name der EEG</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($community['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Marktpartner-ID</label>
        <input type="text" name="marktpartner_id" value="<?= htmlspecialchars($community['marktpartner_id'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>ZVR-Zahl</label>
        <input type="text" name="zvr_number" value="<?= htmlspecialchars($community['zvr_number'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Adresse</label>
        <input type="text" name="address" value="<?= htmlspecialchars($community['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>IBAN</label>
        <input type="text" name="iban" value="<?= htmlspecialchars($community['iban'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>BIC</label>
        <input type="text" name="bic" value="<?= htmlspecialchars($community['bic'] ?? '') ?>">
      </div>
      <div class="form-group" style="grid-column:1 / -1">
        <label>Mitgliederportal-Link (im Bezugsvertrag als Verweis auf die Erzeugungsanlagen-Liste)</label>
        <input type="text" name="dashboard_url" placeholder="https://portal.stromfueralle.at/portal/login" value="<?= htmlspecialchars($community['dashboard_url'] ?? '') ?>">
        <small style="color:var(--gray-600)">Frei änderbar, falls sich die Verlinkung ändert. Leer lassen für den Standard-Link.</small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Stammdaten speichern</button>
  </form>
</div>

<!-- Logo für Rechnungen/Verträge -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Logo für Rechnungen/Verträge</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Erscheint auf Rechnungen (und künftig weiteren PDF-Dokumenten) dieser EEG. Ohne eigenes Logo wird
    ersatzweise das Website-Logo verwendet. Getrennt vom Website-Logo unter Plattform-Admin -&gt; Dateien,
    das für alle Besucher der Website gilt.
  </p>
  <?php if ($hasCustomLogo): ?>
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
      <img src="/portal/settings/logo/preview?v=<?= time() ?>" alt="Logo" style="max-height:60px;background:var(--gray-100);padding:.5rem;border-radius:6px">
      <span class="badge badge-yellow">Eigenes Logo aktiv</span>
    </div>
  <?php endif; ?>
  <form method="post" action="/portal/settings/logo" enctype="multipart/form-data" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <input type="file" name="logo" accept=".png,.jpg,.jpeg" required>
    <button type="submit" class="btn btn-primary">Hochladen</button>
    <?php if ($hasCustomLogo): ?>
      <button type="submit" formaction="/portal/settings/logo/delete" formnovalidate class="btn" style="background:#fee2e2;color:#b91c1c">Entfernen (Standard-Logo verwenden)</button>
    <?php endif; ?>
  </form>
</div>

<!-- Tarif -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Tarif</h3>
  <?php if ($tariff): ?>
    <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:1rem">
      Aktuell gültig ab <?= date('d.m.Y', strtotime($tariff['valid_from'])) ?>:
      Bezug <?= number_format((float)$tariff['bezug_ct_kwh'], 4, ',', '.') ?> ct/kWh ·
      Einspeisung <?= number_format((float)$tariff['einspeisung_ct_kwh'], 4, ',', '.') ?> ct/kWh ·
      Mitgliedsbeitrag <?= number_format((float)$tariff['mitgliedsbeitrag_eur'], 2, ',', '.') ?> EUR/Jahr
    </p>
  <?php endif; ?>
  <form method="post" action="/portal/settings/tariff">
    <p style="font-size:.8rem;color:#92400e;margin-bottom:1rem">
      ⚠️ Ein neuer Tarif wird ab dem angegebenen Datum gültig. Der alte Tarif bleibt für vergangene Abrechnungen erhalten.
    </p>
    <div class="grid-2">
      <div class="form-group">
        <label>Gültig ab</label>
        <input type="date" name="valid_from" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label>Bezugstarif (ct/kWh)</label>
        <input type="text" name="bezug_ct_kwh" placeholder="12.00" value="<?= htmlspecialchars($tariff['bezug_ct_kwh'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Einspeisevergütung (ct/kWh)</label>
        <input type="text" name="einspeisung_ct_kwh" placeholder="8.00" value="<?= htmlspecialchars($tariff['einspeisung_ct_kwh'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Mitgliedsbeitrag (EUR/Jahr)</label>
        <input type="text" name="mitgliedsbeitrag_eur" placeholder="24.00" value="<?= htmlspecialchars($tariff['mitgliedsbeitrag_eur'] ?? '') ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Neuen Tarif anlegen</button>
  </form>
</div>

<!-- Steuerkonfiguration -->
<div class="card">
  <h3 style="margin-bottom:1rem">Steuerkonfiguration</h3>
  <?php if ($tax): ?>
  <table>
    <tr><th>Steuermodell</th><td>
      <?php if ($tax['tax_model'] === 'kleinunternehmer'): ?>
        <span class="badge badge-green">Kleinunternehmer</span>
        <span style="font-size:.8rem;color:var(--gray-600);margin-left:.5rem">§ 6 Abs 1 Z 27 UStG</span>
      <?php else: ?>
        <span class="badge badge-yellow">Standard (<?= $tax['tax_rate_percent'] ?>% USt)</span>
      <?php endif; ?>
    </td></tr>
    <?php if ($tax['uid_number']): ?>
      <tr><th>UID</th><td><?= htmlspecialchars($tax['uid_number']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Gültig ab</th><td><?= date('d.m.Y', strtotime($tax['valid_from'])) ?></td></tr>
  </table>
  <?php else: ?>
    <p style="color:var(--gray-600);font-size:.875rem">Keine Steuerkonfiguration vorhanden.</p>
  <?php endif; ?>
  <form method="post" action="/portal/settings/tax" style="margin-top:1rem">
    <p style="font-size:.8rem;color:#92400e;margin-bottom:1rem">
      ⚠️ Eine neue Steuerkonfiguration wird ab dem angegebenen Datum gültig und historisiert,
      damit bereits erstellte Rechnungen korrekt bleiben.
    </p>
    <div class="grid-2">
      <div class="form-group">
        <label>Gültig ab</label>
        <input type="date" name="valid_from" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label>Steuermodell</label>
        <select name="tax_model" id="tax-model-select" onchange="document.getElementById('tax-rate-field').style.display = this.value === 'standard' ? 'block' : 'none'">
          <option value="kleinunternehmer" <?= ($tax['tax_model'] ?? '') === 'kleinunternehmer' ? 'selected' : '' ?>>Kleinunternehmer (§ 6 Abs 1 Z 27 UStG)</option>
          <option value="standard" <?= ($tax['tax_model'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard (mit USt-Ausweis)</option>
        </select>
      </div>
      <div class="form-group" id="tax-rate-field" style="<?= ($tax['tax_model'] ?? '') === 'standard' ? '' : 'display:none' ?>">
        <label>USt-Satz (%)</label>
        <input type="text" name="tax_rate_percent" placeholder="20.00" value="<?= htmlspecialchars($tax['tax_rate_percent'] ?? '20') ?>">
      </div>
      <div class="form-group">
        <label>UID-Nummer (falls USt-pflichtig)</label>
        <input type="text" name="uid_number" placeholder="ATU12345678" value="<?= htmlspecialchars($tax['uid_number'] ?? '') ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Neue Steuerkonfiguration anlegen</button>
  </form>
</div>

<!-- Unterschrift für Verträge -->
<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.5rem">Unterschrift für Verträge</h3>
  <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:1rem">
    Wird beim Erzeugen von Bezugs-/Einspeisevereinbarungen über der Unterschriftslinie
    "Für die EEG" eingefügt. Bitte unten einmalig mit Maus oder Finger unterschreiben —
    die Unterschrift wird als <?= htmlspecialchars($myUser['first_name'] . ' ' . $myUser['last_name']) ?>
    (Ihr Konto) gespeichert.
  </p>

  <?php if (!empty($myUser['signature_image'])): ?>
    <p style="font-size:.85rem;font-weight:600;margin-bottom:.5rem">Vorschau im Vertrag:</p>
    <div style="border:1px dashed #d1d5db;border-radius:8px;padding:1rem 1.5rem;max-width:320px;margin-bottom:1rem">
      <img src="<?= htmlspecialchars($myUser['signature_image']) ?>" alt="Unterschrift" style="max-height:70px;max-width:100%;display:block;margin-bottom:.3rem">
      <div style="border-top:1px solid #111827;padding-top:.3rem;font-size:.75rem;color:var(--gray-700)">
        Für die EEG – Obmann/Obfrau<br><?= htmlspecialchars($community['name'] ?? '') ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="/portal/settings/signature" id="signature-form">
    <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:.5rem">Neu unterschreiben:</p>
    <canvas id="sig-pad-settings" width="600" height="180"
            style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:400px;height:120px;touch-action:none;background:#fff;display:block;margin-bottom:.5rem"></canvas>
    <input type="hidden" name="signature_image" id="signature_image_settings">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700)" onclick="clearSettingsSignature()">Löschen</button>
      <button type="submit" class="btn btn-primary">Unterschrift speichern</button>
      <?php if (!empty($myUser['signature_image'])): ?>
        <button type="submit" formaction="/portal/settings/signature/delete" formnovalidate class="btn" style="background:#fee2e2;color:#b91c1c">Unterschrift entfernen</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
(function() {
  const canvas = document.getElementById('sig-pad-settings');
  const ctx = canvas.getContext('2d');
  ctx.strokeStyle = '#00008B'; /* dunkelblau, wie vom Verein vorgegeben */
  ctx.lineWidth = 2.5;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  let drawing = false, hasSignature = false;

  function pos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const point = e.touches ? e.touches[0] : e;
    return { x: (point.clientX - rect.left) * scaleX, y: (point.clientY - rect.top) * scaleY };
  }
  function start(e) {
    e.preventDefault();
    drawing = true;
    hasSignature = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }
  function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }
  function stop() { drawing = false; }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', stop);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', stop);

  window.clearSettingsSignature = function() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSignature = false;
  };

  document.getElementById('signature-form').addEventListener('submit', function(e) {
    if (e.submitter && e.submitter.formAction && e.submitter.formAction.includes('/delete')) return;
    if (!hasSignature) {
      e.preventDefault();
      alert('Bitte unterschreiben Sie im Feld, bevor Sie speichern.');
      return;
    }
    document.getElementById('signature_image_settings').value = canvas.toDataURL('image/png');
  });
})();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
