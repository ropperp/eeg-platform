<?php
/**
 * Admin: Statuten-Einladungen (Liste + Anlegen).
 * @var list<array<string, mixed>> $invites  inkl. state
 * @var string $error @var string $success
 */
use App\Domain\Statutes\StatuteInviteService;
use App\Http\Controllers\StatuteAdminController;

$pageTitle = 'Statuten-Einladungen';
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
$o = StatuteAdminController::prefillOptions();
$badge = [
    'open' => 'background:#dbeafe;color:#1e40af', 'generated' => 'background:#dcfce7;color:#166534',
    'expired' => 'background:var(--gray-100);color:var(--gray-600)', 'revoked' => 'background:#fee2e2;color:#991b1b',
];
ob_start();
?>
<h2 style="margin-bottom:.5rem"><?= icon('file-text') ?> Statuten-Einladungen</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
  Nach einem Beratungsgespräch einen persönlichen Link zum Statuten-Generator erzeugen. Der Link funktioniert ohne Login,
  ist nicht öffentlich verlinkt und wird nur direkt nach dem Anlegen angezeigt (in der Datenbank steht nur ein Hash).
  Nach dem Erzeugen der Statuten ist er 90 Tage lang nur noch zum Download gültig. Entwürfe werden 12 Monate nach der
  letzten Erzeugung automatisch gelöscht.
</p>

<?php if ($success !== ''): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= $h($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= $h($error) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-top:0">Neue Einladung</h3>
  <form method="post" action="/admin/statuten-einladungen" class="statute-form">
    <div class="statute-grid">
      <label>Bezeichnung des Interessenten *<input type="text" name="label" required maxlength="150" placeholder="z. B. Gemeinde Musterdorf, Gespräch 21.11."></label>
      <label>Gültig (Tage)<input type="number" name="valid_days" value="<?= StatuteInviteService::DEFAULT_VALID_DAYS ?>" min="1" max="365"></label>
    </div>
    <p style="font-size:.85rem;color:var(--gray-600);margin:.25rem 0 .5rem">Vorbelegung (optional) – Modell ist immer EEG als Verein:</p>
    <div class="statute-grid">
      <label>Nahebereich<select name="prefill_area"><option value="">–</option><?php foreach ($o['areas'] as $k => $l): ?><option value="<?= $h($k) ?>"><?= $h($l) ?></option><?php endforeach; ?></select></label>
      <label>Netzbetreiber<input type="text" name="prefill_grid_operator" list="statute-grid-ops" maxlength="120">
        <datalist id="statute-grid-ops"><?php foreach ($o['grid_operators'] as $g): ?><option value="<?= $h($g) ?>"><?php endforeach; ?></datalist></label>
      <label>Bundesland<select name="prefill_state"><option value="">–</option><?php foreach ($o['states'] as $k => $l): ?><option value="<?= $h($k) ?>"><?= $h($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('plus') ?> Einladung anlegen</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0">Einladungen</h3>
  <?php if (!$invites): ?>
    <p style="color:var(--gray-600)">Noch keine Einladungen.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><th>Interessent</th><th>Status</th><th>Angelegt</th><th>Gültig bis</th><th>Fassung</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invites as $i): ?>
      <tr>
        <td><?= $h($i['label']) ?></td>
        <td><span style="padding:.1rem .5rem;border-radius:999px;font-size:.8rem;<?= $badge[$i['state']] ?>"><?= $h(StatuteInviteService::STATE_LABELS[$i['state']]) ?></span></td>
        <td><?= $h(date('d.m.Y', strtotime((string) $i['created_at']))) ?></td>
        <td><?= $h(date('d.m.Y', strtotime((string) $i['expires_at']))) ?></td>
        <td><?= $i['latest_version'] ? 'v' . (int) $i['latest_version'] : '–' ?></td>
        <td><a href="/admin/statuten-einladungen/<?= $h($i['id']) ?>">Öffnen</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
