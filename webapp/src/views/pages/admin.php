<?php $pageTitle = 'Admin'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">🔧 Plattform-Administration</h2>

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
<div class="card">
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

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
