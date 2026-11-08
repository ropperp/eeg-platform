<?php
// Kontaktformular (Plattform unter /kontakt bzw. EEG unter /<rc>/kontakt).
// Erwartet: $contactAction (POST-Ziel), $contactTopics (Schlüssel => Anliegen, siehe contactFormTopics()).
?>
<div id="kontaktformular">
  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= htmlspecialchars($contactAction) ?>" style="max-width:480px">
    <!-- Honeypot gegen Spam-Bots: für Menschen unsichtbar, ein Bot füllt oft jedes Feld aus. -->
    <div style="position:absolute;left:-9999px" aria-hidden="true">
      <label for="website">Bitte freilassen</label>
      <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>
    <div class="form-group">
      <label for="kontakt-name">Name</label>
      <input type="text" id="kontakt-name" name="name" required autocomplete="name">
    </div>
    <div class="form-group">
      <label for="kontakt-email">E-Mail</label>
      <input type="email" id="kontakt-email" name="email" required autocomplete="email">
    </div>
    <div class="form-group">
      <label for="kontakt-anliegen">Anliegen</label>
      <select id="kontakt-anliegen" name="anliegen">
        <?php foreach ($contactTopics as $key => $label): ?>
          <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="kontakt-nachricht">Nachricht</label>
      <textarea id="kontakt-nachricht" name="nachricht" rows="5" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Nachricht senden</button>
  </form>
</div>
