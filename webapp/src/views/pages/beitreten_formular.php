<?php
$pageTitle = 'Online-Beitrittserklärung — ' . $community['name'];
$d = $_POST ?? [];
ob_start();
?>

<div class="legal" style="max-width:900px">
  <h1>Online-Beitrittserklärung</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?></div>

  <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="margin-bottom:1.5rem"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/beitreten/formular" id="beitritt-form">
    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Persönliche Daten</h3>
      <div class="grid-2">
        <div class="form-group">
          <label>Anrede</label>
          <select name="salutation">
            <option value="">—</option>
            <?php foreach (['Herr','Frau','Divers'] as $s): ?>
              <option value="<?= $s ?>" <?= ($d['salutation'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Titel</label>
          <input type="text" name="titel" placeholder="Dr., Mag., Ing. …" value="<?= htmlspecialchars($d['titel'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Vorname <span style="color:#ef4444">*</span></label>
          <input type="text" name="first_name" required value="<?= htmlspecialchars($d['first_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Nachname <span style="color:#ef4444">*</span></label>
          <input type="text" name="last_name" required value="<?= htmlspecialchars($d['last_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Geburtsdatum</label>
          <input type="date" name="geburtsdatum" value="<?= htmlspecialchars($d['geburtsdatum'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>E-Mail <span style="color:#ef4444">*</span></label>
          <input type="email" name="email" required value="<?= htmlspecialchars($d['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Telefon</label>
          <input type="tel" name="phone" value="<?= htmlspecialchars($d['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Stromlieferant</label>
          <input type="text" name="stromlieferant" value="<?= htmlspecialchars($d['stromlieferant'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Adresse</h3>
      <div class="grid-2">
        <div class="form-group" style="grid-column:1/-1">
          <label>Straße &amp; Hausnummer <span style="color:#ef4444">*</span></label>
          <input type="text" name="address" required value="<?= htmlspecialchars($d['address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>PLZ <span style="color:#ef4444">*</span></label>
          <input type="text" name="zip" required value="<?= htmlspecialchars($d['zip'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Ort <span style="color:#ef4444">*</span></label>
          <input type="text" name="city" required value="<?= htmlspecialchars($d['city'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Teilnahme an der Energiegemeinschaft</h3>
      <div class="grid-2">
        <div class="form-group">
          <label>
            <input type="checkbox" name="bezug_gewuenscht" value="1" style="width:auto;display:inline-block;margin-right:.4rem"
                   <?= !empty($d['bezug_gewuenscht']) ? 'checked' : '' ?>>
            Ich möchte Strom aus der Gemeinschaft beziehen
          </label>
        </div>
        <div class="form-group">
          <label>Jahresverbrauch (kWh), falls bekannt</label>
          <input type="text" name="bezug_jahresverbrauch_kwh" value="<?= htmlspecialchars($d['bezug_jahresverbrauch_kwh'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>
            <input type="checkbox" name="einspeisung_gewuenscht" value="1" style="width:auto;display:inline-block;margin-right:.4rem"
                   <?= !empty($d['einspeisung_gewuenscht']) ? 'checked' : '' ?>>
            Ich möchte Strom in die Gemeinschaft einspeisen
          </label>
        </div>
        <div class="form-group">
          <label>Anlagenleistung (kWp), falls bekannt</label>
          <input type="text" name="einspeisung_kwp" value="<?= htmlspecialchars($d['einspeisung_kwp'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Geplante Einspeisung (kWh/Jahr)</label>
          <input type="text" name="einspeisung_geplante_kwh" value="<?= htmlspecialchars($d['einspeisung_geplante_kwh'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Weitere Informationen</h3>
      <div class="grid-2">
        <div class="form-group">
          <label>Stromspeicher</label>
          <select name="speicher_status">
            <?php $sp = $d['speicher_status'] ?? ''; ?>
            <option value="">—</option>
            <option value="ja" <?= $sp === 'ja' ? 'selected' : '' ?>>Ja</option>
            <option value="nein" <?= $sp === 'nein' ? 'selected' : '' ?>>Nein</option>
            <option value="geplant" <?= $sp === 'geplant' ? 'selected' : '' ?>>Geplant</option>
          </select>
        </div>
        <div class="form-group">
          <label>Speichergröße (kWh)</label>
          <input type="text" name="speicher_kwh" value="<?= htmlspecialchars($d['speicher_kwh'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>
            <input type="checkbox" name="andere_eeg" value="1" style="width:auto;display:inline-block;margin-right:.4rem"
                   <?= !empty($d['andere_eeg']) ? 'checked' : '' ?>>
            Bereits Mitglied in einer anderen EEG/BEG
          </label>
        </div>
        <div class="form-group">
          <label>Name/ID der anderen EEG (falls zutreffend)</label>
          <input type="text" name="andere_eeg_name" value="<?= htmlspecialchars($d['andere_eeg_name'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Bankverbindung (für SEPA-Lastschrift/Überweisung)</h3>
      <div class="grid-2">
        <div class="form-group">
          <label>IBAN</label>
          <input type="text" name="member_iban" placeholder="AT61 1904 3002 3457 3201" value="<?= htmlspecialchars($d['member_iban'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>BIC</label>
          <input type="text" name="member_bic" placeholder="OPSKATWW" value="<?= htmlspecialchars($d['member_bic'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Kontoinhaber:in</label>
          <input type="text" name="kontoinhaber" placeholder="falls abweichend vom eigenen Namen" value="<?= htmlspecialchars($d['kontoinhaber'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Adresse Kontoinhaber:in</label>
          <input type="text" name="konto_adresse" placeholder="falls abweichend von obiger Adresse" value="<?= htmlspecialchars($d['konto_adresse'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Rechtliche Zustimmungen &amp; Erklärungen</h3>
      <p style="font-size:.8rem;color:#6b7280;margin-bottom:1rem">Alle sechs Punkte sind Pflicht, bevor die Beitrittserklärung übermittelt werden kann.</p>
      <?php
      $consents = [
        'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft: Ich beantrage die Mitgliedschaft im Verein und nehme die Vereinsstatuten zur Kenntnis.',
        'zustimmung_vollmacht'           => 'Vollmacht: Ich bevollmächtige den Vorstand zur Zustimmungserklärung und Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber.',
        'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist: Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt.',
        'zustimmung_email_kommunikation' => 'E-Mail-Rechnung/-Korrespondenz: Ich stimme der Zustellung von Rechnungen und vereinsrelevanten Dokumenten per E-Mail zu.',
        'zustimmung_datenschutz'         => 'Datenschutz: Ich willige in die Verarbeitung meiner Stamm-, Erzeugungs- und Verbrauchsdaten gemäß Datenschutzerklärung ein.',
        'zustimmung_agb'                 => 'AGB &amp; Tarif-/Preisblatt: Ich bestätige, die geltenden Konditionen laut Preisliste und AGB gelesen und akzeptiert zu haben.',
      ];
      foreach ($consents as $field => $label):
      ?>
        <div class="form-group" style="margin-bottom:.6rem">
          <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:400">
            <input type="checkbox" name="<?= $field ?>" value="1" required style="width:auto;margin-top:.2rem"
                   <?= !empty($d[$field]) ? 'checked' : '' ?>>
            <span style="font-size:.85rem"><?= $label ?></span>
          </label>
        </div>
      <?php endforeach; ?>
      <p style="font-size:.8rem;margin-top:.75rem">
        Bitte lesen Sie vorab
        <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/statuten" target="_blank">Statuten</a>,
        <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/datenschutz" target="_blank">Datenschutzerklärung</a>,
        <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/agb" target="_blank">AGBs</a> und
        <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/preisliste" target="_blank">Preisliste</a>.
      </p>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Unterschrift</h3>
      <p style="font-size:.8rem;color:#6b7280;margin-bottom:.75rem">Bitte unterschreiben Sie mit Maus oder Finger im Feld unten.</p>
      <canvas id="sig-pad" width="600" height="180" style="border:1px solid #e5e7eb;border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
      <div style="margin-top:.5rem">
        <button type="button" class="btn" style="background:#f3f4f6;color:#374151;font-size:.8rem" onclick="clearSignature()">Löschen</button>
      </div>
      <input type="hidden" name="signature_image" id="signature_image">
    </div>

    <div style="display:flex;gap:1rem">
      <button type="submit" class="btn btn-primary" id="submit-btn">Beitrittserklärung übermitteln</button>
    </div>
  </form>
</div>

<script>
(function() {
  const canvas = document.getElementById('sig-pad');
  const ctx = canvas.getContext('2d');
  ctx.strokeStyle = '#111827';
  ctx.lineWidth = 2;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  let drawing = false;
  let hasSignature = false;

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

  window.clearSignature = function() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSignature = false;
  };

  document.getElementById('beitritt-form').addEventListener('submit', function(e) {
    if (!hasSignature) {
      e.preventDefault();
      alert('Bitte unterschreiben Sie im Unterschriftsfeld, bevor Sie absenden.');
      return;
    }
    document.getElementById('signature_image').value = canvas.toDataURL('image/png');
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
