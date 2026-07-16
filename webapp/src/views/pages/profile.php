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
    <form method="post" action="/portal/profile/photo" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:center">
      <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
      <button type="submit" class="btn" style="background:#f3f4f6;color:#374151">Ändern</button>
    </form>
  </div>
</div>

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

<?php $content = ob_get_clean(); require ROOT . '/src/views/layouts/portal.php'; ?>
