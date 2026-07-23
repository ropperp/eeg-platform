<?php $pageTitle = 'Meine Dokumente'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">📄 Meine Dokumente</h2>

<?php if (!empty($success)): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($info)): ?>
  <div class="alert" style="margin-bottom:1rem;background:#eff6ff;color:#1d4ed8"><?= htmlspecialchars($info) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">📅 Jahresübersicht</h3>
  <p style="color:var(--gray-600);font-size:.9rem;margin-bottom:1rem">
    Alle Ihre Rechnungen eines Jahres auf einen Blick – als druckbare Übersicht (auch als PDF speicherbar).
  </p>
  <a href="/portal/my/jahresuebersicht" target="_blank" class="btn btn-secondary">Jahresübersicht öffnen</a>
</div>

<?php if (($hasConsumer || $hasProducer) && contractsEnabled($member['community_id'])): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Meine Verträge</h3>
  <div style="display:flex;flex-direction:column;gap:.75rem">
    <?php
      $contractRows = [];
      if ($hasConsumer)  $contractRows['bezug']       = ['label' => 'Bezugsvereinbarung',    'color' => '#1d4ed8', 'icon' => '📄'];
      if ($hasProducer)  $contractRows['einspeisung'] = ['label' => 'Einspeisevereinbarung', 'color' => '#b45309', 'icon' => '☀️'];
    ?>
    <?php foreach ($contractRows as $type => $info_): $status = $member['contract_' . $type . '_status'] ?? 'none'; ?>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <a href="/portal/my/contract/<?= $type ?>" target="_blank" class="btn" style="background:<?= $info_['color'] ?>;color:#fff">
        <?= $info_['icon'] ?> <?= $info_['label'] ?> ansehen
      </a>
      <?php if ($status === 'signed'): ?>
        <span class="badge badge-green">✓ Unterschrieben am <?= date('d.m.Y', strtotime($member['contract_' . $type . '_signed_at'])) ?></span>
      <?php elseif ($status === 'created'): ?>
        <a href="/portal/my/contract/<?= $type ?>/sign" class="btn btn-primary">✍️ Jetzt unterschreiben</a>
      <?php else: ?>
        <span class="badge badge-gray">Noch nicht bereit</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($application)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Beitrittserklärung</h3>
  <a href="/portal/my/documents/formular" target="_blank" class="btn" style="background:var(--gray-100);color:var(--gray-700)">🖨️ Beitrittserklärung ansehen (PDF)</a>
</div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-bottom:1rem">📎 Meine Dateien</h3>
  <?php if (empty($member_files)): ?>
    <p style="color:var(--gray-600);font-size:.875rem">Es liegen noch keine Dateien vor (z. B. Beitrittserklärung, Ausweis-Scan).</p>
  <?php else: ?>
    <table style="font-size:.85rem">
      <thead>
        <tr><th>Name</th><th>Hochgeladen am</th><th>Aktion</th></tr>
      </thead>
      <tbody>
      <?php foreach ($member_files as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
          <td><a href="/portal/my/documents/<?= $f['id'] ?>/download">Herunterladen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
