<?php
$a = $application;
$pageTitle = 'Beitrittserklärung: ' . $a['first_name'] . ' ' . $a['last_name'];
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/applications" style="color:var(--gray-600);text-decoration:none">← Neuanmeldungen</a>
  <h2 style="margin:0"><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></h2>
  <?php $sb = ['pending' => 'yellow', 'approved' => 'green', 'rejected' => 'gray']; ?>
  <span class="badge badge-<?= $sb[$a['status']] ?? 'gray' ?>"><?= htmlspecialchars($a['status']) ?></span>
  <a href="/portal/applications/<?= $a['id'] ?>/formular" target="_blank"
     class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem;margin-left:auto">🖨️ Formular ausdrucken (PDF)</a>
</div>

<div class="grid-2" style="gap:1.5rem;margin-bottom:1.5rem">
  <div class="card">
    <h3 style="margin-bottom:1rem">Stammdaten</h3>
    <table>
      <tr><th>Anrede</th><td><?= htmlspecialchars($a['salutation'] ?? '—') ?></td></tr>
      <tr><th>Titel</th><td><?= htmlspecialchars($a['titel'] ?? '—') ?></td></tr>
      <tr><th>Geburtsdatum</th><td><?= $a['geburtsdatum'] ? date('d.m.Y', strtotime($a['geburtsdatum'])) : '—' ?></td></tr>
      <tr><th>E-Mail</th><td><?= htmlspecialchars($a['email']) ?></td></tr>
      <tr><th>Telefon</th><td><?= htmlspecialchars($a['phone'] ?? '—') ?></td></tr>
      <tr><th>Adresse</th><td><?= htmlspecialchars($a['address'] . ', ' . $a['zip'] . ' ' . $a['city']) ?></td></tr>
      <tr><th>Stromlieferant</th><td><?= htmlspecialchars($a['stromlieferant'] ?? '—') ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem">Teilnahme &amp; Weitere Infos</h3>
    <table>
      <tr><th>Bezug gewünscht</th><td><?= in_array($a['bezug_gewuenscht'], [true, 't', '1', 1], true) ? 'Ja' : 'Nein' ?></td></tr>
      <tr><th>Zählpunkt (Bezug)</th><td><?= htmlspecialchars($a['bezug_zaehlpunkt'] ?? '—') ?></td></tr>
      <tr><th>Jahresverbrauch</th><td><?= $a['bezug_jahresverbrauch_kwh'] ? number_format((float)$a['bezug_jahresverbrauch_kwh'], 0, ',', '.') . ' kWh' : '—' ?></td></tr>
      <tr><th>Einspeisung gewünscht</th><td><?= in_array($a['einspeisung_gewuenscht'], [true, 't', '1', 1], true) ? 'Ja' : 'Nein' ?></td></tr>
      <tr><th>Zählpunkt (Einspeisung)</th><td><?= htmlspecialchars($a['einspeisung_zaehlpunkt'] ?? '—') ?></td></tr>
      <tr><th>Anlagenleistung</th><td><?= $a['einspeisung_kwp'] ? number_format((float)$a['einspeisung_kwp'], 2, ',', '.') . ' kWp' : '—' ?></td></tr>
      <tr><th>Speicher</th><td><?= htmlspecialchars($a['speicher_status'] ?? '—') ?><?= $a['speicher_kwh'] ? ' (' . number_format((float)$a['speicher_kwh'], 1, ',', '.') . ' kWh)' : '' ?></td></tr>
      <tr><th>Andere EEG/BEG</th><td><?= in_array($a['andere_eeg'], [true, 't', '1', 1], true) ? htmlspecialchars($a['andere_eeg_name'] ?? 'Ja') : 'Nein' ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem">Bankverbindung</h3>
    <table>
      <tr><th>IBAN</th><td><?= htmlspecialchars($a['iban'] ?? '—') ?></td></tr>
      <tr><th>BIC</th><td><?= htmlspecialchars($a['bic'] ?? '—') ?></td></tr>
      <tr><th>Kontoinhaber:in</th><td><?= htmlspecialchars($a['kontoinhaber'] ?? '—') ?></td></tr>
      <tr><th>Adresse Kontoinhaber:in</th><td><?= htmlspecialchars($a['konto_adresse'] ?? '—') ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem">Rechtliche Zustimmungen</h3>
    <?php
    $consentLabels = [
      'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft',
      'zustimmung_vollmacht'           => 'Vollmacht',
      'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist',
      'zustimmung_email_kommunikation' => 'E-Mail-Rechnung/-Korrespondenz',
      'zustimmung_datenschutz'         => 'Datenschutz',
      'zustimmung_agb'                 => 'AGB & Tarif-/Preisblatt',
    ];
    ?>
    <ul style="padding-left:1.2rem">
      <?php foreach ($consentLabels as $field => $label): ?>
        <li style="font-size:.85rem;margin-bottom:.3rem">
          <?= in_array($a[$field], [true, 't', '1', 1], true) ? '✅' : '❌' ?> <?= htmlspecialchars($label) ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p style="font-size:.8rem;color:var(--gray-600);margin-top:.5rem">
      Unterschrieben am <?= $a['signed_at'] ? date('d.m.Y H:i', strtotime($a['signed_at'])) : '—' ?>
      <?php if (!empty($a['signer_ip'])): ?> von IP <?= htmlspecialchars($a['signer_ip']) ?><?php endif; ?>
    </p>
  </div>
