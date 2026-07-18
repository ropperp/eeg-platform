<?php
$pageTitle = 'Online-Beitrittserklärung — ' . $community['name'];
$d = $_POST ?? [];
$mpid = strtolower($community['marktpartner_id']);
$downloadPdf = '/assets/docs/beitrittserklaerung-eeg-strompool-feldkirchen-suedwest.pdf';
$hasDownloadPdf = $mpid === 'rc108175' && file_exists(ROOT . '/public' . $downloadPdf);
ob_start();
?>

<div class="legal" style="max-width:900px">
  <h1>Online-Beitrittserklärung</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?></div>

  <?php if ($hasDownloadPdf): ?>
    <p style="margin-bottom:1.5rem">
      <a href="<?= htmlspecialchars($downloadPdf) ?>" class="btn" style="background:var(--gray-100);color:var(--gray-700)" download>
        📄 Beitrittsformular herunterladen (falls Sie es lieber ausdrucken &amp; per Post/E-Mail schicken möchten)
      </a>
    </p>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="margin-bottom:1.5rem"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/<?= htmlspecialchars($mpid) ?>/beitreten/formular" id="beitritt-form">
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

      <div class="form-group">
        <label>
          <input type="checkbox" name="bezug_gewuenscht" id="bezug_gewuenscht" value="1" style="width:auto;display:inline-block;margin-right:.4rem"
                 <?= !empty($d['bezug_gewuenscht']) ? 'checked' : '' ?>>
          Ich möchte Strom aus der Energiegemeinschaft beziehen
        </label>
      </div>
      <div class="grid-2" id="bezug-fields" style="display:none;margin-bottom:1rem">
        <div class="form-group">
          <label>Zählpunktnummer (33-stellig)</label>
          <input type="text" name="bezug_zaehlpunkt" maxlength="33" placeholder="AT..." value="<?= htmlspecialchars($d['bezug_zaehlpunkt'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Jahresverbrauch (kWh), falls bekannt</label>
          <input type="text" name="bezug_jahresverbrauch_kwh" value="<?= htmlspecialchars($d['bezug_jahresverbrauch_kwh'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label>
          <input type="checkbox" name="einspeisung_gewuenscht" id="einspeisung_gewuenscht" value="1" style="width:auto;display:inline-block;margin-right:.4rem"
                 <?= !empty($d['einspeisung_gewuenscht']) ? 'checked' : '' ?>>
          Ich möchte Strom in die Energiegemeinschaft einspeisen
        </label>
      </div>
      <div class="grid-2" id="einspeisung-fields" style="display:none">
        <div class="form-group">
          <label>Zählpunktnummer (33-stellig)</label>
          <input type="text" name="einspeisung_zaehlpunkt" maxlength="33" placeholder="AT..." value="<?= htmlspecialchars($d['einspeisung_zaehlpunkt'] ?? '') ?>">
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
          <input type="text" name="member_iban" id="member_iban" placeholder="AT61 1904 3002 3457 3201" value="<?= htmlspecialchars($d['member_iban'] ?? '') ?>">
          <div id="iban-feedback" style="font-size:.78rem;margin-top:.35rem;min-height:1.1em"></div>
        </div>
        <div class="form-group">
          <label>BIC</label>
          <input type="text" name="member_bic" placeholder="OPSKATWW" value="<?= htmlspecialchars($d['member_bic'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Kontoinhaber:in (voller Name lt. Bankkonto)</label>
          <input type="text" name="kontoinhaber" placeholder="z.B. mit zweitem Vornamen, falls am Konto so hinterlegt" value="<?= htmlspecialchars($d['kontoinhaber'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Adresse Kontoinhaber:in</label>
          <input type="text" name="konto_adresse" placeholder="falls abweichend von obiger Adresse" value="<?= htmlspecialchars($d['konto_adresse'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Rechtliche Zustimmungen &amp; Erklärungen</h3>
      <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:1rem">Alle sechs Punkte sind Pflicht, bevor die Beitrittserklärung übermittelt werden kann.</p>
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
        <a href="/<?= htmlspecialchars($mpid) ?>/statuten" target="_blank">Statuten</a>,
        <a href="/<?= htmlspecialchars($mpid) ?>/datenschutz" target="_blank">Datenschutzerklärung</a>,
        <a href="/<?= htmlspecialchars($mpid) ?>/agb" target="_blank">AGBs</a> und
        <a href="/<?= htmlspecialchars($mpid) ?>/preisliste" target="_blank">Preisliste</a>
        — Ihre Eingaben bleiben dabei in diesem Browser erhalten.
      </p>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h3 style="margin-bottom:1rem">Unterschrift Beitrittserklärung</h3>
      <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:.75rem">Bitte unterschreiben Sie mit Maus oder Finger im Feld unten.</p>
      <canvas id="sig-pad" width="600" height="180" style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
      <div style="margin-top:.5rem">
        <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem" onclick="clearSignature('sig-pad')">Löschen</button>
      </div>
      <input type="hidden" name="signature_image" id="signature_image">
    </div>

    <div class="card" id="sepa-card" style="margin-bottom:1.5rem;display:none">
      <h3 style="margin-bottom:1rem">SEPA-Lastschriftmandat</h3>
      <p style="font-size:.8rem;color:var(--gray-600);margin-bottom:.75rem">
        Da Sie eine IBAN angegeben haben, benötigen wir Ihre gesonderte Unterschrift für das
        SEPA-Lastschriftmandat (Einzug von Mitgliedsbeitrag und Rechnungsbeträgen).
      </p>
      <canvas id="sepa-sig-pad" width="600" height="180" style="border:1px solid var(--gray-200);border-radius:8px;width:100%;max-width:600px;height:180px;touch-action:none;background:#fff"></canvas>
      <div style="margin-top:.5rem">
        <button type="button" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem" onclick="clearSignature('sepa-sig-pad')">Löschen</button>
      </div>
      <input type="hidden" name="sepa_signature_image" id="sepa_signature_image">
    </div>

    <div style="display:flex;gap:1rem">
      <button type="submit" class="btn btn-primary" id="submit-btn">Beitrittserklärung übermitteln</button>
    </div>
  </form>
