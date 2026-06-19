<?php $pageTitle = 'Profil'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">Meine Daten</h2>

<?php if (isset($success)): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
<?php elseif (isset($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

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
