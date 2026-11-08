<?php
// Kontaktseite einer EEG -- Daten aus den Stammdaten (Einstellungen -> Kontakt-E-Mail/-Telefon).
$rc = strtolower($community['marktpartner_id']);
$kontaktEmail = trim((string)($community['contact_email'] ?? '')) ?: 'office@stromfueralle.at';
$kontaktTel = trim((string)($community['contact_phone'] ?? ''));
$pageTitle = 'Kontakt — ' . $community['name'];
$pageDescription = 'Kontaktmöglichkeiten der ' . $community['name'] . ' für Interessierte und Mitglieder.';
ob_start();
?>

<div class="legal">
  <h1>Kontakt</h1>
  <div class="legal-meta"><?= htmlspecialchars($community['name']) ?><?php if (!empty($community['zvr_number'])): ?> · ZVR <?= htmlspecialchars($community['zvr_number']) ?><?php endif; ?></div>

  <p>
    Sie haben Fragen — zur Mitgliedschaft, zur Abrechnung oder zu Ihren bei uns
    gespeicherten personenbezogenen Daten? Melden Sie sich gerne direkt bei uns.
  </p>

  <h2>Kontakt</h2>
  <p>
    E-Mail: <a href="mailto:<?= htmlspecialchars($kontaktEmail) ?>"><?= htmlspecialchars($kontaktEmail) ?></a>
    <?php if ($kontaktTel !== ''): ?><br>Telefon: <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $kontaktTel)) ?>"><?= htmlspecialchars($kontaktTel) ?></a><?php endif; ?>
  </p>
  <p>Vertretungsbefugte Personen siehe <a href="/<?= htmlspecialchars($rc) ?>/impressum">Impressum</a>.</p>

  <?php if (!empty($community['address'])): ?>
  <h2>Vereinssitz</h2>
  <p>
    <?= htmlspecialchars($community['name']) ?><br>
    <?= htmlspecialchars($community['address']) ?>
  </p>
  <?php endif; ?>

  <h2>Fragen zum Datenschutz</h2>
  <p>
    Für Anfragen zu Auskunft, Berichtigung oder Löschung Ihrer Daten siehe auch unsere
    <a href="/<?= htmlspecialchars($rc) ?>/datenschutz">Datenschutzerklärung</a> — oder nutzen Sie das Formular
    unten (Anliegen "Datenschutz") bzw. schreiben Sie uns direkt eine E-Mail.
  </p>

  <h2>Kontaktformular</h2>
  <?php $contactAction = '/' . $rc . '/kontakt'; $contactTopics = contactFormTopics(false); require __DIR__ . '/../partials/contact_form.php'; ?>

  <p style="margin-top:2rem;font-size:.9rem;color:var(--gray-600)">
    Fragen zur Plattform „Strom für alle“ selbst (nicht zu dieser Energiegemeinschaft)?
    <a href="/kontakt">Kontakt zum Plattformbetreiber</a>
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
