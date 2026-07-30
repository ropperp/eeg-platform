<?php $pageTitle = 'API-Zugänge'; ob_start(); ?>

<h2 style="margin-bottom:.5rem"><?= icon('plug') ?> API-Zugänge</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
  Persönliche API-Keys für die Smart-Home-Anbindung (eigene Bezugs-/Einspeiseleistung
  und Gemeinschafts-Autarkie in Echtzeit, z. B. für Node-RED).
</p>

<?php
  // Diese Box bleibt bewusst IMMER hell (auch im Dark Mode) -- die globale
  // [data-theme="dark"] code-Regel würde die <code>-Tags sonst dunkel einfärben, was sich mit
  // dem hellblauen Kasten beißt. Inline-Style auf den <code>-Tags selbst gewinnt gegen die
  // globale Regel (höhere Spezifität durchs style-Attribut).
  $codeStyle = 'background:#dbeafe;color:#1d4ed8;padding:.1rem .3rem;border-radius:4px';
?>
<div class="alert" style="margin-bottom:1.5rem;background:#eff6ff;color:#1d4ed8">
  <?= icon('info') ?> <code style="<?= $codeStyle ?>">GET /api/v1/live</code> liefert Bezug/Einspeisung in Watt (sobald Sie
  eine eigene Ausleseeinheit haben) und die Autarkiequote der Gemeinschaft --
  Bearer-Authentifizierung mit einem der API-Keys unten. Testen lässt sich die Authentifizierung
  auch mit <code style="<?= $codeStyle ?>">GET /api/v1/me</code>.
</div>

<?php if (!empty($newApiKey)): ?>
<div class="card" style="margin-bottom:1.5rem;border:2px solid #16a34a">
  <h3 style="color:#15803d;margin-bottom:.75rem"><?= icon('check-circle') ?> API-Key erstellt</h3>
  <p style="margin-bottom:.5rem;font-size:.85rem">
    Bitte jetzt kopieren -- aus Sicherheitsgründen wird dieser Key nur dieses eine Mal angezeigt:
  </p>
  <code style="display:block;padding:.6rem .75rem;background:var(--gray-100);border-radius:6px;font-size:.95rem;word-break:break-all"><?= htmlspecialchars($newApiKey) ?></code>
</div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;max-width:32rem">
  <h3 style="margin-bottom:1rem">Neuen API-Key erstellen</h3>
  <form method="post" action="/portal/my/api-keys">
    <div class="form-group">
      <label>Name</label>
      <input type="text" name="name" required placeholder="z. B. Home Assistant">
    </div>
    <div class="form-group">
      <label>Gültigkeitsdauer</label>
      <select name="validity">
        <option value="">Läuft nie ab</option>
        <option value="30">30 Tage</option>
        <option value="90">90 Tage</option>
        <option value="365">1 Jahr</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">API-Key erstellen</button>
  </form>
</div>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Key</th>
        <th>Erstellt</th>
        <th>Läuft ab</th>
        <th>Zuletzt genutzt</th>
        <th>Status</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($apiKeys as $k): ?>
      <?php
        $isRevoked = !empty($k['revoked_at']);
        $isExpired = !$isRevoked && !empty($k['expires_at']) && strtotime($k['expires_at']) < time();
      ?>
      <tr>
        <td><?= htmlspecialchars($k['name']) ?></td>
        <td><code style="font-size:.8rem"><?= htmlspecialchars($k['key_prefix']) ?>…</code></td>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y', strtotime($k['created_at'])) ?></td>
        <td style="font-size:.85rem;white-space:nowrap"><?= $k['expires_at'] ? date('d.m.Y', strtotime($k['expires_at'])) : 'nie' ?></td>
        <td style="font-size:.85rem;white-space:nowrap"><?= $k['last_used_at'] ? date('d.m.Y H:i', strtotime($k['last_used_at'])) : '–' ?></td>
        <td>
          <?php if ($isRevoked): ?>
            <span class="badge badge-gray">Widerrufen</span>
          <?php elseif ($isExpired): ?>
            <span class="badge badge-gray">Abgelaufen</span>
          <?php else: ?>
            <span class="badge badge-green">Aktiv</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$isRevoked): ?>
          <form method="post" action="/portal/my/api-keys/<?= $k['id'] ?>/revoke"
                onsubmit="return confirm('API-Key „<?= htmlspecialchars(addslashes($k['name'])) ?>&#8220; wirklich widerrufen? Jede Anwendung, die ihn nutzt, verliert damit sofort den Zugriff.')">
            <button type="submit" class="btn btn-tint-red" style="font-size:.78rem;padding:.3rem .6rem">Widerrufen</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($apiKeys)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine API-Keys angelegt.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
