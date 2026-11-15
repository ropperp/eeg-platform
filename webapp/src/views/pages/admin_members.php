<?php
// Alle Mitglieder aller EEGs für den Platform-Admin (Patrick, 14.11.2026) -- Suche nach Name,
// Geburtsdatum, Adresse, KdNr, E-Mail oder Zählpunkt; Klick öffnet die Mitglied-Details (dort
// "Ins Portal des Kunden"). Erwartet: $members (inkl. community_name), $communities, $q.
$pageTitle = 'Mitglieder';
ob_start(); ?>

<h2 style="margin-bottom:1rem"><?= icon('users-three') ?> Mitglieder aller Energiegemeinschaften</h2>

<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div class="admin-member-search">
    <input type="search" id="am-search" value="<?= htmlspecialchars($q) ?>" autocomplete="off" autofocus
           placeholder="Name, Geburtsdatum, Adresse, KdNr, E-Mail oder Zählpunkt…" aria-label="Mitglieder durchsuchen">
    <?php if (count($communities) > 1): ?>
    <select id="am-eeg" aria-label="Energiegemeinschaft" style="padding:.45rem .75rem;border:1px solid var(--gray-200,#e5e7eb);border-radius:8px">
      <option value="">Alle EEGs</option>
      <?php foreach ($communities as $c): ?>
        <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <p id="am-count" role="status" style="margin:.5rem 0 0;font-size:.82rem;color:var(--gray-600)"></p>
</div>

<div class="card" style="padding:0">
  <table class="rtable" id="am-table">
    <thead><tr>
      <th>KdNr</th><th>Name</th><th>Geburtsdatum</th><th>Adresse</th><th>EEG</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php $statusLabel = ['active' => ['Aktiv', 'green'], 'pending' => ['Ausstehend', 'yellow'], 'inactive' => ['Archiviert', 'gray']]; ?>
    <?php foreach ($members as $m): ?>
      <?php
        $gd = (string)($m['geburtsdatum'] ?? '');
        $gdText = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $gd, $d) ? "{$d[3]}.{$d[2]}.{$d[1]}" : $gd;
        [$stText, $stColor] = $statusLabel[$m['status']] ?? [$m['status'], 'gray'];
        $name = trim($m['first_name'] . ' ' . $m['last_name']);
      ?>
      <tr data-href="/portal/members/<?= htmlspecialchars($m['id']) ?>"
          data-search="<?= htmlspecialchars(memberSearchText($m)) ?>"
          data-eeg="<?= htmlspecialchars($m['community_name']) ?>" style="cursor:pointer">
        <td data-label="KdNr"><?= htmlspecialchars((string)($m['kundennummer'] ?? '–')) ?></td>
        <td data-label="Name">
          <a href="/portal/members/<?= htmlspecialchars($m['id']) ?>"><?= htmlspecialchars($name) ?></a>
          <?php if (!empty($m['company_name'])): ?><br><small style="color:var(--gray-600)"><?= htmlspecialchars($m['company_name']) ?></small><?php endif; ?>
        </td>
        <td data-label="Geburtsdatum"><?= htmlspecialchars($gdText !== '' ? $gdText : '–') ?></td>
        <td data-label="Adresse"><?= htmlspecialchars(trim(($m['address'] ?? '') . ', ' . ($m['zip'] ?? '') . ' ' . ($m['city'] ?? ''), ' ,')) ?></td>
        <td data-label="EEG"><?= htmlspecialchars($m['community_name']) ?></td>
        <td data-label="Status"><span class="badge badge-<?= $stColor ?>"><?= htmlspecialchars($stText) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$members): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Mitglieder.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script nonce="<?= cspNonce() ?>">
(function () {
  var input = document.getElementById('am-search');
  var eeg = document.getElementById('am-eeg');
  var rows = Array.prototype.slice.call(document.querySelectorAll('#am-table tbody tr[data-search]'));
  var count = document.getElementById('am-count');
  // Alle Suchwörter müssen vorkommen; KdNr/Zählpunkt auch ohne Leerzeichen (memberSearchText()).
  function match(text, q) {
    q = q.trim().toLowerCase();
    if (!q) return true;
    if (q.split(/\s+/).every(function (w) { return text.indexOf(w) !== -1; })) return true;
    return text.replace(/\s+/g, '').indexOf(q.replace(/\s+/g, '')) !== -1;
  }
  function apply() {
    var q = input.value, e = eeg ? eeg.value : '', n = 0;
    rows.forEach(function (r) {
      var show = match(r.dataset.search, q) && (!e || r.dataset.eeg === e);
      r.hidden = !show;
      if (show) n++;
    });
    count.textContent = n + (n === 1 ? ' Mitglied' : ' Mitglieder') + ' gefunden';
  }
  input.addEventListener('input', apply);
  if (eeg) eeg.addEventListener('change', apply);
  rows.forEach(function (r) {
    r.addEventListener('click', function (ev) {
      if (ev.target.closest('a')) return;
      location.href = r.dataset.href;
    });
  });
  apply();
})();
</script>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
