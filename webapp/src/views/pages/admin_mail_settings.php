<?php $pageTitle = 'E-Mail-Einstellungen'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:#6b7280;text-decoration:none">← Admin</a>
  <h2 style="margin:0">✉️ E-Mail-Einstellungen (Microsoft Graph)</h2>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Einstellungen gespeichert.</div>
<?php endif; ?>
<?php if (!empty($testSuccess)): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= $testSuccess ?></div>
<?php endif; ?>
<?php if (!empty($testError)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Test-Mail fehlgeschlagen: <code style="font-size:.8rem"><?= htmlspecialchars($testError) ?></code></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Azure-App-Registrierung</h3>
  <p style="color:#6b7280;font-size:.85rem;margin-bottom:1rem">
    Benötigt eine Azure-AD-App-Registrierung mit Anwendungsberechtigung <code>Mail.Send</code> (Admin-Zustimmung erteilt)
    für die Absenderadresse. Diese Werte werden nur in der Datenbank gespeichert, nie im Repo.
  </p>
  <form method="post" action="/admin/mail-settings">
    <div class="grid-2">
      <div class="form-group">
        <label>Tenant-ID</label>
        <input type="text" name="tenant_id" value="<?= htmlspecialchars($mailConfig['tenant_id'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
      </div>
      <div class="form-group">
        <label>Client-ID</label>
        <input type="text" name="client_id" value="<?= htmlspecialchars($mailConfig['client_id'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
      </div>
      <div class="form-group">
        <label>Client-Secret</label>
        <input type="password" name="client_secret" placeholder="<?= !empty($mailConfig['client_secret']) ? '•••••••• (gespeichert, leer lassen zum Beibehalten)' : 'Client-Secret eingeben' ?>" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Absenderadresse</label>
        <input type="email" name="sender_address" value="<?= htmlspecialchars($mailConfig['sender_address'] ?? '') ?>" placeholder="noreply@stromfueralle.at">
        <small style="color:#6b7280">Muss ein echtes Postfach im selben Tenant sein (Graph sendet "im Namen von").</small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">E-Mail-Vorlagen</h3>
  <p style="color:#6b7280;font-size:.85rem;margin-bottom:1rem">
    Verfügbare Platzhalter: <code>{{vorname}}</code>, <code>{{link}}</code>, <code>{{gueltigkeit}}</code>
    (werden beim Versand automatisch ersetzt). Der Body darf einfaches HTML enthalten (z. B. <code>&lt;p&gt;</code>).
  </p>
  <?php $templateLabel = ['password_reset' => 'Passwort zurücksetzen', 'invite' => 'Erstlogin-Einladung']; ?>
  <?php foreach ($mailTemplates as $t): ?>
    <form method="post" action="/admin/mail-templates" style="margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid #e5e7eb">
      <input type="hidden" name="key" value="<?= htmlspecialchars($t['key']) ?>">
      <h4 style="margin-bottom:.5rem;font-size:.95rem"><?= htmlspecialchars($templateLabel[$t['key']] ?? $t['key']) ?></h4>
      <div class="form-group">
        <label>Betreff</label>
        <input type="text" name="subject" value="<?= htmlspecialchars($t['subject']) ?>" required>
      </div>
      <div class="form-group">
        <label>Text (HTML)</label>
        <textarea name="body_html" rows="5" style="width:100%;font-family:monospace;font-size:.85rem" required><?= htmlspecialchars($t['body_html']) ?></textarea>
      </div>
      <button type="submit" class="btn" style="background:#f3f4f6;color:#374151">Vorlage speichern</button>
    </form>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:1rem">Test-E-Mail senden</h3>
  <form method="post" action="/admin/mail-settings/test" style="display:flex;gap:.5rem;align-items:flex-end">
    <div class="form-group" style="margin-bottom:0;flex:1">
      <label>Ziel-Adresse</label>
      <input type="email" name="test_to" required placeholder="test@example.at">
    </div>
    <button type="submit" class="btn" style="background:#f3f4f6;color:#374151;height:38px">Test-Mail senden</button>
  </form>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
