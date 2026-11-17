<?php
/**
 * Protokoll einer Mitgliederversammlung bearbeiten / unterschreiben lassen.
 * @var array<string, mixed> $a @var list<array<string, mixed>> $items @var list<array<string, mixed>> $signers
 * @var bool $deletable @var string $error @var string $success
 */
use App\Domain\Assembly\AssemblyService;
$pageTitle = 'Protokoll ' . date('d.m.Y', strtotime($a['held_on'])); ob_start();
$base = '/portal/versammlungen/' . htmlspecialchars($a['id']);
$isDraft = $a['status'] === 'draft';
$isSigning = $a['status'] === 'signing';
$isFinal = $a['status'] === 'final';
$isUpload = $a['source'] === 'upload';
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
$ro = $isDraft ? '' : 'disabled';
$signedCount = count(array_filter($signers, fn($s) => $s['signed_at'] !== null));
$allSigned = $signers && $signedCount === count($signers);
?>
<p style="margin-bottom:.5rem"><a href="/portal/versammlungen">← Alle Mitgliederversammlungen</a></p>
<h2 style="margin-bottom:.25rem"><?= icon('users-three') ?> <?= $h($a['title']) ?> vom <?= date('d.m.Y', strtotime($a['held_on'])) ?></h2>
<p style="margin-top:0;margin-bottom:1.25rem">
  <?php if ($isDraft): ?><span class="badge badge-gray">Entwurf</span>
  <?php elseif ($isSigning): ?><span class="badge badge-yellow">Unterschriften: <?= $signedCount ?> von <?= count($signers) ?></span>
  <?php else: ?><span class="badge badge-green">Abgeschlossen</span><?php endif; ?>
  <a href="<?= $base ?>/pdf" target="_blank" rel="noopener" class="btn" style="margin-left:.5rem;font-size:.82rem;background:var(--gray-100);color:var(--gray-700)">
    <?= icon('file-text') ?> <?= $isFinal ? 'PDF öffnen' : 'PDF-Vorschau' ?>
  </a>
</p>

