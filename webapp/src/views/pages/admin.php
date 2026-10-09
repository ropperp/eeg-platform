<?php $pageTitle = 'Admin'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem"><?= icon('wrench') ?> Plattform-Administration</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars(is_string($_GET['success']) && $_GET['success'] !== '1' ? $_GET['success'] : 'Gespeichert.') ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="grid-2" style="margin-bottom:2rem">
  <div class="card stat-card">
    <div class="stat-value"><?= count($communities) ?></div>
    <div class="stat-label">Energiegemeinschaften</div>
  </div>
  <div class="card stat-card">
    <div class="stat-value"><?= $userCount ?></div>
    <div class="stat-label">Benutzerkonten</div>
  </div>
</div>

<!-- Neue EEG anlegen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">+ Neue EEG anlegen</h3>
  <form method="post" action="/admin/communities">
    <div class="grid-2">
      <div class="form-group">
        <label>Name der EEG</label>
        <input type="text" name="name" required placeholder="EEG Mustergemeinschaft">
      </div>
      <div class="form-group">
        <label>Marktpartner-ID</label>
        <input type="text" name="marktpartner_id" placeholder="RC108175">
      </div>
      <div class="form-group">
        <label>Adresse</label>
        <input type="text" name="address" placeholder="Musterstraße 1, 1234 Musterort">
      </div>
    </div>
    <button class="btn btn-primary">EEG anlegen</button>
  </form>
</div>

<!-- EEG-Liste -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Alle Energiegemeinschaften</h3>
  <table>
    <thead>
      <tr><th>Name</th><th>Slug</th><th>Marktpartner-ID</th><th>Status</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($communities as $c): ?>
      <tr>
        <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
        <td><code style="font-size:.8rem"><?= htmlspecialchars($c['slug']) ?></code></td>
        <td><?= htmlspecialchars($c['marktpartner_id'] ?? '—') ?></td>
        <td>
          <span class="badge badge-<?= $c['active'] ? 'green' : 'gray' ?>">
            <?= $c['active'] ? 'aktiv' : 'inaktiv' ?>
          </span>
        </td>
        <td><a href="/admin/communities/<?= $c['id'] ?>" style="font-size:.8rem">Konfigurieren</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Benutzer & Rollen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('user-circle') ?> Benutzer & Rollen</h3>

  <table style="margin-bottom:1.5rem">
    <thead>
      <tr><th>E-Mail</th><th>Name</th><th>Rollen</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
          <?php if (!empty($u['founder_admin'])): ?>
            <span class="badge badge-green" style="margin-left:.35rem" title="Kann von niemandem entfernt werden">Ersteller-Admin</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.8rem">
          <?php foreach ($u['roles'] as $r): ?>
            <span class="badge badge-<?= $r['role'] === 'platform_admin' ? 'green' : 'yellow' ?>" style="margin-right:.25rem">
              <?= htmlspecialchars($r['role']) ?>
              <?php if ($r['community_name']): ?>
                (<?= htmlspecialchars($r['community_name']) ?>)
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
          <?php if (empty($u['roles'])): ?>
            <span style="color:var(--gray-600)">keine</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="/admin/users/<?= $u['id'] ?>" style="font-size:.8rem">Rollen verwalten</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (Auth::isFounderAdmin()): ?>
<!-- Nur der Ersteller-Admin darf die Platform-Admin-Rollenverteilung selbst verändern
     (Patrick, 09.10.2026: "Bis halt auf die Rollenverteilung") -- Unteradmins sehen diese
     Karte gar nicht erst. -->
<div class="card">
  <h3 style="margin-bottom:.5rem"><?= icon('shield-check') ?> Neuen Platform-Admin (Unteradmin) einladen</h3>
  <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:1rem">
    Existiert bereits ein Login mit dieser E-Mail (z.B. ein bestehender Obmann-Account), bekommt
    genau dieser Account zusätzlich Platform-Admin-Zugang -- kein zweiter Account nötig, Vor-/
    Nachname werden dann ignoriert. Bei einer neuen E-Mail wird ein Account angelegt und ein
    Link zum Passwort-Setzen per Mail verschickt (48 Stunden gültig).
  </p>
  <form method="post" action="/admin/platform-admins/invite">
    <div class="grid-2">
      <div class="form-group" style="grid-column:1 / -1">
        <label>E-Mail</label>
        <input type="email" name="email" required placeholder="kollege@beispiel.at">
      </div>
      <div class="form-group">
        <label>Vorname (nur bei neuem Account)</label>
        <input type="text" name="first_name">
      </div>
      <div class="form-group">
        <label>Nachname (nur bei neuem Account)</label>
        <input type="text" name="last_name">
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Platform-Admin-Zugang einrichten</button>
  </form>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
