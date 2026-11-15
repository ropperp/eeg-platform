<?php $pageTitle = 'Admin'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem"><?= icon('wrench') ?> Plattform-Administration</h2>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars(is_string($_GET['success']) && $_GET['success'] !== '1' ? $_GET['success'] : 'Gespeichert.') ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php // Kästen selbst anordnen (Patrick, 14.11.2026), siehe src/dashboard_widgets.php ?>
<?php
  $sum = fn(string $k) => array_sum(array_map(fn($e) => (int)($e[$k] ?? 0), $eegStats ?? []));
  $fmtAgo = function ($ts): string {
      if (!$ts) return 'noch nie';
      $min = (int)floor((time() - strtotime((string)$ts)) / 60);
      if ($min < 60) return 'vor ' . max(0, $min) . ' Min.';
      if ($min < 48 * 60) return 'vor ' . intdiv($min, 60) . ' Std.';
      return 'vor ' . intdiv($min, 1440) . ' Tagen';
  };
?>
<?php $dw = new DashboardWidgets('admin', dashboardLayout('admin')); $dw->start('mitglied-suchen', 'Mitglied suchen'); ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('magnifying-glass') ?> Mitglied suchen</h3>
  <form method="get" action="/admin/members" class="admin-member-search" role="search">
    <input type="search" name="q" placeholder="Name, Geburtsdatum, Adresse, KdNr, E-Mail oder Zählpunkt…"
           aria-label="Mitglied suchen" autocomplete="off">
    <button type="submit" class="btn btn-primary"><?= icon('magnifying-glass') ?> Suchen</button>
    <a href="/admin/members" class="btn btn-secondary"><?= icon('users-three') ?> Alle Mitglieder</a>
  </form>
</div>

<?php $dw->end(); $dw->start('kennzahlen', 'Kennzahlen'); ?>
<div class="grid-4 admin-stats" style="margin-bottom:2rem">
  <div class="card stat-card">
    <div class="stat-value"><?= count($communities) ?></div>
    <div class="stat-label">Energie&shy;gemeinschaften</div>
  </div>
  <a class="card stat-card" href="/admin/members" style="text-decoration:none;color:inherit">
    <div class="stat-value"><?= $sum('members') ?></div>
    <div class="stat-label">Aktive Mitglieder</div>
  </a>
  <div class="card stat-card">
    <div class="stat-value"><?= $sum('live') ?> / <?= $sum('meters') ?></div>
    <div class="stat-label">Zählpunkte mit Live-Daten</div>
  </div>
  <div class="card stat-card">
    <div class="stat-value"><?= $userCount ?></div>
    <div class="stat-label">Benutzerkonten</div>
  </div>
</div>

<?php $dw->end(); $dw->start('aufgaben', 'Offene Aufgaben je EEG'); ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.25rem"><?= icon('list-checks') ?> Offene Aufgaben je EEG</h3>
  <p style="font-size:.82rem;color:var(--gray-600);margin-bottom:.75rem">
    Bearbeiten in der Obmann-Rolle der jeweiligen EEG (Rollenwahl oben).
  </p>
  <table class="rtable">
    <thead><tr>
      <th>EEG</th><th>Beitritts&shy;anträge</th><th>Postfach</th><th>Support</th><th>Zählpunkte ohne Mitglied</th><th>Letzter EDA-Import</th>
    </tr></thead>
    <tbody>
    <?php foreach ($eegStats ?? [] as $e): ?>
      <?php $badge = fn(int $n, string $cls = 'badge-red') => $n > 0 ? '<span class="badge ' . $cls . '">' . $n . '</span>' : '<span style="color:var(--gray-500)">–</span>'; ?>
      <tr>
        <td data-label="EEG"><a href="/admin/communities/<?= htmlspecialchars($e['id']) ?>"><?= htmlspecialchars($e['name']) ?></a></td>
        <td data-label="Beitrittsanträge"><?= $badge((int)$e['applications'], 'badge-yellow') ?></td>
        <td data-label="Postfach"><?= $badge((int)$e['notifications']) ?></td>
        <td data-label="Support"><?= $badge((int)$e['support']) ?></td>
        <td data-label="Ohne Mitglied"><?= $badge((int)$e['unassigned'], 'badge-yellow') ?></td>
        <td data-label="Letzter EDA-Import">
          <?= htmlspecialchars($fmtAgo($e['last_import'])) ?>
          <?php if ($e['last_interval_import']): ?><br><small style="color:var(--gray-600)">Viertelstunden: <?= htmlspecialchars($fmtAgo($e['last_interval_import'])) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php $dw->end(); $dw->start('systemstatus', 'Systemstatus'); ?>
