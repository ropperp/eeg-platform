<?php $pageTitle = 'Mitglied anlegen'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/members" style="color:#6b7280;text-decoration:none">← Zurück</a>
  <h2 style="margin:0">Neues Mitglied anlegen</h2>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="/portal/members">
  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Persönliche Daten</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>Anrede</label>
        <select name="salutation">
          <option value="">—</option>
          <option value="Herr">Herr</option>
          <option value="Frau">Frau</option>
          <option value="Divers">Divers</option>
        </select>
      </div>
      <div class="form-group">
        <label>Firma (optional)</label>
        <input type="text" name="company_name" placeholder="Muster GmbH">
      </div>
      <div class="form-group">
        <label>Vorname <span style="color:#ef4444">*</span></label>
        <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Nachname <span style="color:#ef4444">*</span></label>
        <input type="text" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>E-Mail <span style="color:#ef4444">*</span></label>
        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <small style="color:#6b7280">Wird für den Plattform-Login verwendet.</small>
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Adresse</h3>
    <div class="grid-2">
      <div class="form-group" style="grid-column:1/-1">
        <label>Straße &amp; Hausnummer <span style="color:#ef4444">*</span></label>
        <input type="text" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>PLZ <span style="color:#ef4444">*</span></label>
        <input type="text" name="zip" required value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Ort <span style="color:#ef4444">*</span></label>
        <input type="text" name="city" required value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Rechnungsdaten (optional)</h3>
    <div class="form-group">
      <label>UID-Nummer (für Unternehmen)</label>
      <input type="text" name="invoice_uid" placeholder="ATU12345678" value="<?= htmlspecialchars($_POST['invoice_uid'] ?? '') ?>">
    </div>
  </div>

  <div style="display:flex;gap:1rem">
    <button type="submit" class="btn btn-primary">Mitglied anlegen</button>
    <a href="/portal/members" class="btn" style="background:#f3f4f6;color:#374151">Abbrechen</a>
  </div>
</form>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
