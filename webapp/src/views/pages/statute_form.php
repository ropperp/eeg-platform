<?php
/**
 * Statuten-Generator: Fragebogen über den persönlichen Einladungslink (Zustand „offen“).
 * @var string $token @var array $answers @var list<string> $errors @var list<string> $hints
 * @var string $flash @var string $expires
 */
$pageTitle = 'Statuten-Generator – Strom für alle';
$pageNoindex = true;
$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
$formAction = '/statuten/' . rawurlencode($token);
$adminMode = false;
ob_start();
?>
<section style="max-width:900px;margin:2rem auto;padding:0 1rem">
  <div class="card" style="margin-bottom:1rem">
    <h2 style="margin-top:0"><?= icon('file-text') ?> Statuten für Ihre Erneuerbare-Energie-Gemeinschaft</h2>
    <p>Beantworten Sie die Fragen in den Abschnitten A bis F. Sie können jederzeit <strong>zwischenspeichern</strong>
       und später über denselben Link weitermachen (gültig bis <?= $h(date('d.m.Y', strtotime($expires))) ?>).
       Mit <strong>„Statuten erzeugen“</strong> entsteht ein PDF; danach ist der Fragebogen über diesen Link nicht mehr
       änderbar und Sie können nur noch die Dokumente herunterladen.</p>
    <p class="statute-fixed" style="margin-bottom:0"><?= icon('warning-circle') ?> Vorlage, keine Rechtsberatung. Bitte die
       Statuten vor der Einreichung bei der Vereinsbehörde von einer fachkundigen Person prüfen lassen.</p>
  </div>
  <?php if ($flash !== ''): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= $h($flash) ?></div><?php endif; ?>
  <?php require ROOT . '/src/views/partials/statute_questionnaire.php'; ?>
</section>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/base.php';
