<?php $pageTitle = 'EEG konfigurieren'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:var(--gray-600);text-decoration:none">← Admin</a>
  <h2 style="margin:0">EEG konfigurieren</h2>
</div>

<form method="post" action="/admin/communities/<?= $community['id'] ?>">
  <div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem">Stammdaten</h3>
    <div class="grid-2">
      <div class="form-group">
        <label>Name der EEG</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($community['name']) ?>">
      </div>
      <div class="form-group">
        <label>Marktpartner-ID</label>
        <input type="text" name="marktpartner_id" value="<?= htmlspecialchars($community['marktpartner_id'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>ZVR-Zahl</label>
        <input type="text" name="zvr_number" value="<?= htmlspecialchars($community['zvr_number'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Adresse</label>
        <input type="text" name="address" value="<?= htmlspecialchars($community['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>IBAN</label>
        <input type="text" name="iban" value="<?= htmlspecialchars($community['iban'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>BIC</label>
        <input type="text" name="bic" value="<?= htmlspecialchars($community['bic'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="active" value="1" <?= $community['active'] ? 'checked' : '' ?>>
          EEG aktiv
        </label>
      </div>
    </div>
  </div>
  <button type="submit" class="btn btn-primary">Speichern</button>
</form>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:1rem">Mitglieder dieser EEG (<?= count($members) ?>)</h3>
  <?php if (empty($members)): ?>
    <p style="color:var(--gray-600);font-size:.875rem">Noch keine Mitglieder.</p>
  <?php else: ?>
    <table style="font-size:.85rem">
      <thead>
        <tr><th>KdNr</th><th>Name</th><th>E-Mail</th><th>Status</th><th>Login</th><th>Aktionen</th></tr>
      </thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td style="font-weight:600;color:#15803d"><?= htmlspecialchars((string)($m['kundennummer'] ?? '—')) ?></td>
          <td><?= htmlspecialchars(trim(($m['company_name'] ?: '') ?: ($m['first_name'] . ' ' . $m['last_name']))) ?></td>
          <td><?= htmlspecialchars($m['email']) ?></td>
          <td>
            <?php $sb = ['active' => 'green', 'pending' => 'yellow', 'inactive' => 'gray']; ?>
            <span class="badge badge-<?= $sb[$m['status']] ?? 'gray' ?>"><?= htmlspecialchars($m['status']) ?></span>
          </td>
          <td>
            <?php if ($m['user_id']): ?>
              <code style="font-size:.78rem"><?= htmlspecialchars($m['login_email']) ?></code>
            <?php else: ?>
              <span style="color:var(--gray-600)">kein Login</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <a href="/portal/members/<?= $m['id'] ?>" style="font-size:.8rem">Mitgliedskonto</a>
            <?php if ($m['user_id']): ?>
              &nbsp;·&nbsp;
              <a href="/admin/users/<?= $m['user_id'] ?>" style="font-size:.8rem">Login verwalten/löschen</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:1.5rem;border:1px solid #fecaca">
  <h3 style="margin-bottom:1rem;color:#b91c1c">Gefahrenzone</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Löscht die EEG endgültig inkl. aller Mitglieder, Zählpunkte, Verträge, Rechnungen und
    Rollenzuweisungen. Login-Accounts von Mitgliedern/Managern bleiben bestehen, verlieren
    aber ihre Rolle(n) in dieser EEG. Nicht rückgängig zu machen -- alternativ oben einfach
    "EEG aktiv" deaktivieren, um die Daten zu behalten.
  </p>
  <form method="post" action="/admin/communities/<?= $community['id'] ?>/delete"
        onsubmit="return confirmDangerDelete('EEG <?= htmlspecialchars(addslashes($community['name'])) ?> inkl. aller <?= count($members) ?> Mitglieder, Verträge und Rechnungen')">
    <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c">🗑️ EEG endgültig löschen</button>
  </form>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
