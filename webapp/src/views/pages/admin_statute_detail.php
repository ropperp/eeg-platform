<?php
/**
 * Admin: eine Statuten-Einladung (Link, Status, Fassungen, Entwurf bearbeiten).
 * @var array<string, mixed> $invite @var string $state @var list<array<string, mixed>> $versions
 * @var array<string, mixed> $answers @var list<string> $errors @var list<string> $hints @var list<string> $missing
 * @var string|null $link  nur direkt nach Anlegen/neuem Link
 * @var string $error @var string $success @var array<string, string> $prefill
 */
use App\Domain\Statutes\StatuteInviteService;
use App\Domain\Statutes\StatutePdfRenderer;

$pageTitle = 'Statuten-Einladung';
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
$base = '/admin/statuten-einladungen/' . $invite['id'];
$revoked = $state === StatuteInviteService::STATE_REVOKED;
ob_start();
?>
<p><a href="/admin/statuten-einladungen">&larr; Alle Einladungen</a></p>
<h2 style="margin-bottom:.25rem"><?= $h($invite['label']) ?></h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-top:0">
  Status: <strong><?= $h(StatuteInviteService::STATE_LABELS[$state]) ?></strong> ·
  angelegt <?= $h(date('d.m.Y', strtotime((string) $invite['created_at']))) ?> ·
  gültig bis <?= $h(date('d.m.Y', strtotime((string) $invite['expires_at']))) ?>
  <?php if (!empty($invite['used_at'])): ?> · über den Link erzeugt am <?= $h(date('d.m.Y', strtotime((string) $invite['used_at']))) ?><?php endif; ?>
</p>

<?php if ($success !== ''): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= $h($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= $h($error) ?></div><?php endif; ?>

<?php if ($link !== null): ?>
  <div class="card" style="margin-bottom:1rem;border:2px solid var(--primary, #16a34a)">
    <h3 style="margin-top:0"><?= icon('key') ?> Persönlicher Link</h3>
    <p style="font-size:.875rem">Jetzt kopieren und an den Interessenten schicken. <strong>Er wird nur dieses eine Mal angezeigt.</strong>
       Geht er verloren: „Neuen Link erzeugen“ (der alte wird dann ungültig).</p>
    <div class="statute-link">
      <input type="text" id="statute-link" value="<?= $h($link) ?>" readonly>
      <button type="button" class="btn btn-primary" data-copy-target="statute-link"><?= icon('clipboard-text') ?> Link kopieren</button>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-top:0">Aktionen</h3>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <?php if (!$revoked): ?>
      <form method="post" action="<?= $h($base) ?>/neuer-link" data-confirm="Neuen Link erzeugen? Der bisherige Link funktioniert danach nicht mehr.">
        <button type="submit" class="btn"><?= icon('arrow-clockwise') ?> Neuen Link erzeugen</button>
      </form>
      <form method="post" action="<?= $h($base) ?>/widerrufen" data-confirm="Einladung widerrufen? Der Link funktioniert danach nicht mehr (auch nicht für den Download).">
        <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b"><?= icon('x-circle') ?> Widerrufen</button>
      </form>
    <?php else: ?>
      <p style="margin:0;color:var(--gray-600)">Widerrufen am <?= $h(date('d.m.Y', strtotime((string) $invite['revoked_at']))) ?>. Für einen neuen Zugang eine neue Einladung anlegen.</p>
    <?php endif; ?>
  </div>
  <?php if ($prefill): ?>
    <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:0">Vorbelegung: <?= $h(implode(', ', array_map(fn($k, $v) => $k . ' = ' . $v, array_keys($prefill), $prefill))) ?></p>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-top:0">Erzeugte Fassungen</h3>
  <?php if (!$versions): ?>
    <p style="color:var(--gray-600)">Noch keine Statuten erzeugt.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Fassung</th><th>Erzeugt</th><th>Von</th><th>Status</th><th>Dokumente</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $v): ?>
        <tr>
          <td>v<?= (int) $v['version'] ?></td>
          <td><?= $h(date('d.m.Y H:i', strtotime((string) $v['generated_at']))) ?></td>
          <td><?= $v['generated_by'] ? 'Admin' : 'Interessent (Link)' ?></td>
          <td><?= $v['released_at'] ? 'freigegeben ' . $h(date('d.m.Y', strtotime((string) $v['released_at']))) : 'Entwurf' ?></td>
          <td>
            <?php foreach (StatutePdfRenderer::DOCUMENTS as $key => $label): ?>
              <a href="<?= $h($base) ?>/pdf/<?= (int) $v['version'] ?>/<?= $h($key) ?>" target="_blank" rel="noopener" style="margin-right:.5rem"><?= $h($label) ?></a>
            <?php endforeach; ?>
          </td>
          <td>
            <?php if (!$v['released_at']): ?>
              <form method="post" action="<?= $h($base) ?>/freigeben" data-confirm="Fassung v<?= (int) $v['version'] ?> nach fachlicher Prüfung freigeben? Die PDFs werden ohne Entwurfs-Kopfzeile neu erzeugt.">
                <input type="hidden" name="version" value="<?= (int) $v['version'] ?>">
                <button type="submit" class="btn" style="font-size:.8rem"><?= icon('shield-check') ?> Freigeben</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<h3>Entwurf bearbeiten</h3>
<p style="font-size:.875rem;color:var(--gray-600)">Änderungen nach dem Erzeugen nur hier: speichern und eine neue Fassung erzeugen. Der Link zeigt dann die neue Fassung zum Download (90 Tage).</p>
<?php if ($missing && !$errors): ?>
  <div class="alert" style="margin-bottom:1rem;background:var(--gray-100)">Noch offen bis zum Erzeugen: <?= $h(implode(' ', $missing)) ?></div>
<?php endif; ?>
<?php
if (!$revoked) {
    $formAction = $base;
    $adminMode = true;
    require ROOT . '/src/views/partials/statute_questionnaire.php';
}
?>
<script src="<?= htmlspecialchars(assetUrl('/assets/js/statuten.js')) ?>" defer></script>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