<?php
  $edaLast = $mailConfig['eda_import_last_run_at'] ?? null;
  $edaConfigured = trim((string)($mailConfig['eda_import_mailbox_address'] ?? '')) !== '';
  $edaStale = $edaConfigured && (!$edaLast || (time() - strtotime((string)$edaLast)) > 20 * 60);
  $backupUnix = (int)($backupStatus['unix'] ?? 0);
  $backupStale = !$backupUnix || (time() - $backupUnix) > 26 * 3600;
  $testMode = !empty($platformSettings['test_mode']);
?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('pylon') ?> Systemstatus</h3>
  <ul class="admin-status-list">
    <li>
      <span class="admin-status-dot <?= !$edaConfigured ? 'is-off' : ($edaStale ? 'is-bad' : 'is-ok') ?>" aria-hidden="true"></span>
      <span><strong>EDA-Postfach:</strong>
        <?php if (!$edaConfigured): ?>nicht eingerichtet
        <?php else: ?>letzter Check <?= htmlspecialchars($fmtAgo($edaLast)) ?><?= $edaStale ? ' – Cron läuft offenbar nicht' : '' ?><?php endif; ?>
      </span>
      <a href="/admin/mail-settings#tab-eda">Details</a>
    </li>
    <li>
      <span class="admin-status-dot <?= $backupStale ? 'is-bad' : 'is-ok' ?>" aria-hidden="true"></span>
      <span><strong>Sicherung:</strong> <?= $backupUnix ? 'zuletzt ' . htmlspecialchars($fmtAgo(date('c', $backupUnix))) : 'keine Angabe gefunden' ?></span>
      <a href="/admin/backups">Details</a>
    </li>
    <li>
      <span class="admin-status-dot <?= $testMode ? 'is-warn' : 'is-ok' ?>" aria-hidden="true"></span>
      <span><strong>Betriebsart:</strong> <?= $testMode ? 'Testmodus' : 'Echtbetrieb' ?></span>
      <a href="/admin/mail-settings">Einstellungen</a>
    </li>
  </ul>
</div>

<?php $dw->end(); $dw->start('schnellzugriff', 'Schnellzugriff'); ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.75rem"><?= icon('lightning') ?> Schnellzugriff</h3>
  <div class="admin-quick">
    <a href="/admin/members"><?= icon('users-three') ?> Mitglieder</a>
    <a href="/admin/log"><?= icon('clipboard-text') ?> Aktivitätslog</a>
    <a href="/admin/mail-settings"><?= icon('gear') ?> Einstellungen</a>
    <a href="/admin/mail-settings#tab-eda"><?= icon('folder-open') ?> EDA-Automatik</a>
    <a href="/admin/templates"><?= icon('folder-simple') ?> Vorlagen &amp; Dateien</a>
    <a href="/portal/dokumente"><?= icon('file-text') ?> Rechtstexte</a>
    <a href="/admin/backups"><?= icon('floppy-disk') ?> Backups</a>
  </div>
</div>

<?php $dw->end(); $dw->start('eeg-anlegen', 'Neue EEG anlegen'); ?>
<!-- Neue EEG anlegen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">+ Neue EEG anlegen</h3>
  <form method="post" action="/admin/communities">
    <div class="grid-2">
      <div class="form-group">
        <label>Name der EEG</label>
        <input type="text" name="name" required placeholder="EEG Mustergemeinschaft">
      </div>
      <div class="form-group">
        <label>Marktpartner-ID</label>
        <input type="text" name="marktpartner_id" placeholder="RC108175">
      </div>
      <div class="form-group">
        <label>Adresse</label>
        <input type="text" name="address" placeholder="Musterstraße 1, 1234 Musterort">
      </div>
    </div>
    <div class="form-group">
      <label>
        <input type="checkbox" name="uses_ausleseeinheit" value="1" checked>
        Mitglieder bekommen eine Ausleseeinheit (Live-Daten über die P1-Kundenschnittstelle)
      </label>
      <p style="font-size:.8rem;color:var(--gray-600);margin-top:.25rem">
        Abwählen für eine „normale“ Energiegemeinschaft ohne eigene Messgeräte: dann verschwinden
        Ausleseeinheit, P1-Schlüssel und Live-Werte aus Beitritt, Portal und Rechtstexten.
      </p>
    </div>
    <button class="btn btn-primary">EEG anlegen</button>
  </form>
