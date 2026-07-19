<?php $pageTitle = 'E-Mail-Einstellungen'; ob_start(); ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
  <a href="/admin" style="color:var(--gray-600);text-decoration:none">← Admin</a>
  <h2 style="margin:0">✉️ E-Mail-Einstellungen (Microsoft Graph)</h2>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Einstellungen gespeichert.</div>
<?php endif; ?>
<?php if (!empty($testSuccess)): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= $testSuccess ?></div>
<?php endif; ?>
<?php if (!empty($testError)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">Test-Mail fehlgeschlagen: <code style="font-size:.8rem"><?= htmlspecialchars($testError) ?></code></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">🧪 Testmodus / Echtbetrieb</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Betrifft nur die Vergabe von Kundennummern. Im <strong>Testmodus</strong> füllt eine neu
    angelegte Kundennummer Lücken von gelöschten/deaktivierten Mitgliedern wieder auf (praktisch
    zum Testen). Im <strong>Echtbetrieb</strong> wird eine einmal vergebene Kundennummer nie
    wieder verwendet -- es wird immer die nächsthöhere freie Nummer vergeben.
  </p>
  <form method="post" action="/admin/settings/test-mode">
    <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;font-weight:600">
      <input type="checkbox" name="test_mode" value="1" style="width:auto"
             <?= !empty($platformSettings['test_mode']) ? 'checked' : '' ?>>
      Testmodus aktiv
    </label>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">Azure-App-Registrierung</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Benötigt eine Azure-AD-App-Registrierung mit Anwendungsberechtigung <code>Mail.Send</code> (Admin-Zustimmung erteilt)
    für die Absenderadresse. Diese Werte werden nur in der Datenbank gespeichert, nie im Repo.
  </p>
  <form method="post" action="/admin/mail-settings">
    <div class="grid-2">
      <div class="form-group">
        <label>Tenant-ID</label>
        <input type="text" name="tenant_id" value="<?= htmlspecialchars($mailConfig['tenant_id'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
      </div>
      <div class="form-group">
        <label>Client-ID</label>
        <input type="text" name="client_id" value="<?= htmlspecialchars($mailConfig['client_id'] ?? '') ?>" placeholder="00000000-0000-0000-0000-000000000000">
      </div>
      <div class="form-group">
        <label>Client-Secret</label>
        <input type="password" name="client_secret" placeholder="<?= !empty($mailConfig['client_secret']) ? '•••••••• (gespeichert, leer lassen zum Beibehalten)' : 'Client-Secret eingeben' ?>" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Absenderadresse</label>
        <input type="email" name="sender_address" value="<?= htmlspecialchars($mailConfig['sender_address'] ?? '') ?>" placeholder="noreply@stromfueralle.at">
        <small style="color:var(--gray-600)">Muss ein echtes Postfach im selben Tenant sein (Graph sendet "im Namen von").</small>
      </div>
      <div class="form-group">
        <label>Antwort-an-Adresse (Reply-To)</label>
        <input type="email" name="reply_to" value="<?= htmlspecialchars($mailConfig['reply_to'] ?? '') ?>" placeholder="office@stromfueralle.at">
        <small style="color:var(--gray-600)">Optional. Sinnvoll, wenn die Absenderadresse ein unüberwachtes Postfach ist (z.B. noreply@...) --
          Antworten der Kunden landen dann trotzdem in einem tatsächlich gelesenen Postfach. Leer lassen, wenn Antworten
          an die Absenderadresse selbst gehen sollen.</small>
      </div>
      <div class="form-group" style="grid-column:1 / -1">
        <label>Signatur (an jede E-Mail angehängt)</label>
        <textarea name="signature_html" rows="4" placeholder="<p>Bei Fragen zu Rechnungen, Verträgen oder Vereinbarungen antworten Sie einfach auf diese E-Mail oder schreiben Sie an office@stromfueralle.at.<br>EEG Strompool Feldkirchen Süd-West</p>"><?= htmlspecialchars($mailConfig['signature_html'] ?? '') ?></textarea>
        <small style="color:var(--gray-600)">Einfaches HTML möglich (z.B. <code>&lt;br&gt;</code>, <code>&lt;strong&gt;</code>). Gilt für
          <strong>alle</strong> E-Mails (Einladung, Passwort-Reset, Vertrags-/Rechnungsversand, Test-Mail) -- eine gemeinsame Signatur statt
          sie in jede einzelne Vorlage einzeln hineinzuschreiben, damit eine spätere Änderung (z.B. neue Telefonnummer) nur an einer
          Stelle gepflegt werden muss. Am einfachsten die gleiche Adresse wie bei "Antwort-an" nennen -- eine zusätzliche, dritte
          Kontaktadresse würde nur verwirren.</small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">E-Mail-Vorlagen</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Verfügbare Platzhalter je nach Vorlage: <code>{{vorname}}</code>, <code>{{link}}</code>, <code>{{gueltigkeit}}</code>,
    <code>{{eeg_name}}</code>, <code>{{hinweis}}</code> (werden beim Versand automatisch ersetzt;
    <code>{{hinweis}}</code> wird bei Vertrags-Mails automatisch mit dem Ungültigkeits-Hinweis befüllt,
    falls eine zuvor gesendete Fassung durch eine korrigierte ersetzt wird, sonst bleibt es leer).
    Der Body darf einfaches HTML enthalten (z. B. <code>&lt;p&gt;</code>).
  </p>
  <?php $templateLabel = [
    'password_reset'       => 'Passwort zurücksetzen',
    'invite'                => 'Erstlogin-Einladung',
    'member_deactivated'    => 'Mitglied deaktiviert ("Wirklich löschen")',
    'contract_bezug'        => 'Vertrag: nur Bezugsvereinbarung',
    'contract_einspeisung'  => 'Vertrag: nur Einspeisevereinbarung',
    'contract_both'         => 'Vertrag: Bezug + Einspeisung gemeinsam',
  ]; ?>
  <?php foreach ($mailTemplates as $t): ?>
    <form method="post" action="/admin/mail-templates" style="margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid #e5e7eb">
      <input type="hidden" name="key" value="<?= htmlspecialchars($t['key']) ?>">
      <h4 style="margin-bottom:.5rem;font-size:.95rem"><?= htmlspecialchars($templateLabel[$t['key']] ?? $t['key']) ?></h4>
      <div class="form-group">
        <label>Betreff</label>
        <input type="text" name="subject" value="<?= htmlspecialchars($t['subject']) ?>" required>
      </div>
      <div class="form-group">
        <label>Text (HTML)</label>
        <textarea name="body_html" rows="5" style="width:100%;font-family:monospace;font-size:.85rem" required><?= htmlspecialchars($t['body_html']) ?></textarea>
      </div>
      <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700)">Vorlage speichern</button>
    </form>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3 style="margin-bottom:1rem">Test-E-Mail senden</h3>
  <form method="post" action="/admin/mail-settings/test" style="display:flex;gap:.5rem;align-items:flex-end">
    <div class="form-group" style="margin-bottom:0;flex:1">
      <label>Ziel-Adresse</label>
      <input type="email" name="test_to" required placeholder="test@example.at">
    </div>
    <button type="submit" class="btn" style="background:var(--gray-100);color:var(--gray-700);height:38px">Test-Mail senden</button>
  </form>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
