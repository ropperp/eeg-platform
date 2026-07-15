<?php $pageTitle = 'Dateien: ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/portal/files" style="color:#6b7280;text-decoration:none">← Dateien</a>
  <h2 style="margin:0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h2>
  <span class="badge badge-gray" style="font-weight:700;color:#15803d">KdNr <?= htmlspecialchars((string)($member['kundennummer'] ?? '—')) ?></span>
  <a href="/portal/members/<?= $member['id'] ?>" style="margin-left:auto;font-size:.85rem">Zum Mitgliedskonto →</a>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">📄 Beitrittserklärung &amp; Verträge</h3>
  <table style="font-size:.9rem">
    <tbody>
      <tr>
        <th>Beitrittserklärung</th>
        <td>
          <?php if (!empty($application)): ?>
            <a href="/portal/applications/<?= $application['id'] ?>/formular" target="_blank">🖨️ Formular als PDF öffnen</a>
          <?php elseif (!empty($filesByCategory['beitritt'])): ?>
            <span style="color:#9ca3af">Kein Online-Beitrittsformular vorhanden —</span>
          <?php else: ?>
            <span style="color:#9ca3af">Kein Online-Beitrittsformular vorhanden</span>
          <?php endif; ?>
          <?php if (!empty($filesByCategory['beitritt'])): $f = $filesByCategory['beitritt']; ?>
            <a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download">📎 <?= htmlspecialchars($f['name']) ?> (hochgeladen)</a>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Bezugsvereinbarung</th>
        <td>
          <?php if ($hasConsumer): ?>
            <a href="/portal/members/<?= $member['id'] ?>/contract/bezug" target="_blank">📄 PDF öffnen</a>
          <?php elseif (empty($filesByCategory['bezug'])): ?>
            <span style="color:#9ca3af">Kein Bezugs-Zählpunkt registriert</span>
          <?php endif; ?>
          <?php if (!empty($filesByCategory['bezug'])): $f = $filesByCategory['bezug']; ?>
            <?= $hasConsumer ? ' &nbsp;·&nbsp; ' : '' ?><a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download">📎 <?= htmlspecialchars($f['name']) ?> (hochgeladen)</a>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Einspeisevereinbarung</th>
        <td>
          <?php if ($hasProducer): ?>
            <a href="/portal/members/<?= $member['id'] ?>/contract/einspeisung" target="_blank">☀️ PDF öffnen</a>
          <?php elseif (empty($filesByCategory['einspeisung'])): ?>
            <span style="color:#9ca3af">Kein Einspeise-Zählpunkt registriert</span>
          <?php endif; ?>
          <?php if (!empty($filesByCategory['einspeisung'])): $f = $filesByCategory['einspeisung']; ?>
            <?= $hasProducer ? ' &nbsp;·&nbsp; ' : '' ?><a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download">📎 <?= htmlspecialchars($f['name']) ?> (hochgeladen)</a>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Ausweisdokument</th>
        <td>
          <?php if (!empty($filesByCategory['ausweis'])): $f = $filesByCategory['ausweis']; ?>
            <a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download">📎 <?= htmlspecialchars($f['name']) ?> (hochgeladen)</a>
          <?php else: ?>
            <span style="color:#9ca3af">Kein Ausweisdokument hochgeladen</span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card">
  <h3 style="margin-bottom:1rem">📎 Hochgeladene Dateien</h3>
  <?php if (empty($member_files)): ?>
    <p style="color:#6b7280;font-size:.875rem">Noch keine Dateien hochgeladen.</p>
  <?php else: ?>
    <table style="font-size:.85rem">
      <thead>
        <tr><th>Name</th><th>Hochgeladen am</th><th>Aktionen</th></tr>
      </thead>
      <tbody>
      <?php foreach ($member_files as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
          <td>
            <a href="/portal/members/<?= $member['id'] ?>/files/<?= $f['id'] ?>/download">Herunterladen</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
