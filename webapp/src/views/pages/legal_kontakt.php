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
  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
  <?php endif; ?>
  <form method="post" action="/<?= htmlspecialchars($rc) ?>/kontakt" style="max-width:480px">
    <!-- Honeypot gegen Spam-Bots: für Menschen unsichtbar, ein Bot füllt oft jedes Feld aus. -->
    <div style="position:absolute;left:-9999px" aria-hidden="true">
      <label for="website">Bitte freilassen</label>
      <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>
    <div class="form-group">
      <label for="kontakt-name">Name</label>
      <input type="text" id="kontakt-name" name="name" required>
    </div>
    <div class="form-group">
      <label for="kontakt-email">E-Mail</label>
      <input type="email" id="kontakt-email" name="email" required>
    </div>
    <div class="form-group">
      <label for="kontakt-anliegen">Anliegen</label>
      <select id="kontakt-anliegen" name="anliegen">
        <option value="allgemein">Allgemeine Frage</option>
        <option value="mitgliedschaft">Mitgliedschaft</option>
        <option value="rechnung">Rechnung &amp; Zahlung</option>
        <option value="datenschutz">Datenschutz (Auskunft/Berichtigung/Löschung)</option>
        <option value="sonstiges">Sonstiges</option>
      </select>
    </div>
    <div class="form-group">
      <label for="kontakt-nachricht">Nachricht</label>
      <textarea id="kontakt-nachricht" name="nachricht" rows="5" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Nachricht senden</button>
  </form>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
