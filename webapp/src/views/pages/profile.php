<?php $pageTitle = 'Profil'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">Meine Daten</h2>

<?php if (isset($success)): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
<?php elseif (isset($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php
  $profileAvatarUrl = $profileMember
    ? memberAvatarUrl($profileMember['id'], $profileMember['photo_path'], $profileMember['salutation'])
    : userAvatarUrl($profileUser['id'], $profileUser['photo_path'] ?? null);
?>
<div class="card" style="max-width:480px;margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Profilbild</h3>
  <div style="display:flex;align-items:center;gap:1.25rem">
    <img src="<?= htmlspecialchars($profileAvatarUrl) ?>"
         alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover">
    <form method="post" action="/portal/profile/photo" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:.5rem;align-items:flex-start">
      <input type="file" name="photo" id="profile-photo-input" accept="image/png,image/jpeg,image/webp" required>
      <div id="profile-photo-crop-wrapper" style="display:none;flex-direction:column;align-items:center;gap:.5rem">
        <div style="width:220px;height:220px;border-radius:50%;overflow:hidden;border:2px solid #e5e7eb">
          <canvas id="profile-photo-canvas" width="220" height="220" style="cursor:grab"></canvas>
        </div>
        <label style="font-size:.78rem;color:var(--gray-600);display:flex;align-items:center;gap:.5rem">
          🔍 Zoom
          <input type="range" id="profile-photo-zoom" min="100" max="300" value="100">
        </label>
        <small style="color:var(--gray-600)">Zum Verschieben im Bild ziehen.</small>
      </div>
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Ändern</button>
    </form>
  </div>
</div>

<script src="/assets/js/avatar-crop.js"></script>
<script>
  initAvatarCropper({
    fileInputId: 'profile-photo-input',
    wrapperId: 'profile-photo-crop-wrapper',
    canvasId: 'profile-photo-canvas',
    zoomId: 'profile-photo-zoom',
  });
</script>

<div class="card" style="max-width:480px">
  <form method="post" action="/portal/profile">
    <div class="form-group">
      <label class="form-label">Vorname</label>
      <input type="text" name="first_name" class="form-control" required
             value="<?= htmlspecialchars($profileUser['first_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Nachname</label>
      <input type="text" name="last_name" class="form-control" required
             value="<?= htmlspecialchars($profileUser['last_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label class="form-label">E-Mail</label>
      <input type="email" name="email" class="form-control" required
             value="<?= htmlspecialchars($profileUser['email'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<div class="card" style="max-width:480px;margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">🔐 Zwei-Faktor-Authentifizierung</h3>
  <?php if (!empty($profileUser['totp_enabled'])): ?>
    <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
      <span class="badge badge-green">Aktiv</span> Bei jeder Anmeldung wird zusätzlich ein 6-stelliger Code abgefragt.
    </p>
    <form method="post" action="/portal/profile/2fa/disable"
          onsubmit="return confirm('Zwei-Faktor-Authentifizierung wirklich ausschalten?')">
      <button type="submit" class="btn btn-tint-red">2FA deaktivieren</button>
    </form>
  <?php else: ?>
    <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
      Zusätzlicher Schutz beim Login per 6-stelligem Code (TOTP, z.&nbsp;B. Apple Passwörter oder Authenticator).
      Jederzeit wieder abschaltbar.
    </p>
    <a href="/portal/profile/2fa/setup" class="btn btn-primary">2FA aktivieren</a>
  <?php endif; ?>
</div>

<?php if ($profileMember): ?>
<div class="card" style="max-width:480px;margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Meine Daten exportieren</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Sie können jederzeit alle zu Ihnen gespeicherten Daten als Datei herunterladen
    (DSGVO-Auskunftsrecht, Art. 15). Die Datei enthält Ihre Stammdaten, Zählpunkte, Verträge,
    Rechnungen und hochgeladenen Dokumente im maschinenlesbaren JSON-Format.
  </p>
  <a href="/portal/my/dsgvo-export" class="btn" style="background:var(--gray-100);color:var(--gray-700)">🔐 Datenauskunft herunterladen (JSON)</a>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require ROOT . '/src/views/layouts/portal.php'; ?>
