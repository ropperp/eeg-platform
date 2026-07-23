<?php
$pageTitle = 'Zwei-Faktor-Authentifizierung';
// $secret (Base32), $otpauthUri, optional $error kommen aus der Route.
$secretGrouped = trim(chunk_split($secret, 4, ' '));
ob_start();
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/profile" style="color:var(--gray-600);text-decoration:none">← Profil</a>
  <h2 style="margin:0">🔐 Zwei-Faktor-Authentifizierung einrichten</h2>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:560px">
  <ol style="margin:0 0 1rem 1.1rem;font-size:.92rem;line-height:1.7">
    <li>Öffne deine <strong>Passwörter-App</strong> (Apple/iCloud, iPhone &amp; Mac) oder einen Authenticator und
      lege einen Eintrag mit <strong>Einrichtungsschlüssel / Setup-Key</strong> an.</li>
    <li>Trage den folgenden Schlüssel ein:</li>
  </ol>

  <div style="text-align:center;margin:0 0 1rem">
    <code style="display:inline-block;font-size:1.15rem;letter-spacing:.08em;padding:.6rem .9rem;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:8px;user-select:all"><?= htmlspecialchars($secretGrouped) ?></code>
  </div>

  <details style="margin-bottom:1.25rem">
    <summary style="cursor:pointer;font-size:.85rem;color:var(--gray-600)">Alternativ: otpauth-Link (zum Kopieren)</summary>
    <p style="word-break:break-all;font-size:.8rem;color:var(--gray-600);margin-top:.5rem;user-select:all"><?= htmlspecialchars($otpauthUri) ?></p>
  </details>

  <form method="post" action="/portal/profile/2fa/enable">
    <div class="form-group">
      <label for="code">Zur Bestätigung: aktueller 6-stelliger Code</label>
      <input type="text" id="code" name="code" required autofocus inputmode="numeric" pattern="[0-9]*" maxlength="6"
             autocomplete="one-time-code" style="letter-spacing:.4em;font-size:1.3rem;text-align:center;max-width:220px" placeholder="000000">
    </div>
    <button type="submit" class="btn btn-primary">Aktivieren</button>
    <a href="/portal/profile" class="btn btn-secondary" style="margin-left:.5rem">Abbrechen</a>
  </form>

  <p style="font-size:.8rem;color:var(--gray-600);margin-top:1rem">
    Nach dem Aktivieren wird bei jeder Anmeldung zusätzlich zum Passwort dieser Code abgefragt.
    Du kannst 2FA jederzeit im Profil wieder ausschalten. Bewahre den Schlüssel sicher auf –
    verlierst du den Zugriff auf die App, hilft nur ein Zurücksetzen durch einen Administrator.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
