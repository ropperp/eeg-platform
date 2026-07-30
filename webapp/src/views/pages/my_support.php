<?php $pageTitle = 'Support'; ob_start(); ?>

<h2 style="margin-bottom:.5rem"><?= icon('chat-circle-text') ?> Support</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.5rem">
  Ein Problem oder einen Vorschlag für die Plattform? Hier landet es direkt bei der Verwaltung
  als Ticket -- statt per E-Mail hin und her.
</p>

<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;max-width:40rem">
  <h3 style="margin-bottom:1rem">Neues Ticket</h3>
  <form method="post" action="/portal/my/support">
    <div class="form-group">
      <label>Art</label>
      <select name="category">
        <option value="problem">Problem / Frage</option>
        <option value="feature">Feature-Vorschlag</option>
      </select>
    </div>
    <div class="form-group">
      <label>Betreff</label>
      <input type="text" name="subject" required maxlength="200" placeholder="Kurz zusammengefasst">
    </div>
    <div class="form-group">
      <label>Nachricht</label>
      <textarea name="message" required rows="5" placeholder="Was ist passiert, oder was schlägst du vor?"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Ticket erstellen</button>
  </form>
</div>

<div class="card" style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Betreff</th>
        <th>Art</th>
        <th>Status</th>
        <th>Zuletzt aktualisiert</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr style="cursor:pointer" onclick="location.href='/portal/my/support/<?= $t['id'] ?>'">
        <td><?= htmlspecialchars($t['subject']) ?></td>
        <td><?= $t['category'] === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage' ?></td>
        <td>
          <?php if ($t['status'] === 'offen'): ?>
            <span class="badge badge-yellow">Offen</span>
          <?php elseif ($t['status'] === 'in_bearbeitung'): ?>
            <span class="badge badge-yellow">In Bearbeitung</span>
          <?php else: ?>
            <span class="badge badge-green">Geschlossen</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($t['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($tickets)): ?>
      <tr><td colspan="4" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Tickets.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/portal.php';
