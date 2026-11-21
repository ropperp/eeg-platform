<?php
/**
 * Statuten-Generator: Einladungslink im Zustand „erzeugt“ – nur noch Download.
 * @var string $token @var array|null $version @var array<string,string> $docs @var string $name @var string $expires
 */
$pageTitle = 'Ihre Statuten – Strom für alle';
$pageNoindex = true;
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
ob_start();
?>
<section style="max-width:760px;margin:2rem auto;padding:0 1rem">
  <div class="card">
    <h2 style="margin-top:0"><?= icon('check-circle') ?> Ihre Statuten sind erstellt</h2>
    <?php if ($name !== ''): ?><p style="margin-top:0"><strong><?= $h($name) ?></strong></p><?php endif; ?>
    <?php if ($version): ?>
      <p>Fassung <?= (int) $version['version'] ?> vom <?= $h(date('d.m.Y', strtotime((string) $version['generated_at']))) ?>
        <?= empty($version['released_at']) ? '· <strong>Entwurf</strong>' : '· geprüft und freigegeben' ?></p>
    <?php endif; ?>
    <ul style="list-style:none;padding:0;margin:1rem 0">
      <?php foreach ($docs as $key => $label): ?>
        <li style="margin-bottom:.5rem">
          <a class="btn" href="/statuten/<?= $h(rawurlencode($token)) ?>/pdf/<?= $h($key) ?>" target="_blank" rel="noopener">
            <?= icon('download-simple') ?> <?= $h($label) ?> (PDF)</a>
        </li>
      <?php endforeach; ?>
    </ul>
    <p style="font-size:.9rem;color:var(--gray-600)">Der Download ist bis <?= $h(date('d.m.Y', strtotime($expires))) ?> möglich.
       Der Fragebogen kann über diesen Link nicht mehr geändert werden. Änderungswünsche richten Sie bitte an Ihre
       Ansprechperson bei Strom für alle.</p>
    <p class="statute-fixed" style="margin-bottom:0"><?= icon('warning-circle') ?> Vorlage, keine Rechtsberatung. Vor der Einreichung
       von einer fachkundigen Person prüfen lassen.</p>
  </div>
</section>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/base.php';
