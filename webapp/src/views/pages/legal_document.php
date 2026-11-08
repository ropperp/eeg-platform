<?php
// Ein Rechtsdokument (AGB, Statuten, Widerruf, Impressum, Barrierefreiheit) -- Text aus
// legal_documents bzw. der Standardfassung, siehe legalDocument() in index.php.
// Erwartet: $docKey, $docTitle, $doc (oder null = noch nicht veröffentlicht), $community (null = Plattform).
$ownerName = $community['name'] ?? 'Strom für alle';
$pageTitle = $docTitle . ' — ' . $ownerName;
$pageDescription = $docTitle . ' der ' . $ownerName . '.';
if (!$doc) {
    $pageNoindex = true;
}
// Impressum: Vereinsdaten zusätzlich als strukturierte Daten (schema.org), aus den Stammdaten der EEG.
if ($docKey === 'impressum' && $community) {
    $extraHead = '<script type="application/ld+json">' . json_encode(array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'NGO',
        'name'          => $community['name'],
        'alternateName' => 'Strom für alle',
        'url'           => 'https://stromfueralle.at/' . strtolower($community['marktpartner_id']) . '/impressum',
        'identifier'    => array_values(array_filter([
            !empty($community['zvr_number']) ? ['@type' => 'PropertyValue', 'name' => 'ZVR-Zahl', 'value' => $community['zvr_number']] : null,
            ['@type' => 'PropertyValue', 'name' => 'Marktpartner-ID (E-Control)', 'value' => $community['marktpartner_id']],
        ])),
        'address'       => $community['address'] ?? null,
        'email'         => $community['contact_email'] ?? null,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
}
ob_start();
?>

<div class="legal">
  <h1><?= htmlspecialchars($docTitle) ?></h1>
  <div class="legal-meta">
    <?= htmlspecialchars($ownerName) ?>
    <?php if ($doc && !empty($doc['meta'])): ?> · <?= htmlspecialchars($doc['meta']) ?>
    <?php elseif ($doc && $doc['stand'] !== ''): ?> · Stand: <?= htmlspecialchars($doc['stand']) ?><?php endif; ?>
  </div>

  <?php if ($doc): ?>
    <?= $doc['html'] ?>
  <?php else: ?>
    <p>
      Die <?= htmlspecialchars($ownerName) ?> hat dieses Dokument noch nicht veröffentlicht.
      Bei Fragen wenden Sie sich bitte direkt an die Energiegemeinschaft<?php if (!empty($community['contact_email'])): ?>:
      <a href="mailto:<?= htmlspecialchars($community['contact_email']) ?>"><?= htmlspecialchars($community['contact_email']) ?></a><?php endif; ?>.
    </p>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
