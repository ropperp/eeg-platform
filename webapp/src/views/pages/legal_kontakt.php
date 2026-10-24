<?php
$pageTitle = 'Kontakt — EEG Strompool Feldkirchen Süd-West';
$pageDescription = 'Kontaktmöglichkeiten der EEG Strompool Feldkirchen Süd-West für Interessierte und Mitglieder.';
ob_start();
?>

<div class="legal">
  <h1>Kontakt</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <p>
    Sie haben Fragen — zur Mitgliedschaft, zur Abrechnung oder zu Ihren bei uns
    gespeicherten personenbezogenen Daten? Melden Sie sich gerne direkt bei uns.
  </p>

  <h2>Ansprechpartner</h2>
  <p>
    Patrick Ropper (Obmann)<br>
    E-Mail: <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a><br>
    Telefon: <a href="tel:+436506044812">+43 650 6044812</a>
  </p>

  <h2>Vereinssitz</h2>
  <p>
    EEG Strompool Feldkirchen Süd-West<br>
    Eichenweg 2, 9560 St. Nikolai<br>
    Feldkirchen in Kärnten
  </p>

  <h2>Fragen zum Datenschutz</h2>
  <p>
    Für Anfragen zu Auskunft, Berichtigung oder Löschung Ihrer Daten siehe auch unsere
    <a href="/rc108175/datenschutz">Datenschutzerklärung</a> — oder nutzen Sie das Formular
    unten (Anliegen "Datenschutz") bzw. schreiben Sie uns direkt eine E-Mail.
  </p>

  <h2>Kontaktformular</h2>
  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
  <?php endif; ?>
  <form method="post" action="/rc108175/kontakt" style="max-width:480px">
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