</div>

<?php $dw->end(); $dw->start('eeg-liste', 'Alle Energiegemeinschaften'); ?>
<!-- EEG-Liste -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem">Alle Energiegemeinschaften</h3>
  <table>
    <thead>
      <tr><th>Name</th><th>Slug</th><th>Marktpartner-ID</th><th>Status</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($communities as $c): ?>
      <tr>
        <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
        <td><code style="font-size:.8rem"><?= htmlspecialchars($c['slug']) ?></code></td>
        <td><?= htmlspecialchars($c['marktpartner_id'] ?? '—') ?></td>
        <td>
          <span class="badge badge-<?= $c['active'] ? 'green' : 'gray' ?>">
            <?= $c['active'] ? 'aktiv' : 'inaktiv' ?>
          </span>
        </td>
        <td><a href="/admin/communities/<?= $c['id'] ?>" style="font-size:.8rem">Konfigurieren</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php $dw->end(); $dw->start('benutzer', 'Benutzer & Rollen'); ?>
<!-- Benutzer & Rollen -->
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:1rem"><?= icon('user-circle') ?> Benutzer & Rollen</h3>

  <table style="margin-bottom:1.5rem">
    <thead>
      <tr><th>E-Mail</th><th>Name</th><th>Rollen</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
          <?php if (!empty($u['founder_admin'])): ?>
            <span class="badge badge-green" style="margin-left:.35rem" title="Kann von niemandem entfernt werden">Ersteller-Admin</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.8rem">
          <?php foreach ($u['roles'] as $r): ?>
            <span class="badge badge-<?= $r['role'] === 'platform_admin' ? 'green' : 'yellow' ?>" style="margin-right:.25rem">
              <?= htmlspecialchars($r['role']) ?>
              <?php if ($r['community_name']): ?>
                (<?= htmlspecialchars($r['community_name']) ?>)
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
          <?php if (empty($u['roles'])): ?>
            <span style="color:var(--gray-600)">keine</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="/admin/users/<?= $u['id'] ?>" style="font-size:.8rem">Rollen verwalten</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php $dw->end(); $dw->start('admin-rollen', 'Platform-Admins einladen'); ?>
<?php if (Auth::isFounderAdmin()): ?>
<!-- Nur der Ersteller-Admin darf die Platform-Admin-Rollenverteilung selbst verändern
     (Patrick, 09.10.2026: "Bis halt auf die Rollenverteilung") -- Unteradmins sehen diese
     Karte gar nicht erst. -->
<div class="card">
  <h3 style="margin-bottom:.5rem"><?= icon('shield-check') ?> Neuen Platform-Admin (Unteradmin) einladen</h3>
  <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:1rem">
    Existiert bereits ein Login mit dieser E-Mail (z.B. ein bestehender Obmann-Account), bekommt
    genau dieser Account zusätzlich Platform-Admin-Zugang -- kein zweiter Account nötig, Vor-/
    Nachname werden dann ignoriert. Bei einer neuen E-Mail wird ein Account angelegt und ein
    Link zum Passwort-Setzen per Mail verschickt (48 Stunden gültig).
  </p>
  <form method="post" action="/admin/platform-admins/invite">
    <div class="grid-2">
      <div class="form-group" style="grid-column:1 / -1">
        <label>E-Mail</label>
        <input type="email" name="email" required placeholder="kollege@beispiel.at">
      </div>
      <div class="form-group">
        <label>Vorname (nur bei neuem Account)</label>
        <input type="text" name="first_name">
      </div>
      <div class="form-group">
        <label>Nachname (nur bei neuem Account)</label>
        <input type="text" name="last_name">
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Platform-Admin-Zugang einrichten</button>
  </form>
</div>
<?php endif; ?>

<?php $dw->end(); echo $dw->render(); ?>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
