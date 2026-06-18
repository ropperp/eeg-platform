<?php $pageTitle = 'Passwort ändern'; ob_start(); ?>

<div style="max-width:480px">
  <h2 style="margin-bottom:1.5rem">🔑 Passwort ändern</h2>

  <?php if (!empty($success)): ?>
    <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="post" action="/portal/password">
      <div class="form-group">
        <label>Aktuelles Passwort <span style="color:#ef4444">*</span></label>
        <input type="password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label>Neues Passwort <span style="color:#ef4444">*</span></label>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
               oninput="checkStrength(this.value)">
        <div id="strength-bar" style="height:4px;border-radius:2px;margin-top:.25rem;transition:all .3s;background:#e5e7eb"></div>
        <small id="strength-label" style="font-size:.75rem;color:#6b7280"></small>
      </div>
      <div class="form-group">
        <label>Passwort bestätigen <span style="color:#ef4444">*</span></label>
        <input type="password" name="confirm_password" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary">Passwort speichern</button>
    </form>
  </div>
</div>

<script>
function checkStrength(pw) {
  const bar = document.getElementById('strength-bar');
  const lbl = document.getElementById('strength-label');
  let score = 0;
  if (pw.length >= 8) score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const levels = [
    {color:'#ef4444', text:'Sehr schwach'},
    {color:'#f97316', text:'Schwach'},
    {color:'#eab308', text:'Mittel'},
    {color:'#22c55e', text:'Stark'},
    {color:'#16a34a', text:'Sehr stark'},
  ];
  const l = levels[Math.min(score, 4)];
  bar.style.background = l.color;
  bar.style.width = (score * 20) + '%';
  lbl.textContent = l.text;
  lbl.style.color = l.color;
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
