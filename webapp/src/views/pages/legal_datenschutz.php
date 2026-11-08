<?php
// Datenschutzerklärung einer EEG = allgemeiner Plattform-Teil (für alle gleich, Plattform-Admin)
// + Ergänzungen der jeweiligen Energiegemeinschaft (Obmann). Patrick, 08.11.2026.
// Erwartet: $community, $platformDoc, $doc (EEG-Teil, null = noch nicht veröffentlicht).
$pageTitle = 'Datenschutzerklärung — ' . $community['name'];
$pageDescription = 'Welche Daten verarbeitet werden, zu welchem Zweck und wie lange -- sowie Ihre Rechte als Mitglied der ' . $community['name'] . ' nach der DSGVO.';
$stands = array_filter([$platformDoc['stand'] ?? '', $doc['stand'] ?? '']);
ob_start();
?>

<div class="legal">
  <h1>Datenschutzerklärung</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?><?php if ($stands): ?> · Stand: <?= htmlspecialchars(implode(' / ', array_unique($stands))) ?><?php endif; ?></div>

  <p class="legal-part">Teil 1 &ndash; Allgemeiner Teil (Plattform „Strom für alle“, gilt für alle Energiegemeinschaften)</p>
  <?= $platformDoc['html'] ?? '' ?>

  <p class="legal-part">Teil 2 &ndash; Ergänzungen der <?= htmlspecialchars($community['name']) ?></p>
  <?php if ($doc): ?>
    <?= $doc['html'] ?>
  <?php else: ?>
    <p>
      Die <?= htmlspecialchars($community['name']) ?> hat ihre Ergänzungen noch nicht veröffentlicht.
      Bis dahin gilt der allgemeine Teil oben.
    </p>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
