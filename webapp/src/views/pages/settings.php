<?php $pageTitle = 'EEG-Einstellungen'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">⚙️ EEG-Einstellungen</h2>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Stammdaten</h3>
  <table>
    <tr><th>Name</th><td><?= htmlspecialchars($community['name']) ?></td></tr>
    <tr><th>Marktpartner-ID</th><td><?= htmlspecialchars($community['marktpartner_id'] ?? '—') ?></td></tr>
    <tr><th>ZVR-Zahl</th><td><?= htmlspecialchars($community['zvr_number'] ?? '—') ?></td></tr>
    <tr><th>Adresse</th><td><?= htmlspecialchars($community['address'] ?? '—') ?></td></tr>
    <tr><th>IBAN</th><td><?= htmlspecialchars($community['iban'] ?? '—') ?></td></tr>
    <tr><th>BIC</th><td><?= htmlspecialchars($community['bic'] ?? '—') ?></td></tr>
  </table>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Aktueller Tarif (gültig ab <?= $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '—' ?>)</h3>
  <?php if ($tariff): ?>
  <table>
    <tr><th>Bezugstarif</th><td><?= number_format((float)$tariff['bezug_ct_kwh'], 4, ',', '.') ?> ct/kWh</td></tr>
    <tr><th>Einspeisevergütung</th><td><?= number_format((float)$tariff['einspeisung_ct_kwh'], 4, ',', '.') ?> ct/kWh</td></tr>
    <tr><th>Mitgliedsbeitrag</th><td><?= number_format((float)$tariff['mitgliedsbeitrag_eur'], 2, ',', '.') ?> EUR/Jahr</td></tr>
  </table>
  <?php else: ?>
    <p style="color:#6b7280;font-size:.875rem">Kein Tarif konfiguriert.</p>
  <?php endif; ?>
</div>

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
    Für Änderungen an Tarifen oder Steuerkonfiguration wenden Sie sich an den Plattform-Administrator.
    Alle Änderungen werden historisiert damit alte Rechnungen korrekt bleiben.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
