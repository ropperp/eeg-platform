<?php
$pageTitle = 'Datenqualität – ' . $imp['filename'];
ob_start();
?>

<h2 style="margin-bottom:.25rem"><?= icon('chart-bar') ?> Datenqualität</h2>
<p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1.5rem">
  Import <code><?= htmlspecialchars($imp['filename']) ?></code>,
  Zeitraum <?= date('d.m.Y', strtotime($imp['period_from'])) ?> – <?= date('d.m.Y', strtotime($imp['period_to'])) ?>.
</p>

<?php require __DIR__ . '/../partials/eda_quality_report.php'; ?>

<a href="/portal/eda/upload" style="font-size:.85rem">&larr; Zurück zu EDA-Daten importieren</a>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
