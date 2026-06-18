<?php
$pageTitle = isset($member) ? 'Mitglied bearbeiten' : 'Mitglied anlegen';
$m = $member ?? [];
$action = isset($member) ? '/portal/members/' . $member['id'] . '/edit' : '/portal/members';
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Zurück</a>
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
        <label>Anrede</label>
        <select name="salutation">
          <option value="">—</option>
          <?php foreach (['Herr','Frau','Divers'] as $s): ?>
            <option value="<?= $s ?>" <?= ($m['salutation'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
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
        <label>E-Mail <span style="color:#ef4444">*</span></label>
        <input type="email" name="email" required value="<?= htmlspecialchars($m['email'] ?? $_POST['email'] ?? '') ?>">
        <?php if (!isset($member)): ?>
          <small style="color:#6b7280">Wird für den Plattform-Login verwendet.</small>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($m['phone'] ?? $_POST['phone'] ?? '') ?>">
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
      <div class="form-group">
        <label>Mitglied seit <span style="color:#ef4444">*</span></label>
        <input type="date" name="member_since" required
               value="<?= htmlspecialchars($m['member_since'] ?? $_POST['member_since'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="form-group">
        <label>Mitglied bis</label>
        <input type="date" name="member_until"
               value="<?= htmlspecialchars($m['member_until'] ?? $_POST['member_until'] ?? '2099-12-31') ?>">
        <small style="color:#6b7280">Leer lassen = aktives Mitglied (wird auf 31.12.2099 gesetzt)</small>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Bankverbindung</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>IBAN</label>
        <input type="text" name="member_iban" placeholder="AT61 1904 3002 3457 3201"
               value="<?= htmlspecialchars($m['member_iban'] ?? $_POST['member_iban'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>BIC</label>
        <input type="text" name="member_bic" placeholder="OPSKATWW"
               value="<?= htmlspecialchars($m['member_bic'] ?? $_POST['member_bic'] ?? '') ?>">
      </div>
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

  <div style="display:flex;gap:1rem">
    <button type="submit" class="btn btn-primary"><?= isset($member) ? 'Speichern' : 'Mitglied anlegen' ?></button>
    <a href="/portal/members" class="btn" style="background:#f3f4f6;color:#374151">Abbrechen</a>
  </div>
</form>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
