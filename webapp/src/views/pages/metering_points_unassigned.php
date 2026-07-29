<?php $pageTitle = 'Zählpunkte ohne Zuordnung'; ob_start(); ?>

<h2 style="margin-bottom:.5rem"><?= icon('warning-circle') ?> Zählpunkte ohne Zuordnung</h2>
<p style="color:var(--gray-600);font-size:.9rem;margin-bottom:1.5rem">
  Diese Zählpunkte kamen beim letzten EDA-Import in der Datei vor, waren bei uns aber noch nicht
  registriert (siehe Warnungen unter „EDA-Daten importieren"). Sie sind automatisch angelegt
  worden, aber noch keinem Mitglied zugeordnet und noch nicht aktiv -- erst nach der Zuordnung
  hier nehmen sie an einer Abrechnung teil.
</p>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Zählpunkt zugeordnet und aktiviert.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if (empty($unassigned)): ?>
  <div class="card" style="text-align:center;padding:2.5rem 1.5rem">
    <div style="font-size:2rem;margin-bottom:.5rem"><?= icon('check-circle') ?></div>
    <p style="color:var(--gray-600)">Keine offenen Zuordnungen -- alle aus EDA-Importen bekannten Zählpunkte sind zugeordnet.</p>
  </div>
<?php else: ?>
<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Zählpunktnummer</th>
        <th>Zählernummer</th>
        <th>Angelegt am</th>
        <th>Zuordnung</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($unassigned as $mp): ?>
      <tr>
        <td><code style="font-size:.78rem"><?= htmlspecialchars($mp['zaehlpunkt_nr']) ?></code></td>
        <td><?= $mp['meter_code'] ? '<code style="font-size:.78rem">' . htmlspecialchars($mp['meter_code']) . '</code>' : '—' ?></td>
        <td style="font-size:.85rem;color:var(--gray-600)"><?= $mp['registered_at'] ? date('d.m.Y', strtotime($mp['registered_at'])) : '—' ?></td>
        <td>
          <form method="post" action="/portal/metering-points/<?= $mp['id'] ?>/assign" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
            <select name="type" title="Typ (Vermutung, bitte prüfen)" style="padding:.3rem .5rem;border:1px solid var(--gray-200);border-radius:6px;font-size:.82rem">
              <option value="consumer" <?= $mp['type'] === 'consumer' ? 'selected' : '' ?>>Bezug</option>
              <option value="producer" <?= $mp['type'] === 'producer' ? 'selected' : '' ?>>Einspeisung</option>
              <option value="prosumer" <?= $mp['type'] === 'prosumer' ? 'selected' : '' ?>>Bezug + Einspeisung</option>
            </select>
            <select name="member_id" required style="padding:.3rem .5rem;border:1px solid var(--gray-200);border-radius:6px;font-size:.82rem;min-width:200px">
              <option value="">Mitglied wählen…</option>
              <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?><?= $m['kundennummer'] ? ' (KdNr ' . htmlspecialchars((string)$m['kundennummer']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary" style="padding:.3rem .7rem;font-size:.82rem">Zuordnen &amp; aktivieren</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
