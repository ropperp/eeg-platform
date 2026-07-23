<?php
$pageTitle = isset($member) ? 'Mitglied bearbeiten' : 'Mitglied anlegen';
$m = $member ?? [];
$action = isset($member) ? '/portal/members/' . $member['id'] . '/edit' : '/portal/members';
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:var(--gray-600);text-decoration:none">← Zurück</a>
  <h2 style="margin:0"><?= $pageTitle ?></h2>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>">
  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Persönliche Daten</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>Anrede (Geschlecht)</label>
        <select name="salutation">
          <option value="">—</option>
          <?php foreach (['Herr','Frau','Divers'] as $s): ?>
            <option value="<?= $s ?>" <?= ($m['salutation'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>E-Mail-Anrede</label>
        <?php $eam = $m['email_anrede_mode'] ?? $_POST['email_anrede_mode'] ?? 'auto'; ?>
        <select name="email_anrede_mode">
          <?php foreach ([
            'auto'    => 'Automatisch (aus Geschlecht)',
            'herr'    => 'Sehr geehrter Herr',
            'frau'    => 'Sehr geehrte Frau',
            'familie' => 'Sehr geehrte Familie',
          ] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $eam === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--gray-600)">Nur die Anrede in E-Mails. Nützlich, wenn z.&nbsp;B. die Ehefrau die
          Mails liest, der Vertrag aber auf den Mann läuft → „Sehr geehrte Familie". Der Nachname bleibt der des Vertragspartners.</small>
      </div>
      <div class="form-group">
        <label>Titel</label>
        <input type="text" name="titel" placeholder="Dr., Mag., Ing. …" value="<?= htmlspecialchars($m['titel'] ?? $_POST['titel'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Firma (optional)</label>
        <input type="text" name="company_name" value="<?= htmlspecialchars($m['company_name'] ?? $_POST['company_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Vorname <span style="color:#ef4444">*</span></label>
        <input type="text" name="first_name" required value="<?= htmlspecialchars($m['first_name'] ?? $_POST['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Nachname <span style="color:#ef4444">*</span></label>
        <input type="text" name="last_name" required value="<?= htmlspecialchars($m['last_name'] ?? $_POST['last_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Geburtsdatum</label>
        <input type="date" name="geburtsdatum" value="<?= htmlspecialchars($m['geburtsdatum'] ?? $_POST['geburtsdatum'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>E-Mail <span style="color:#ef4444">*</span></label>
        <input type="email" name="email" required value="<?= htmlspecialchars($m['email'] ?? $_POST['email'] ?? '') ?>">
        <?php if (!isset($member)): ?>
          <small style="color:var(--gray-600)">Wird für den Plattform-Login verwendet.</small>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($m['phone'] ?? $_POST['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stromlieferant</label>
        <input type="text" name="stromlieferant" value="<?= htmlspecialchars($m['stromlieferant'] ?? $_POST['stromlieferant'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Adresse</h3>
    <div class="grid-2">
      <div class="form-group" style="grid-column:1/-1">
        <label>Straße &amp; Hausnummer <span style="color:#ef4444">*</span></label>
        <input type="text" name="address" required value="<?= htmlspecialchars($m['address'] ?? $_POST['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>PLZ <span style="color:#ef4444">*</span></label>
        <input type="text" name="zip" required value="<?= htmlspecialchars($m['zip'] ?? $_POST['zip'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Ort <span style="color:#ef4444">*</span></label>
        <input type="text" name="city" required value="<?= htmlspecialchars($m['city'] ?? $_POST['city'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Mitgliedschaft</h3>
    <div class="grid-2">
      <?php if (isset($member)): ?>
      <div class="form-group">
        <label>Kundennummer</label>
        <input type="text" value="<?= htmlspecialchars((string)($m['kundennummer'] ?? '—')) ?>" disabled
               style="background:var(--gray-100);font-weight:600;color:#15803d">
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Mitglied seit <span style="color:#ef4444">*</span></label>
        <input type="date" name="member_since" required
               value="<?= htmlspecialchars($m['member_since'] ?? $_POST['member_since'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="form-group">
        <label>Mitglied bis</label>
        <input type="date" name="member_until"
               value="<?= htmlspecialchars($m['member_until'] ?? $_POST['member_until'] ?? '2099-12-31') ?>">
        <small style="color:var(--gray-600)">Leer lassen = aktives Mitglied (wird auf 31.12.2099 gesetzt)</small>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Weitere Informationen</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>Stromspeicher</label>
        <select name="speicher_status">
          <?php $sp = $m['speicher_status'] ?? $_POST['speicher_status'] ?? ''; ?>
          <option value="">—</option>
          <option value="ja" <?= $sp === 'ja' ? 'selected' : '' ?>>Ja</option>
          <option value="nein" <?= $sp === 'nein' ? 'selected' : '' ?>>Nein</option>
          <option value="geplant" <?= $sp === 'geplant' ? 'selected' : '' ?>>Geplant</option>
        </select>
      </div>
      <div class="form-group">
        <label>Speichergröße (kWh)</label>
        <input type="number" step="0.1" name="speicher_kwh" value="<?= htmlspecialchars((string)($m['speicher_kwh'] ?? $_POST['speicher_kwh'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="andere_eeg" value="1" <?= !empty($m['andere_eeg'] ?? $_POST['andere_eeg'] ?? false) ? 'checked' : '' ?>
                 style="width:auto;display:inline-block;margin-right:.4rem">
          Bereits Mitglied in einer anderen EEG/BEG
        </label>
      </div>
      <div class="form-group">
        <label>Name/ID der anderen EEG (falls zutreffend)</label>
        <input type="text" name="andere_eeg_name" value="<?= htmlspecialchars($m['andere_eeg_name'] ?? $_POST['andere_eeg_name'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Bankverbindung</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>IBAN</label>
        <input type="text" name="member_iban" id="member_iban" placeholder="AT61 1904 3002 3457 3201"
               value="<?= htmlspecialchars($m['member_iban'] ?? $_POST['member_iban'] ?? '') ?>">
        <div id="iban-feedback" style="font-size:.78rem;margin-top:.35rem;min-height:1.1em"></div>
      </div>
      <div class="form-group">
        <label>BIC</label>
        <input type="text" name="member_bic" placeholder="OPSKATWW"
               value="<?= htmlspecialchars($m['member_bic'] ?? $_POST['member_bic'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Kontoinhaber:in</label>
        <input type="text" name="kontoinhaber" placeholder="falls abweichend vom Mitgliedsnamen"
               value="<?= htmlspecialchars($m['kontoinhaber'] ?? $_POST['kontoinhaber'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Adresse Kontoinhaber:in</label>
        <input type="text" name="konto_adresse" placeholder="falls abweichend von obiger Adresse"
               value="<?= htmlspecialchars($m['konto_adresse'] ?? $_POST['konto_adresse'] ?? '') ?>">
      </div>
      <?php if (isset($member) && !empty($m['mandatsreferenz'])): ?>
      <div class="form-group">
        <label>Mandatsreferenz</label>
        <input type="text" value="<?= htmlspecialchars($m['mandatsreferenz']) ?>" disabled style="background:var(--gray-100)">
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Rechnungsdaten</h3>
    <div class="form-group">
      <label>UID-Nummer (für Unternehmen)</label>
      <input type="text" name="invoice_uid" placeholder="ATU12345678"
             value="<?= htmlspecialchars($m['invoice_uid'] ?? $_POST['invoice_uid'] ?? '') ?>">
    </div>
  </div>

  <?php if (!isset($member)): ?>
  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Rechtliche Zustimmungen &amp; Erklärungen</h3>
    <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:1rem">
      Bitte erst anhaken, wenn das Mitglied die Beitrittserklärung unterschrieben hat.
      Alle sechs Punkte sind Pflicht, bevor das Mitglied angelegt werden kann.
    </p>
    <?php
    $consents = [
      'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft: Das Mitglied beantragt die Mitgliedschaft im Verein und nimmt die Vereinsstatuten zur Kenntnis.',
      'zustimmung_vollmacht'           => 'Vollmacht: Das Mitglied bevollmächtigt den Vorstand zur Zustimmungserklärung und Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber.',
      'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist: Das Mitglied stimmt zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt.',
      'zustimmung_email_kommunikation' => 'E-Mail-Rechnung/-Korrespondenz: Das Mitglied stimmt der Zustellung von Rechnungen und vereinsrelevanten Dokumenten per E-Mail zu.',
      'zustimmung_datenschutz'         => 'Datenschutz: Das Mitglied willigt in die Verarbeitung seiner Stamm-, Erzeugungs- und Verbrauchsdaten gemäß Datenschutzerklärung ein.',
      'zustimmung_agb'                 => 'AGB &amp; Tarif-/Preisblatt: Das Mitglied bestätigt, die geltenden Konditionen laut Preisliste und AGB gelesen und akzeptiert zu haben.',
    ];
    foreach ($consents as $field => $label):
    ?>
      <div class="form-group" style="margin-bottom:.6rem">
        <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:400">
          <input type="checkbox" name="<?= $field ?>" value="1" required
                 style="width:auto;margin-top:.2rem"
                 <?= !empty($_POST[$field] ?? false) ? 'checked' : '' ?>>
          <span style="font-size:.85rem"><?= $label ?></span>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:1rem">
    <button type="submit" class="btn btn-primary"><?= isset($member) ? 'Speichern' : 'Mitglied anlegen' ?></button>
    <a href="/portal/members" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Abbrechen</a>
  </div>
</form>

<script>
  // IBAN: Prüfziffer per Mod-97 (ISO 7064) validieren und in 4er-Blöcken anzeigen,
  // analog zur serverseitigen Prüfung in validateIban() (webapp/public/index.php).
  function ibanChecksumValid(rawIban) {
    const iban = rawIban.replace(/\s+/g, '').toUpperCase();
    if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(iban)) return false;
    const rearranged = iban.slice(4) + iban.slice(0, 4);
    let numeric = '';
    for (const ch of rearranged) {
      numeric += /[0-9]/.test(ch) ? ch : (ch.charCodeAt(0) - 55).toString();
    }
    let remainder = 0;
    for (const digit of numeric) {
      remainder = (remainder * 10 + parseInt(digit, 10)) % 97;
    }
    return remainder === 1;
  }
  function formatIbanGroups(rawIban) {
    const iban = rawIban.replace(/\s+/g, '').toUpperCase();
    return iban.match(/.{1,4}/g)?.join(' ') ?? '';
  }
  function updateIbanFeedback() {
    const input = document.getElementById('member_iban');
    const feedback = document.getElementById('iban-feedback');
    const raw = input.value.trim();
    if (raw === '') { feedback.textContent = ''; return; }
    const grouped = formatIbanGroups(raw);
    if (ibanChecksumValid(raw)) {
      feedback.innerHTML = '<span style="color:#16a34a">✓ ' + grouped + ' — IBAN gültig</span>';
    } else {
      feedback.innerHTML = '<span style="color:#dc2626">✗ ' + grouped + ' — IBAN ungültig (Prüfsumme stimmt nicht)</span>';
    }
  }
  document.getElementById('member_iban').addEventListener('input', updateIbanFeedback);
  updateIbanFeedback();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
