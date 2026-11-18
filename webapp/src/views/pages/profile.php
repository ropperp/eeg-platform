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
          <?= icon('magnifying-glass') ?> Zoom
          <input type="range" id="profile-photo-zoom" min="100" max="300" value="100">
        </label>
        <small style="color:var(--gray-600)">Zum Verschieben im Bild ziehen.</small>
      </div>
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Ändern</button>
    </form>
  </div>
</div>

<script src="<?= htmlspecialchars(assetUrl('/assets/js/avatar-crop.js')) ?>"></script>
<script nonce="<?= cspNonce() ?>">
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
  <h3 style="margin-bottom:.5rem"><?= icon('lock-key') ?> Zwei-Faktor-Authentifizierung</h3>
  <?php if (!empty($profileUser['totp_enabled'])): ?>
    <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
      <span class="badge badge-green">Aktiv</span> Bei jeder Anmeldung wird zusätzlich ein 6-stelliger Code abgefragt.
    </p>
    <form method="post" action="/portal/profile/2fa/disable"
          data-confirm="Zwei-Faktor-Authentifizierung wirklich ausschalten?">
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

<?php if (!empty($kcLogin)): ?>
<div class="card" style="max-width:480px;margin-bottom:1.5rem" id="anmeldung">
  <h3 style="margin-bottom:.5rem"><?= icon('lock-key') ?> Anmeldung</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Mit einer Anmeldung sind Sie im Portal und in allen Bereichen von „Strom für alle“ angemeldet.
  </p>
  <?php if ($kcLogin['google_available']): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-top:1px solid var(--gray-200)">
      <span style="font-size:.9rem"><strong>Google</strong><br>
        <span style="color:var(--gray-600);font-size:.8rem"><?= $kcLogin['google_linked'] !== null ? 'verbunden: ' . htmlspecialchars($kcLogin['google_linked']) : 'nicht verbunden' ?></span>
      </span>
      <?php if ($kcLogin['google_linked'] !== null): ?>
        <form method="post" action="/portal/auth/google/unlink" data-confirm="Google-Anmeldung für dieses Konto lösen?">
          <?= csrfField() ?><button type="submit" class="btn btn-tint-red">Lösen</button>
        </form>
      <?php else: ?>
        <a href="/portal/auth/google" class="btn btn-primary">Mit Google verknüpfen</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php foreach ($kcLogin['passkeys'] as $pk): ?>
    <form method="post" action="/portal/auth/passkey/delete" data-confirm="Passkey wirklich entfernen?"
          style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-top:1px solid var(--gray-200)">
      <?= csrfField() ?><input type="hidden" name="id" value="<?= htmlspecialchars($pk['id']) ?>">
      <span style="font-size:.9rem"><strong><?= htmlspecialchars($pk['label']) ?></strong><br>
        <span style="color:var(--gray-600);font-size:.8rem">Passkey<?= $pk['created'] !== '' ? ', angelegt ' . htmlspecialchars($pk['created']) : '' ?></span>
      </span>
      <button type="submit" class="btn btn-tint-red">Entfernen</button>
    </form>
  <?php endforeach; ?>
  <div style="padding-top:.75rem;border-top:1px solid var(--gray-200)">
    <a href="/portal/auth/passkey" class="btn btn-secondary"><?= icon('key') ?> Passkey für die Anmeldung einrichten</a>
  </div>
</div>
<?php endif; ?>

<div class="card" style="max-width:480px;margin-bottom:1.5rem" id="passkeys">
  <h3 style="margin-bottom:.5rem"><?= icon('key') ?> <?= !empty($kcLogin) ? 'Passkeys für den Notzugang' : 'Passkeys' ?></h3>
  <?php if (!empty($kcLogin)): ?>
    <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:.5rem">
      Diese Passkeys gelten nur, wenn der Anmeldedienst ausfällt, und für den Notzugang der Steuerzentrale.
    </p>
  <?php endif; ?>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Anmelden ohne Passwort mit Fingerabdruck, Gesicht oder Geräte-PIN (z.&nbsp;B. iPhone, MacBook,
    Windows&nbsp;Hello oder Sicherheitsschlüssel). Ein Passkey ersetzt Passwort und 2FA und lässt sich
    nicht auf gefälschten Seiten eingeben.
  </p>
  <?php foreach (($profilePasskeys ?? []) as $pk): ?>
    <form method="post" action="/portal/profile/passkeys/delete"
          style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-top:1px solid var(--gray-200)"
          data-confirm="Passkey wirklich entfernen?">
      <input type="hidden" name="id" value="<?= htmlspecialchars($pk['id']) ?>">
      <span style="font-size:.9rem">
        <strong><?= htmlspecialchars($pk['label']) ?></strong><br>
        <span style="color:var(--gray-600);font-size:.8rem">
          angelegt <?= htmlspecialchars(date('d.m.Y', strtotime($pk['created_at']))) ?><?= $pk['last_used_at'] ? ', zuletzt benutzt ' . htmlspecialchars(date('d.m.Y', strtotime($pk['last_used_at']))) : '' ?>
        </span>
      </span>
      <button type="submit" class="btn btn-tint-red">Entfernen</button>
    </form>
  <?php endforeach; ?>
  <div id="passkey-register-box" hidden style="margin-top:.75rem">
    <label class="form-label" for="passkey-label">Name (z.&nbsp;B. „iPhone“)</label>
    <input type="text" id="passkey-label" class="form-control" maxlength="60" style="margin-bottom:.75rem">
    <button type="button" class="btn btn-primary" data-passkey-register data-csrf="<?= htmlspecialchars(csrfToken()) ?>">Passkey hinzufügen</button>
    <p id="passkey-msg" role="status" style="font-size:.85rem;margin-top:.5rem"></p>
  </div>
  <p id="passkey-unsupported" style="color:var(--gray-600);font-size:.85rem">Dieser Browser unterstützt keine Passkeys.</p>
</div>
<script src="<?= htmlspecialchars(assetUrl('/assets/js/passkey.js')) ?>" defer></script>

<?php if ($profileMember): ?>
<div class="card" style="max-width:480px;margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Meine Daten exportieren</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Sie können jederzeit alle zu Ihnen gespeicherten Daten als Datei herunterladen
    (DSGVO-Auskunftsrecht, Art. 15). Die Datei enthält Ihre Stammdaten, Zählpunkte, Verträge,
    Rechnungen und hochgeladenen Dokumente im maschinenlesbaren JSON-Format.
  </p>
  <a href="/portal/my/dsgvo-export" class="btn" style="background:var(--gray-100);color:var(--gray-700)"><?= icon('lock-key') ?> Datenauskunft herunterladen (JSON)</a>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require ROOT . '/src/views/layouts/portal.php'; ?>
