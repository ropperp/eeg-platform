<?php
/**
 * @var string $name @var string $csrf @var list<array<string,string>> $services @var string $dockerError
 * @var array{active: bool, message: string, until: string, since: string} $wartung
 * @var list<array<string,string>> $audit @var string $ok @var string $fehler
 */
$title = 'Steuerzentrale – strom für alle';
$who = $name;
$level = function (array $s): string {
    if ($s['state'] !== 'running') return 'bad';
    return (str_contains($s['status'], 'unhealthy') || str_contains($s['status'], 'starting')) ? 'warn' : 'ok';
};
$label = ['ok' => 'läuft', 'warn' => 'startet / gestört', 'bad' => 'gestoppt'];
$ziel = fn(string $z): string => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $z) ? 'bis ' . date('d.m.Y H:i', (int) strtotime($z)) : $z;
$counts = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($services as $s) { $counts[$level($s)]++; }
$aktionen = [
    'anmeldung' => 'Anmeldung', 'abmeldung' => 'Abmeldung', 'anmeldung_fehlgeschlagen' => 'Anmeldung abgelehnt',
    'neustart' => 'Neustart', 'neustart_fehlgeschlagen' => 'Neustart fehlgeschlagen',
    'wartung_ein' => 'Wartung ein', 'wartung_aus' => 'Wartung aus',
];
ob_start(); ?>
<?php if ($ok !== ''): ?><p class="alert ok"><?= $h($ok) ?></p><?php endif; ?>
<?php if ($fehler !== ''): ?><p class="alert bad"><?= $h($fehler) ?></p><?php endif; ?>

<div class="stats">
  <div class="stat <?= $counts['bad'] ? 'bad' : ($counts['warn'] ? 'warn' : '') ?>">
    <div class="value"><?= $counts['ok'] ?> / <?= count($services) ?></div>
    <div class="label">Dienste laufen einwandfrei</div>
  </div>
  <div class="stat <?= $wartung['active'] ? 'warn' : '' ?>">
    <div class="value"><?= $wartung['active'] ? 'Wartung' : 'Online' ?></div>
    <div class="label"><?= $wartung['active'] ? 'Besucher sehen den Wartungshinweis' : 'Plattform ist normal erreichbar' ?></div>
  </div>
  <div class="stat">
    <div class="value"><?= $h(date('H:i')) ?></div>
    <div class="label">Stand <?= $h(date('d.m.Y')) ?> · <a href="/">aktualisieren</a></div>
  </div>
</div>

<section class="card <?= $wartung['active'] ? 'maint-on' : '' ?>">
  <h2>Wartungsmodus <?= $wartung['active'] ? '· aktiv' : '' ?></h2>
  <?php if ($wartung['active']): ?>
    <p class="alert warn">Aktiv seit <?= $h(date('d.m.Y H:i', (int) strtotime($wartung['since']))) ?> Uhr<?= $wartung['until'] !== '' ? ', voraussichtlich bis ' . $h(date('d.m.Y H:i', (int) strtotime($wartung['until']))) . ' Uhr' : '' ?>.</p>
  <?php else: ?>
    <p class="muted">Schaltet die öffentliche Seite und das Portal auf einen Wartungshinweis um, z. B. vor einem Update.
      Diese Steuerzentrale bleibt erreichbar.</p>
  <?php endif; ?>
  <form method="post" action="/wartung" class="form">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <div class="two">
      <label>Hinweis für Besucher
        <textarea name="text" rows="3" maxlength="500" placeholder="Wir führen gerade Wartungsarbeiten durch. Bitte versuchen Sie es in Kürze erneut."><?= $h($wartung['message']) ?></textarea></label>
      <label>Voraussichtlich wieder online
        <input type="datetime-local" name="bis" value="<?= $h($wartung['active'] ? $wartung['until'] : '') ?>"></label>
    </div>
    <div class="row">
      <button type="submit" name="aktion" value="ein" class="btn warn" data-confirm="Wartungsmodus einschalten? Alle Besucher sehen dann nur noch den Wartungshinweis.">
        <?= $wartung['active'] ? 'Hinweis aktualisieren' : 'Wartungsmodus einschalten' ?></button>
      <?php if ($wartung['active']): ?>
        <button type="submit" name="aktion" value="aus" class="btn primary">Wartungsmodus beenden</button>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="card">
  <h2>Dienste</h2>
  <?php if ($dockerError !== ''): ?><p class="alert bad">Docker nicht erreichbar: <?= $h($dockerError) ?></p><?php endif; ?>
  <div class="services">
    <?php foreach ($services as $s): $l = $level($s); ?>
      <div class="svc <?= $l === 'ok' ? '' : $l ?>">
        <span class="name"><?= $h($s['name']) ?></span>
        <span class="pill <?= $l === 'ok' ? '' : $l ?>"><?= $s['state'] === 'fehlt' ? 'fehlt' : $label[$l] ?></span>
        <span class="status"><?= $h($s['status']) ?></span>
        <span class="image"><?= $h($s['image']) ?></span>
        <div class="actions">
          <a class="btn" href="/logs?dienst=<?= $h(rawurlencode($s['name'])) ?>">Logs</a>
          <form method="post" action="/neustart">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="dienst" value="<?= $h($s['name']) ?>">
            <button type="submit" class="btn" data-confirm="<?= $h($s['name']) ?> jetzt neu starten?"><?= $l === 'bad' ? 'Starten' : 'Neu starten' ?></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2>Letzte Aktionen</h2>
  <table>
    <thead><tr><th>Zeit</th><th>Wer</th><th>Aktion</th><th>Ziel</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a): ?>
      <tr><td><?= $h(date('d.m.Y H:i', (int) strtotime($a['zeit'] ?? ''))) ?></td><td><?= $h($a['wer'] ?? '') ?></td>
        <td><?= $h($aktionen[$a['aktion'] ?? ''] ?? ($a['aktion'] ?? '')) ?></td><td><?= $h($ziel($a['ziel'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$audit): ?><tr><td colspan="4" class="muted">Noch keine Aktionen.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
