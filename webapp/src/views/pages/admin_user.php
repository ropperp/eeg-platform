<?php $pageTitle = 'Benutzer: ' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:var(--gray-600);text-decoration:none">← Admin</a>
  <h2 style="margin:0"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
  <code style="font-size:.8rem;color:var(--gray-600)"><?= htmlspecialchars($user['email']) ?></code>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php endif; ?>

<!-- Aktuelle Rollen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Aktuelle Rollen</h3>
  <?php if (empty($roles)): ?>
    <p style="color:var(--gray-600);font-size:.875rem">Keine Rollen zugewiesen.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Rolle</th><th>EEG</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r): ?>
        <tr>
          <td><span class="badge badge-<?= $r['role'] === 'platform_admin' ? 'green' : 'yellow' ?>"><?= htmlspecialchars($r['role']) ?></span></td>
          <td><?= htmlspecialchars($r['community_name'] ?? '—') ?></td>
          <td>
            <form method="post" action="/admin/users/<?= $user['id'] ?>/roles/delete" style="display:inline">
              <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
              <button type="submit" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:.8rem"
                      onclick="return confirm('Rolle entfernen?')">Entfernen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Rolle hinzufügen -->
<div class="card">
  <h3 style="margin-bottom:1rem">Rolle hinzufügen</h3>
  <form method="post" action="/admin/users/<?= $user['id'] ?>/roles">
    <div class="grid-2">
      <div class="form-group">
        <label>Rolle</label>
        <select name="role" onchange="document.getElementById('community-field').style.display = this.value === 'platform_admin' ? 'none' : 'block'">
          <option value="manager">manager (EEG-Verwalter)</option>
          <option value="member">member (EEG-Mitglied)</option>
          <option value="platform_admin">platform_admin (Plattform-Admin)</option>
        </select>
      </div>
      <div class="form-group" id="community-field">
        <label>EEG</label>
        <select name="community_id">
          <?php foreach ($communities as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Rolle zuweisen</button>
  </form>
</div>

<?php if ($user['id'] !== Auth::userId()): ?>
<div class="card" style="margin-top:1.5rem;border:1px solid #fecaca">
  <h3 style="margin-bottom:1rem;color:#b91c1c">Gefahrenzone</h3>
  <form method="post" action="/admin/users/<?= $user['id'] ?>/delete"
        onsubmit="return confirmDangerDelete('Benutzer <?= htmlspecialchars(addslashes($user['first_name'] . ' ' . $user['last_name'])) ?> (<?= htmlspecialchars(addslashes($user['email'])) ?>) inkl. aller Rollenzuweisungen')">
    <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c">🗑️ Benutzer löschen</button>
  </form>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
