<?php $pageTitle = 'EEG konfigurieren'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:#6b7280;text-decoration:none">← Admin</a>
  <h2 style="margin:0">EEG konfigurieren</h2>
</div>

<form method="post" action="/admin/communities/<?= $community['id'] ?>">
  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Stammdaten</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>Name der EEG</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($community['name']) ?>">
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
      <div class="form-group">
        <label>
          <input type="checkbox" name="active" value="1" <?= $community['active'] ? 'checked' : '' ?>>
          EEG aktiv
        </label>
      </div>
    </div>
  </div>
  <button type="submit" class="btn btn-primary">Speichern</button>
</form>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
