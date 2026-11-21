<?php
/**
 * Statuten-Generator: Link unbekannt, abgelaufen, widerrufen oder zu viele Aufrufe.
 * @var string $reason  unknown | expired | revoked | ratelimit | missing
 */
$pageTitle = 'Link nicht gültig – Strom für alle';
$pageNoindex = true;
$text = match ($reason) {
    'expired'   => 'Dieser Link ist abgelaufen.',
    'revoked'   => 'Dieser Link wurde zurückgezogen.',
    'ratelimit' => 'Zu viele Aufrufe in kurzer Zeit. Bitte versuchen Sie es in einigen Minuten erneut.',
    'missing'   => 'Das angeforderte Dokument ist nicht verfügbar.',
    default     => 'Dieser Link ist nicht gültig.',
};
ob_start();
?>
<section style="max-width:760px;margin:2rem auto;padding:0 1rem">
  <div class="card">
    <h2 style="margin-top:0">Statuten-Generator</h2>
    <p><?= htmlspecialchars($text) ?></p>
    <?php if ($reason !== 'ratelimit'): ?>
      <p>Wenn Sie einen neuen Link brauchen, wenden Sie sich bitte an Ihre Ansprechperson bei Strom für alle.</p>
    <?php endif; ?>
  </div>
</section>
<?php
$content = ob_get_clean();
require ROOT . '/src/views/layouts/base.php';
