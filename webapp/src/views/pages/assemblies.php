<?php
/** @var list<array<string, mixed>> $assemblies @var string $error @var string $success */
use App\Domain\Assembly\AssemblyService;
$pageTitle = 'Mitgliederversammlungen'; ob_start();
$statusBadge = [
    'draft'   => '<span class="badge badge-gray">Entwurf</span>',
    'signing' => '<span class="badge badge-yellow">Unterschriften offen</span>',
    'final'   => '<span class="badge badge-green">Abgeschlossen</span>',
];
?>
<h2 style="margin-bottom:.5rem"><?= icon('users-three') ?> Mitgliederversammlungen</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
  Protokolle der Mitgliederversammlungen (mindestens einmal im Jahr). Neu erstellen, zur Unterschrift freigeben
  und als PDF ablegen. Abgeschlossene Protokolle sehen die Mitglieder unter „Meine Dokumente“.
</p>

<?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card" style="overflow-x:auto;margin-bottom:1.5rem">
  <table>
    <thead><tr><th>Datum</th><th>Versammlung</th><th>Status</th><th>Unterschriften</th><th>Für Mitglieder</th></tr></thead>
    <tbody>
    <?php foreach ($assemblies as $x): ?>
      <tr style="cursor:pointer" data-href="/portal/versammlungen/<?= htmlspecialchars($x['id']) ?>">
        <td style="white-space:nowrap"><?= date('d.m.Y', strtotime($x['held_on'])) ?></td>
        <td><?= htmlspecialchars($x['title']) ?><?= $x['source'] === 'upload' ? ' <span class="badge badge-gray">PDF hochgeladen</span>' : '' ?></td>
        <td><?= $statusBadge[$x['status']] ?? '' ?></td>
        <td><?= $x['source'] === 'upload' ? '–' : (int) $x['signers_done'] . ' / ' . (int) $x['signers_total'] ?></td>
        <td><?= $x['status'] !== 'final' ? '–' : (in_array($x['visible_to_members'], [true, 't', '1', 1], true) ? 'sichtbar' : 'verborgen') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$assemblies): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Protokolle.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem">
  <div class="card">
    <h3 style="margin-top:0"><?= icon('plus') ?> Neue Mitgliederversammlung</h3>
    <form method="post" action="/portal/versammlungen">
      <div class="form-group">
        <label>Datum der Versammlung</label>
        <input type="date" name="held_on" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label>Art</label>
        <select name="kind">
          <?php foreach (AssemblyService::KINDS as $k => $label): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><?= icon('pencil-simple') ?> Protokoll anlegen</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0"><?= icon('paperclip') ?> Fertiges Protokoll hochladen</h3>
    <p style="color:var(--gray-600);font-size:.85rem;margin-top:0">
      Für Protokolle, die auf Papier unterschrieben und eingescannt wurden (PDF, max. 20 MB).
    </p>
    <form method="post" action="/portal/versammlungen/upload" enctype="multipart/form-data">
      <div class="form-group">
        <label>Datum der Versammlung</label>
        <input type="date" name="held_on" required>
      </div>
      <div class="form-group">
        <label>Art</label>
        <select name="kind">
          <?php foreach (AssemblyService::KINDS as $k => $label): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>PDF-Datei</label>
        <input type="file" name="pdf" accept="application/pdf,.pdf" required>
      </div>
      <button type="submit" class="btn btn-primary"><?= icon('paperclip') ?> Hochladen</button>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