<?php if ($error !== ''): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= $h($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= $h($success) ?></div><?php endif; ?>

<?php if ($isFinal): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-top:0"><?= icon('eye') ?> Sichtbarkeit</h3>
  <form method="post" action="<?= $base ?>/sichtbarkeit" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <label style="display:flex;gap:.5rem;align-items:center;cursor:pointer">
      <input type="checkbox" name="visible" value="1" style="width:auto" <?= in_array($a['visible_to_members'], [true, 't', '1', 1], true) ? 'checked' : '' ?>>
      Mitglieder dieser EEG dürfen das Protokoll unter „Meine Dokumente“ herunterladen
    </label>
    <button type="submit" class="btn btn-primary"><?= icon('floppy-disk') ?> Speichern</button>
  </form>
</div>
<?php endif; ?>

<?php if (!$isUpload): ?>
<!-- ─── Kopfdaten ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-top:0"><?= icon('calendar-blank') ?> Versammlung</h3>
  <form method="post" action="<?= $base ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 1rem">
      <div class="form-group"><label>Bezeichnung</label><input type="text" name="title" maxlength="160" value="<?= $h($a['title']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Art</label>
        <select name="kind" <?= $ro ?>>
          <?php foreach (AssemblyService::KINDS as $k => $label): ?>
            <option value="<?= $k ?>" <?= $a['kind'] === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Datum der Versammlung</label><input type="date" name="held_on" required value="<?= $h($a['held_on']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Beginn (Uhrzeit)</label><input type="time" name="time_from" value="<?= $h($a['time_from']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Ende (Uhrzeit)</label><input type="time" name="time_to" value="<?= $h($a['time_to']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Ort</label><input type="text" name="location" maxlength="200" value="<?= $h($a['location']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Anwesende Mitglieder</label><input type="number" min="0" name="attendees_count" value="<?= $h($a['attendees_count']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>davon stimmberechtigt</label><input type="number" min="0" name="voting_count" value="<?= $h($a['voting_count']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Vorsitz</label><input type="text" name="chair_name" maxlength="120" value="<?= $h($a['chair_name']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Protokollführung</label><input type="text" name="minutes_taker" maxlength="120" value="<?= $h($a['minutes_taker']) ?>" <?= $ro ?>></div>
      <div class="form-group"><label>Beschlussfähig?</label>
        <?php $q = $a['quorum'] === null ? '' : (in_array($a['quorum'], [true, 't', '1', 1], true) ? '1' : '0'); ?>
        <select name="quorum" <?= $ro ?>>
          <option value="" <?= $q === '' ? 'selected' : '' ?>>keine Angabe</option>
          <option value="1" <?= $q === '1' ? 'selected' : '' ?>>ja, beschlussfähig</option>
          <option value="0" <?= $q === '0' ? 'selected' : '' ?>>nein</option>
        </select>
      </div>
    </div>
    <div class="form-group"><label>Weitere Anmerkungen (optional, erscheinen am Ende)</label>
      <textarea name="notes" rows="3" maxlength="5000" <?= $ro ?>><?= $h($a['notes']) ?></textarea>
    </div>
    <?php if ($isDraft): ?><button type="submit" class="btn btn-primary"><?= icon('floppy-disk') ?> Speichern</button><?php endif; ?>
  </form>
</div>

<!-- ─── Tagesordnung ──────────────────────────────────────────────────── -->
<div class="card" id="punkte" style="margin-bottom:1.5rem">
  <h3 style="margin-top:0"><?= icon('list-checks') ?> Tagesordnung und Besprochenes</h3>
  <?php foreach ($items as $i => $it): ?>
    <details style="border:1px solid var(--gray-200);border-radius:8px;padding:.75rem 1rem;margin-bottom:.75rem" <?= $isDraft ? '' : 'open' ?>>
      <summary style="cursor:pointer;font-weight:600">TOP <?= $i + 1 ?>: <?= $h($it['title']) ?></summary>
      <form method="post" action="<?= $base ?>/punkte/<?= $h($it['id']) ?>" style="margin-top:.75rem">
        <div class="form-group"><label>Titel</label><input type="text" name="title" required maxlength="200" value="<?= $h($it['title']) ?>" <?= $ro ?>></div>
        <div class="form-group"><label>Besprochenes (Leerzeile = neuer Absatz)</label><textarea name="body" rows="5" <?= $ro ?>><?= $h($it['body']) ?></textarea></div>
        <div class="form-group"><label>Beschluss (optional)</label><textarea name="resolution" rows="2" <?= $ro ?>><?= $h($it['resolution']) ?></textarea></div>
        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 1rem">
          <div class="form-group"><label>Stimmen dafür</label><input type="number" min="0" name="votes_yes" value="<?= $h($it['votes_yes']) ?>" <?= $ro ?>></div>
          <div class="form-group"><label>dagegen</label><input type="number" min="0" name="votes_no" value="<?= $h($it['votes_no']) ?>" <?= $ro ?>></div>
          <div class="form-group"><label>Enthaltungen</label><input type="number" min="0" name="votes_abstain" value="<?= $h($it['votes_abstain']) ?>" <?= $ro ?>></div>
        </div>
        <?php if ($isDraft): ?><button type="submit" class="btn btn-primary"><?= icon('floppy-disk') ?> Punkt speichern</button><?php endif; ?>
      </form>
      <?php if ($isDraft): ?>
        <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap">
          <?php if ($i > 0): ?>
          <form method="post" action="<?= $base ?>/punkte/<?= $h($it['id']) ?>/verschieben"><input type="hidden" name="direction" value="up">
            <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"><?= icon('arrow-up') ?> nach oben</button></form>
          <?php endif; ?>
          <?php if ($i < count($items) - 1): ?>
          <form method="post" action="<?= $base ?>/punkte/<?= $h($it['id']) ?>/verschieben"><input type="hidden" name="direction" value="down">
            <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem"><?= icon('arrow-down') ?> nach unten</button></form>
          <?php endif; ?>
          <form method="post" action="<?= $base ?>/punkte/<?= $h($it['id']) ?>/loeschen">
            <button type="submit" class="btn btn-tint-red" style="font-size:.8rem" data-confirm="Tagesordnungspunkt löschen?"><?= icon('trash') ?> löschen</button></form>
        </div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
  <?php if (!$items): ?><p style="color:var(--gray-600)">Noch keine Tagesordnungspunkte.</p><?php endif; ?>

  <?php if ($isDraft): ?>
  <form method="post" action="<?= $base ?>/punkte" style="border-top:1px solid var(--gray-200);padding-top:1rem;margin-top:1rem">
    <h4 style="margin:0 0 .5rem"><?= icon('plus') ?> Tagesordnungspunkt hinzufügen</h4>
    <div class="form-group"><label>Titel</label><input type="text" name="title" required maxlength="200" placeholder="z. B. Bericht des Obmanns"></div>
    <div class="form-group"><label>Besprochenes (Leerzeile = neuer Absatz)</label><textarea name="body" rows="4"></textarea></div>
    <div class="form-group"><label>Beschluss (optional)</label><textarea name="resolution" rows="2"></textarea></div>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 1rem">
      <div class="form-group"><label>Stimmen dafür</label><input type="number" min="0" name="votes_yes"></div>
      <div class="form-group"><label>dagegen</label><input type="number" min="0" name="votes_no"></div>
      <div class="form-group"><label>Enthaltungen</label><input type="number" min="0" name="votes_abstain"></div>
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('plus') ?> Hinzufügen</button>
  </form>
  <?php endif; ?>
</div>

<!-- ─── Unterschriften ────────────────────────────────────────────────── -->
<div class="card" id="unterschriften" style="margin-bottom:1.5rem">
  <h3 style="margin-top:0"><?= icon('signature') ?> Unterschriften</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-top:0">
    Wer unterschreibt das Protokoll (z. B. Obmann, Schriftführer)? Nach der Freigabe kann jede Person
    <strong>vor Ort an diesem Gerät</strong> oder <strong>per Link am eigenen Handy</strong> unterschreiben
    (Link gilt <?= AssemblyService::LINK_DAYS ?> Tage). Sobald alle unterschrieben haben, wird das PDF erzeugt und abgelegt.
  </p>
  <table>
    <thead><tr><th>Name</th><th>Funktion</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($signers as $s): ?>
      <tr>
        <td><?= $h($s['name']) ?><?= $s['email'] ? '<br><span style="font-size:.8rem;color:var(--gray-600)">' . $h($s['email']) . '</span>' : '' ?></td>
        <td><?= $h($s['role_label']) ?></td>
        <td>
          <?php if ($s['signed_at'] !== null): ?>
            <span class="badge badge-green">unterschrieben <?= date('d.m.Y', strtotime($s['signed_at'])) ?></span>
          <?php elseif ($s['sign_token_hash'] !== null): ?>
            <span class="badge badge-yellow">Link verschickt</span>
          <?php else: ?>
            <span class="badge badge-gray">offen</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($isDraft): ?>
            <form method="post" action="<?= $base ?>/unterzeichner/<?= $h($s['id']) ?>/loeschen" style="display:inline">
              <button type="submit" class="btn btn-tint-red" style="font-size:.8rem" data-confirm="Unterzeichner entfernen?"><?= icon('trash') ?></button></form>
          <?php elseif ($isSigning && $s['signed_at'] === null): ?>
            <a href="<?= $base ?>/unterschreiben/<?= $h($s['id']) ?>" class="btn btn-primary" style="font-size:.8rem"><?= icon('signature') ?> Jetzt vor Ort</a>
            <?php if ($s['email']): ?>
            <form method="post" action="<?= $base ?>/unterzeichner/<?= $h($s['id']) ?>/link" style="display:inline">
              <button type="submit" class="btn" style="font-size:.8rem;background:var(--gray-100);color:var(--gray-700)"><?= icon('envelope-simple') ?> <?= $s['sign_token_hash'] !== null ? 'Link erneut senden' : 'Link senden' ?></button></form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$signers): ?><tr><td colspan="4" style="color:var(--gray-600)">Noch niemand eingetragen.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($isDraft): ?>
  <form method="post" action="<?= $base ?>/unterzeichner" style="border-top:1px solid var(--gray-200);padding-top:1rem;margin-top:1rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 1rem">
      <div class="form-group"><label>Name</label><input type="text" name="name" required maxlength="120"></div>
      <div class="form-group"><label>Funktion</label>
        <input type="text" name="role_label" required maxlength="80" list="assembly-roles" placeholder="z. B. Obmann">
        <datalist id="assembly-roles">
          <option value="Obmann"><option value="Obfrau"><option value="Obmann-Stellvertreter"><option value="Schriftführer">
          <option value="Schriftführerin"><option value="Kassier"><option value="Kassierin"><option value="Rechnungsprüfer"><option value="Rechnungsprüferin">
        </datalist>
      </div>
      <div class="form-group"><label>E-Mail (nur für Unterschrift per Link)</label><input type="email" name="email" maxlength="200"></div>
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('plus') ?> Unterzeichner hinzufügen</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; /* !$isUpload */ ?>

<!-- ─── Aktionen ──────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:2rem">
  <?php if ($isDraft): ?>
    <form method="post" action="<?= $base ?>/freigeben">
      <button type="submit" class="btn btn-primary" data-confirm="Protokoll zur Unterschrift freigeben? Danach kann der Inhalt nicht mehr geändert werden (solange noch niemand unterschrieben hat, geht es zurück in den Entwurf).">
        <?= icon('lock-key') ?> Fertig – zur Unterschrift freigeben</button>
    </form>
  <?php elseif ($isSigning && $signedCount === 0): ?>
    <form method="post" action="<?= $base ?>/entwurf">
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700)"><?= icon('pencil-simple') ?> Zurück in den Entwurf</button>
    </form>
  <?php endif; ?>
  <?php if ($isSigning && $allSigned): ?>
    <form method="post" action="<?= $base ?>/abschliessen">
      <button type="submit" class="btn btn-primary"><?= icon('check-circle') ?> PDF erzeugen und abschließen</button>
    </form>
  <?php endif; ?>
  <?php if ($deletable): ?>
    <form method="post" action="<?= $base ?>/loeschen">
      <button type="submit" class="btn btn-tint-red" data-confirm="Protokoll endgültig löschen?"><?= icon('trash') ?> Löschen</button>
    </form>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
