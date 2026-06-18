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
    </div>
    <button type="submit" class="btn btn-primary">Stammdaten speichern</button>
  </form>
</div>

<!-- Tarif -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Tarif</h3>
  <?php if ($tariff): ?>
    <p style="font-size:.8rem;color:#6b7280;margin-bottom:1rem">
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
        <span style="font-size:.8rem;color:#6b7280;margin-left:.5rem">§ 6 Abs 1 Z 27 UStG</span>
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
    <p style="color:#6b7280;font-size:.875rem">Keine Steuerkonfiguration vorhanden.</p>
  <?php endif; ?>
  <p style="margin-top:1rem;font-size:.8rem;color:#9ca3af">
    Für Änderungen an der Steuerkonfiguration wenden Sie sich an den Plattform-Administrator.
    Alle Änderungen werden historisiert damit alte Rechnungen korrekt bleiben.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
