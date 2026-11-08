<?php
// Generische Dokument-Adresse ohne RC-Nummer (z.B. stromfueralle.at/datenschutz): zuerst der
// allgemeine Teil der Plattform, darunter die Auswahl aller aktiven EEGs (ein Klick führt auf
// /<rc>/<dokument>). Bewusst KEINE automatische Weiterleitung, auch wenn es nur eine EEG gibt --
// es kommen weitere dazu, und jede hat ihre eigenen Rechtsdokumente (Patrick, 07.11.2026).
// Allgemeiner Teil VOR der Auswahl (Patrick, 08.11.2026): Datenschutz, Impressum und Kontakt
// haben einen eigenen Plattform-Text ($platformDoc), die übrigen Seiten einen kurzen Hinweis.
// Erwartet: $docSlug, $docTitle, $communities, $platformDoc (oder null).
$pageTitle = $docTitle . ' — Strom für alle';
$pageDescription = $docTitle . ' der Plattform „Strom für alle“ und der Energiegemeinschaften darauf.';
$platformIntro = [
    'datenschutz' => 'Teil 1 gilt für die ganze Plattform „Strom für alle“. Was eine einzelne Energiegemeinschaft darüber hinaus verarbeitet (z.B. Verantwortlicher, Netzbetreiber, Ausleseeinheit), steht in deren Teil 2 – Auswahl unten.',
    'impressum'   => 'Angaben zum Betreiber der Plattform „Strom für alle“. Jede Energiegemeinschaft auf der Plattform ist ein eigener Verein mit eigenem Impressum – Auswahl unten.',
    'kontakt'     => 'So erreichen Sie den Betreiber der Plattform „Strom für alle“ – etwa wenn Sie selbst eine Energiegemeinschaft gründen oder Ihre Energiegemeinschaft auf die Plattform bringen möchten. Fragen zu Mitgliedschaft, Rechnung oder Ihren Daten richten Sie bitte direkt an Ihre Energiegemeinschaft – Auswahl unten.',
][$docSlug] ?? null;
$choiceHeading = [
    'datenschutz' => 'Teil 2 – Ergänzungen Ihrer Energiegemeinschaft',
    'impressum'   => 'Impressum einer Energiegemeinschaft',
    'kontakt'     => 'Kontakt zu einer Energiegemeinschaft',
][$docSlug] ?? 'Energiegemeinschaft wählen';
ob_start();
?>

<div class="legal">
  <h1><?= htmlspecialchars($docTitle) ?></h1>
  <?php if (!empty($platformDoc) && $platformDoc['stand'] !== '' && $docSlug !== 'kontakt'): ?>
    <div class="legal-meta">Strom für alle · Stand: <?= htmlspecialchars($platformDoc['stand']) ?></div>
  <?php endif; ?>

  <?php if (!empty($platformDoc)): ?>
    <p><?= htmlspecialchars($platformIntro) ?></p>
    <?php if ($docSlug === 'datenschutz'): ?>
      <p style="font-size:.9rem"><a href="#eeg-auswahl">Direkt zur Auswahl der Energiegemeinschaft ↓</a></p>
    <?php endif; ?>

    <p class="legal-part"><?= $docSlug === 'datenschutz' ? 'Teil 1 &ndash; Allgemeiner Teil (Plattform „Strom für alle“)' : 'Allgemein &ndash; Plattform „Strom für alle“' ?></p>
    <?= $platformDoc['html'] ?>

    <?php if ($docSlug === 'kontakt'): ?>
      <h2>Kontaktformular</h2>
      <?php $contactAction = '/kontakt'; $contactTopics = contactFormTopics(true); require __DIR__ . '/../partials/contact_form.php'; ?>
    <?php endif; ?>
  <?php else: ?>
    <p>
      Jede Energiegemeinschaft auf „Strom für alle“ hat eigene Unterlagen. Bitte wählen Sie unten
      die Energiegemeinschaft, deren <?= htmlspecialchars($docTitle) ?> Sie lesen möchten.
    </p>
    <p class="legal-part" style="font-weight:400">
      <strong>Allgemein:</strong> Fragen zur Plattform „Strom für alle“ selbst beantwortet der
      Plattformbetreiber – <a href="/kontakt">Kontakt</a> · <a href="/impressum">Impressum</a> ·
      <a href="/datenschutz">Datenschutz</a>.
    </p>
  <?php endif; ?>

  <p class="legal-part" id="eeg-auswahl"<?= empty($platformDoc) ? ' style="margin-top:2rem"' : '' ?>><?= $choiceHeading ?></p>
  <?php if (!empty($platformDoc)): ?>
    <p>Bitte wählen Sie Ihre Energiegemeinschaft:</p>
  <?php endif; ?>

  <?php require __DIR__ . '/../partials/community_search.php'; ?>

  <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
    <?php foreach ($communities as $c): ?>
      <?php $rc = strtolower($c['marktpartner_id'] ?? ''); ?>
      <div class="card community-card" data-search="<?= htmlspecialchars(mb_strtolower($c['name'] . ' ' . ($c['address'] ?? ''))) ?>"
           style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
        <div>
          <strong style="font-size:1.05rem"><?= htmlspecialchars($c['name']) ?></strong>
          <?php if (!empty($c['address'])): ?>
            <div style="font-size:.85rem;color:var(--gray-600)"><?= htmlspecialchars($c['address']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (in_array($rc, communitiesWithLegalPages(), true) || $docSlug === 'kontakt'): ?>
          <a href="/<?= htmlspecialchars($rc) ?>/<?= htmlspecialchars($docSlug) ?>" class="btn btn-primary">
            <?= htmlspecialchars($docSlug === 'datenschutz' ? 'Teil 2' : $docTitle) ?> öffnen
          </a>
        <?php else: ?>
          <span style="font-size:.85rem;color:var(--gray-600)">Unterlagen folgen in Kürze</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($communities)): ?>
      <p style="color:var(--gray-600)">Aktuell ist keine Energiegemeinschaft verfügbar.</p>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