</div>

<?php if (!empty($a['signature_image'])): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Unterschrift Beitrittserklärung</h3>
  <img src="<?= htmlspecialchars($a['signature_image']) ?>" alt="Unterschrift" style="max-width:400px;border:1px solid var(--gray-200);border-radius:8px;background:#fff">
</div>
<?php endif; ?>

<?php if (!empty($a['sepa_signature_image'])): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Unterschrift SEPA-Lastschriftmandat</h3>
  <img src="<?= htmlspecialchars($a['sepa_signature_image']) ?>" alt="SEPA-Unterschrift" style="max-width:400px;border:1px solid var(--gray-200);border-radius:8px;background:#fff">
  <p style="font-size:.8rem;color:var(--gray-600);margin-top:.5rem">
    Unterschrieben am <?= $a['sepa_signed_at'] ? date('d.m.Y H:i', strtotime($a['sepa_signed_at'])) : '—' ?>
  </p>
</div>
<?php endif; ?>

<?php if ($a['status'] === 'pending'): ?>
<div class="card">
  <h3 style="margin-bottom:1rem">Entscheidung</h3>
  <div style="display:flex;gap:1rem;flex-wrap:wrap">
    <form method="post" action="/portal/applications/<?= $a['id'] ?>/approve" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap"
          onsubmit="return confirm('Mitgliedschaft freigeben? Es wird automatisch ein Mitgliedskonto inkl. Kundennummer angelegt.')">
      <label style="font-size:.85rem">E-Mail-Anrede
        <select name="email_anrede_mode" style="display:block;padding:.4rem .6rem;border:1px solid var(--gray-200);border-radius:6px">
          <option value="auto" selected>Automatisch (aus Geschlecht)</option>
          <option value="herr">Sehr geehrter Herr</option>
          <option value="frau">Sehr geehrte Frau</option>
          <option value="familie">Sehr geehrte Familie</option>
        </select>
      </label>
      <button type="submit" class="btn btn-primary">✅ Freigeben &amp; Mitglied anlegen</button>
    </form>
    <form method="post" action="/portal/applications/<?= $a['id'] ?>/reject" style="display:flex;gap:.5rem;align-items:center"
          onsubmit="return confirm('Beitrittserklärung wirklich ablehnen?')">
      <input type="text" name="ablehnungsgrund" placeholder="Ablehnungsgrund (optional)"
             style="padding:.4rem .75rem;border:1px solid var(--gray-200);border-radius:6px">
      <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c">❌ Ablehnen</button>
    </form>
  </div>
</div>
<?php elseif ($a['status'] === 'approved'): ?>
<div class="alert alert-success">
  Freigegeben<?= !empty($a['bearbeitet_am']) ? ' am ' . date('d.m.Y H:i', strtotime($a['bearbeitet_am'])) : '' ?>.
  <?php if (!empty($a['member_id'])): ?>
    <a href="/portal/members/<?= $a['member_id'] ?>">Zum Mitgliedskonto</a>
  <?php endif; ?>
</div>
<?php elseif ($a['status'] === 'rejected'): ?>
<div class="alert alert-error">
  Abgelehnt<?= !empty($a['bearbeitet_am']) ? ' am ' . date('d.m.Y H:i', strtotime($a['bearbeitet_am'])) : '' ?>.
  <?php if (!empty($a['ablehnungsgrund'])): ?> Grund: <?= htmlspecialchars($a['ablehnungsgrund']) ?><?php endif; ?>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
