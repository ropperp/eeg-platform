<?php $pageTitle = 'Admin'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">🔧 Plattform-Administration</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
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

<!-- Backup-Status -->
<?php
$backupAge   = $lastBackupAt ? max(0, (int)floor((time() - strtotime($lastBackupAt)) / 3600)) : null;
$statusColor = $backupProblem ? '#dc2626' : '#16a34a';
$statusBg    = $backupProblem ? '#fef2f2'  : '#dcfce7';
?>
<div class="card" style="margin-bottom:1.5rem;border-left:4px solid <?= $statusColor ?>">
  <h3 style="margin-bottom:.75rem">🗄️ Backup-Status</h3>
  <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap">
    <div style="background:<?= $statusBg ?>;border-radius:8px;padding:.75rem 1.25rem;min-width:140px;text-align:center">
      <div style="font-size:1.75rem;font-weight:700;color:<?= $statusColor ?>">
        <?php if ($backupAge === null): ?>
          —
        <?php elseif ($backupAge === 0): ?>
          &lt;1h
        <?php elseif ($backupAge < 24): ?>
          <?= $backupAge ?>h
        <?php else: ?>
          <?= (int)floor($backupAge / 24) ?>d
        <?php endif; ?>
      </div>
      <div style="font-size:.75rem;color:#6b7280">seit Backup</div>
    </div>
    <div style="padding:.25rem 0;font-size:.875rem;display:flex;flex-direction:column;gap:.3rem">
      <div>
        <?php if (!$lastBackupAt): ?>
          <span style="color:#9ca3af">Noch kein Backup erfasst — Cron einrichten</span>
        <?php elseif (!$lastBackupOk): ?>
          <span style="color:#dc2626;font-weight:600">❌ Fehlgeschlagen</span>
        <?php elseif ($backupStale): ?>
          <span style="color:#dc2626;font-weight:600">⚠️ Veraltet (&gt;24 h)</span>
        <?php else: ?>
          <span style="color:#16a34a;font-weight:600">✅ Aktuell</span>
        <?php endif; ?>
      </div>
      <?php if ($lastBackupAt): ?>
        <div style="color:#6b7280">📅 <?= date('d.m.Y H:i', strtotime($lastBackupAt)) ?> UTC</div>
      <?php endif; ?>
      <?php if ($lastBackupSize): ?>
        <div style="color:#6b7280">📦 <?= htmlspecialchars($lastBackupSize) ?></div>
      <?php endif; ?>
      <div style="color:#9ca3af;font-size:.78rem">
        Cron: täglich 02:30 → <code>bash scripts/backup.sh</code> → healthchecks.io
      </div>
    </div>
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
  <h3 style="margin-bottom:1rem">👤 Benutzer & Rollen</h3>

  <table style="margin-bottom:1.5rem">
    <thead>
      <tr><th>E-Mail</th><th>Name</th><th>Rollen</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
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
            <span style="color:#9ca3af">keine</span>
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

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
