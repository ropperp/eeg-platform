<?php
/**
 * @var string $name @var string $csrf @var list<array<string,string>> $services @var string $dockerError
 * @var array{active: bool, message: string, until: string, since: string} $wartung
 * @var list<array<string,string>> $audit @var string $ok @var string $fehler
 */
$title = 'Steuerzentrale';
$badge = function (array $s): string {
    $st = $s['status'];
    if ($s['state'] === 'running' && str_contains($st, '(healthy)')) return 'ok';
    if ($s['state'] === 'running' && !str_contains($st, 'unhealthy') && !str_contains($st, 'starting')) return 'ok';
    if ($s['state'] === 'running') return 'warn';
    return 'bad';
};
ob_start(); ?>
<div class="bar">
  <span>Angemeldet als <strong><?= $h($name) ?></strong></span>
  <form method="post" action="/abmelden"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <button type="submit" class="btn">Abmelden</button></form>
</div>

<?php if ($ok !== ''): ?><p class="alert ok"><?= $h($ok) ?></p><?php endif; ?>
<?php if ($fehler !== ''): ?><p class="alert bad"><?= $h($fehler) ?></p><?php endif; ?>

<section class="card">
  <h2>Wartungsmodus</h2>
  <?php if ($wartung['active']): ?>
    <p class="alert warn"><strong>Aktiv</strong> seit <?= $h(date('d.m.Y H:i', (int) strtotime($wartung['since']))) ?>.
      Besucher sehen den Wartungshinweis<?= $wartung['until'] !== '' ? ', voraussichtlich bis ' . $h(date('d.m.Y H:i', (int) strtotime($wartung['until']))) : '' ?>.</p>
  <?php else: ?>
    <p>Aus. Die Seite ist normal erreichbar.</p>
  <?php endif; ?>
  <form method="post" action="/wartung" class="grid">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <label>Hinweis für Besucher
      <textarea name="text" rows="3" maxlength="500" placeholder="Wir führen gerade Wartungsarbeiten durch."><?= $h($wartung['message']) ?></textarea></label>
    <label>Voraussichtlich wieder online
      <input type="datetime-local" name="bis" value="<?= $h($wartung['active'] ? $wartung['until'] : '') ?>"></label>
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
  <table>
    <thead><tr><th>Dienst</th><th>Zustand</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($services as $s): ?>
      <tr>
        <td><strong><?= $h($s['name']) ?></strong><br><small><?= $h($s['image']) ?></small></td>
        <td><span class="dot <?= $badge($s) ?>"></span> <?= $h($s['status']) ?></td>
        <td class="actions">
          <a class="btn" href="/logs?dienst=<?= $h(rawurlencode($s['name'])) ?>">Logs</a>
          <form method="post" action="/neustart">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="dienst" value="<?= $h($s['name']) ?>">
            <button type="submit" class="btn" data-confirm="<?= $h($s['name']) ?> jetzt neu starten?">Neu starten</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Letzte Aktionen</h2>
  <table>
    <thead><tr><th>Zeit</th><th>Wer</th><th>Aktion</th><th>Ziel</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a): ?>
      <tr><td><?= $h(date('d.m.Y H:i', (int) strtotime($a['zeit'] ?? ''))) ?></td><td><?= $h($a['wer'] ?? '') ?></td><td><?= $h($a['aktion'] ?? '') ?></td><td><?= $h($a['ziel'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$audit): ?><tr><td colspan="4">Noch nichts.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php $inner = ob_get_clean(); require __DIR__ . '/layout.php';