</div>

<script>
(function() {
  const STORAGE_KEY = 'beitritt_draft_<?= $mpid ?>';
  const SIGNATURE_FIELDS = ['signature_image', 'sepa_signature_image'];

  function makeSignaturePad(canvasId) {
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#111827';
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    let drawing = false;
    const state = { hasSignature: false };

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
      state.hasSignature = true;
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

    return {
      canvas, ctx, state,
      clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); state.hasSignature = false; }
    };
  }

  const sigPad = makeSignaturePad('sig-pad');
  const sepaSigPad = makeSignaturePad('sepa-sig-pad');
  window.clearSignature = function(id) {
    (id === 'sepa-sig-pad' ? sepaSigPad : sigPad).clear();
  };

  const form = document.getElementById('beitritt-form');
  const fields = Array.from(form.querySelectorAll('input[name], select[name]'))
    .filter(f => !SIGNATURE_FIELDS.includes(f.name));

  function saveDraft() {
    const data = {};
    fields.forEach(f => { data[f.name] = f.type === 'checkbox' ? f.checked : f.value; });
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch (e) {}
  }

  function restoreDraft() {
    let raw;
    try { raw = localStorage.getItem(STORAGE_KEY); } catch (e) { return; }
    if (!raw) return;
    let data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    fields.forEach(f => {
      if (!(f.name in data)) return;
      if (f.type === 'checkbox') f.checked = !!data[f.name];
      else if (data[f.name]) f.value = data[f.name];
    });
  }

  // Nur bei frischem Aufruf aus dem Browser-Speicher wiederherstellen — nach einem
  // Validierungsfehler zeigt PHP die zuletzt abgeschickten Werte bereits selbst an.
  const serverHasData = <?= empty($d) ? 'false' : 'true' ?>;
  if (!serverHasData) restoreDraft();
  fields.forEach(f => { f.addEventListener('input', saveDraft); f.addEventListener('change', saveDraft); });

  function updateConditional() {
    document.getElementById('bezug-fields').style.display = document.getElementById('bezug_gewuenscht').checked ? 'grid' : 'none';
    document.getElementById('einspeisung-fields').style.display = document.getElementById('einspeisung_gewuenscht').checked ? 'grid' : 'none';
    document.getElementById('sepa-card').style.display = document.getElementById('member_iban').value.trim() !== '' ? 'block' : 'none';
  }
  document.getElementById('bezug_gewuenscht').addEventListener('change', updateConditional);
  document.getElementById('einspeisung_gewuenscht').addEventListener('change', updateConditional);
  document.getElementById('member_iban').addEventListener('input', updateConditional);
  updateConditional();

  // IBAN: Prüfziffer per Mod-97 (ISO 7064) validieren und in 4er-Blöcken anzeigen,
  // analog zur serverseitigen Prüfung in validateIban() (webapp/public/index.php).
  function ibanChecksumValid(rawIban) {
    const iban = rawIban.replace(/\s+/g, '').toUpperCase();
    if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(iban)) return false;
    const rearranged = iban.slice(4) + iban.slice(0, 4);
    let numeric = '';
    for (const ch of rearranged) {
      numeric += /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch;
    }
    let remainder = 0;
    for (let i = 0; i < numeric.length; i += 7) {
      remainder = Number(String(remainder) + numeric.substr(i, 7)) % 97;
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

  form.addEventListener('submit', function(e) {
    if (!sigPad.state.hasSignature) {
      e.preventDefault();
      alert('Bitte unterschreiben Sie die Beitrittserklärung im Unterschriftsfeld.');
      return;
    }
    const sepaVisible = document.getElementById('sepa-card').style.display !== 'none';
    if (sepaVisible && !sepaSigPad.state.hasSignature) {
      e.preventDefault();
      alert('Bitte unterschreiben Sie zusätzlich das SEPA-Lastschriftmandat, da Sie eine IBAN angegeben haben.');
      return;
    }
    document.getElementById('signature_image').value = sigPad.canvas.toDataURL('image/png');
    if (sepaVisible) {
      document.getElementById('sepa_signature_image').value = sepaSigPad.canvas.toDataURL('image/png');
    }
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
