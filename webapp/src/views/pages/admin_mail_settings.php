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
  <form method="post" action="/admin/mail-settings" enctype="multipart/form-data">
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
        <textarea name="signature_html" id="signature-html-input" rows="4" oninput="updateMailPreview()" placeholder="<p>Bei Fragen zu Rechnungen, Verträgen oder Vereinbarungen antworten Sie einfach auf diese E-Mail oder schreiben Sie an office@stromfueralle.at.<br>EEG Strompool Feldkirchen Süd-West</p>"><?= htmlspecialchars($mailConfig['signature_html'] ?? '') ?></textarea>
        <small style="color:var(--gray-600)">Einfaches HTML möglich (z.B. <code>&lt;br&gt;</code>, <code>&lt;strong&gt;</code>). Gilt für
          <strong>alle</strong> E-Mails (Einladung, Passwort-Reset, Vertrags-/Rechnungsversand, Test-Mail) -- eine gemeinsame Signatur statt
          sie in jede einzelne Vorlage einzeln hineinzuschreiben, damit eine spätere Änderung (z.B. neue Telefonnummer) nur an einer
          Stelle gepflegt werden muss. Am einfachsten die gleiche Adresse wie bei "Antwort-an" nennen -- eine zusätzliche, dritte
          Kontaktadresse würde nur verwirren. <strong>Logo einfügen:</strong> schreibe <code>{{logo}}</code> genau an die Stelle,
          an der das Bild erscheinen soll – z.&nbsp;B. <code>Mit freundlichen Grüßen,&lt;br&gt;Ihr Team Stromfueralle&lt;br&gt;{{logo}}&lt;br&gt;&lt;kleines Impressum&gt;</code>.</small>
      </div>
      <div class="form-group" style="grid-column:1 / -1">
        <label>Signatur-Logo / Bild (optional)</label>
        <?php if (!empty($mailConfig['signature_logo_base64'])): ?>
          <div style="margin:.25rem 0 .5rem;padding:.5rem;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:6px;display:inline-block">
            <img src="data:<?= htmlspecialchars($mailConfig['signature_logo_type'] ?: 'image/png') ?>;base64,<?= htmlspecialchars($mailConfig['signature_logo_base64']) ?>"
                 alt="Signatur-Logo" style="max-height:64px;display:block">
          </div>
          <label style="display:block;font-weight:normal;font-size:.85rem;margin:.25rem 0">
            <input type="checkbox" name="signature_logo_remove" value="1"> Logo entfernen
          </label>
        <?php endif; ?>
        <input type="file" name="signature_logo" id="signature-logo-input" accept="image/png,image/jpeg,image/gif" onchange="onLogoPicked(event)">
        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.5rem">
          <label style="font-size:.85rem;font-weight:normal">Breite (px)
            <input type="number" name="signature_logo_width" id="signature-logo-width" min="0" max="1000" step="1"
                   value="<?= htmlspecialchars((string)($mailConfig['signature_logo_width'] ?? '')) ?>"
                   placeholder="auto" oninput="updateMailPreview()" style="width:100px;display:block">
          </label>
          <label style="font-size:.85rem;font-weight:normal">Höhe (px)
            <input type="number" name="signature_logo_height" id="signature-logo-height" min="0" max="1000" step="1"
                   value="<?= htmlspecialchars((string)($mailConfig['signature_logo_height'] ?? '')) ?>"
                   placeholder="auto" oninput="updateMailPreview()" style="width:100px;display:block">
          </label>
        </div>
        <small style="color:var(--gray-600)">Wird als Inline-Bild eingebettet (auch bei No-Reply-Absendern, zuverlässig in Outlook/Gmail).
          PNG/JPG/GIF, max. 2 MB. <strong>Größe:</strong> nur Breite ODER nur Höhe angeben → skaliert proportional; beide → exakt Breite×Höhe;
          beide leer → Standard (max. 64&nbsp;px hoch). <strong>Position:</strong> schreibe <code>{{logo}}</code> an die gewünschte Stelle im
          Signaturfeld oben (z.&nbsp;B. zwischen Grußformel und Impressum) — ohne Platzhalter kommt das Logo ans Ende.
          Leeres Datei-Feld = bestehendes Logo behalten.</small>
      </div>
      <div class="form-group">
        <label>Backup-Alarm an (Adresse 1)</label>
        <input type="email" name="backup_alert_email_1" value="<?= htmlspecialchars($mailConfig['backup_alert_email_1'] ?? '') ?>" placeholder="office@stromfueralle.at">
        <small style="color:var(--gray-600)">Schlägt das nächtliche Backup fehl, geht eine Warn-Mail an diese Adresse(n). Leer = an den ersten Platform-Admin.</small>
      </div>
      <div class="form-group">
        <label>Backup-Alarm an (Adresse 2)</label>
        <input type="email" name="backup_alert_email_2" value="<?= htmlspecialchars($mailConfig['backup_alert_email_2'] ?? '') ?>" placeholder="patrick.ropper@gmail.com">
        <small style="color:var(--gray-600)">Optionale zweite Empfängeradresse für den Backup-Alarm.</small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</div>

<?php
  $logoDataUri = !empty($mailConfig['signature_logo_base64'])
    ? 'data:' . ($mailConfig['signature_logo_type'] ?: 'image/png') . ';base64,' . $mailConfig['signature_logo_base64']
    : '';
  // Labels der Vorlagen (wird weiter unten im Vorlagen-Block wiederverwendet).
  $templateLabel = [
    'password_reset'       => 'Passwort zurücksetzen',
    'invite'                => 'Erstlogin-Einladung',
    'member_deactivated'    => 'Mitglied deaktiviert ("Wirklich löschen")',
    'contract_bezug'        => 'Vertrag: nur Bezugsvereinbarung',
    'contract_einspeisung'  => 'Vertrag: nur Einspeisevereinbarung',
    'contract_both'         => 'Vertrag: Bezug + Einspeisung gemeinsam',
    'sepa_prenotification'  => 'SEPA-Vorabinformation (Pre-Notification)',
    'mahnung'               => 'Zahlungserinnerung / Mahnung',
  ];
  // Vorlagen für die Vorschau (Betreff + Body je key).
  $previewTemplates = [];
  foreach ($mailTemplates as $t) {
    $previewTemplates[$t['key']] = [
      'subject' => $t['subject'],
      'body'    => $t['body_html'],
      'label'   => $templateLabel[$t['key']] ?? $t['key'],
    ];
  }
?>
<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.25rem">👀 Vorschau der E-Mail</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:.75rem">
    So sieht eine ausgehende E-Mail aus – live, während du oben tippst. Wähle eine Vorlage, um zu sehen, wie
    Rechnungs-Mail, Passwort-Reset &amp; Co. mit deiner Signatur und dem Logo wirken. Die Platzhalter
    (<code>{{vorname}}</code> usw.) sind mit Beispiel-Werten eines Test-Nutzers gefüllt.
  </p>
  <div style="margin-bottom:1rem">
    <label style="font-size:.85rem;font-weight:600;margin-right:.5rem">Vorlage:</label>
    <select id="preview-template-select" onchange="updateMailPreview()" style="padding:.35rem .6rem;border:1px solid var(--gray-200);border-radius:6px">
      <option value="">— Beispiel-Text (nur Signatur testen) —</option>
      <?php foreach ($previewTemplates as $key => $tpl): ?>
        <option value="<?= htmlspecialchars($key) ?>"<?= $key === 'sepa_prenotification' ? ' selected' : '' ?>><?= htmlspecialchars($tpl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start">
    <div>
      <div style="font-size:.8rem;color:var(--gray-600);margin-bottom:.4rem;font-weight:600">📱 Smartphone (375&nbsp;px)</div>
      <div style="width:375px;max-width:100%;border:1px solid #d1d5db;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)">
        <div style="background:#f3f4f6;border-bottom:1px solid #e5e7eb;padding:10px 14px;font-size:12px;color:#374151">
          <div><strong>Von:</strong> EEG Strompool &lt;noreply@stromfueralle.at&gt;</div>
          <div><strong>Betreff:</strong> <span class="mail-preview-subject"></span></div>
        </div>
        <div class="mail-preview-body" style="padding:16px;background:#fff"></div>
      </div>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:.8rem;color:var(--gray-600);margin-bottom:.4rem;font-weight:600">💻 Laptop (≈820&nbsp;px)</div>
      <div style="overflow-x:auto;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08)">
        <div style="width:820px">
          <div style="background:#f3f4f6;border-bottom:1px solid #e5e7eb;padding:12px 24px;font-size:13px;color:#374151">
            <div><strong>Von:</strong> EEG Strompool Feldkirchen Süd-West &lt;noreply@stromfueralle.at&gt;</div>
            <div><strong>Betreff:</strong> <span class="mail-preview-subject"></span></div>
          </div>
          <div class="mail-preview-body" style="padding:24px;background:#fff"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const mailPreviewInitialLogo = <?= json_encode($logoDataUri) ?>;
  let mailPreviewLogo = mailPreviewInitialLogo;
  const mailPreviewTemplates = <?= json_encode($previewTemplates, JSON_UNESCAPED_UNICODE) ?>;

  // Test-Nutzer: deckt alle Platzhalter ab, die in den Vorlagen vorkommen können.
  const mailPreviewTestUser = {
    vorname: 'Max',
    anrede: 'Sehr geehrter Herr',
    nachname: 'Mustermann',
    eeg_name: 'EEG Strompool Feldkirchen Süd-West',
    link: 'https://portal.stromfueralle.at/portal/login',
    gueltigkeit: '24 Stunden',
    hinweis: '<p style="color:#b45309"><em>Hinweis: Eine zuvor gesendete Fassung wird damit ungültig.</em></p>',
    rechnungsnummer: 'RC108175-2026-Q1-001',
    betrag: '68,55',
    abbuchung: '06.08.2026',
    mandatsreferenz: 'S00001F2026A100',
    creditor_id: 'AT14EEG00000086499',
    mahnstufe_text: 'Zahlungserinnerung',
    gesamt: '73,55',
    gebuehr_zeile: '<br>Mahngebühren: 5,00 €',
    ruecklast_hinweis: ' (die SEPA-Lastschrift wurde von Ihrer Bank zurückgebucht)',
    frist: '20.08.2026',
    iban: 'AT31 2070 2000 0002 5460'
  };

  function mailPreviewFillVars(str) {
    // {{logo}} bewusst NICHT ersetzen -- wird separat als Bild behandelt.
    return String(str).replace(/\{\{(\w+)\}\}/g, (m, k) =>
      (k === 'logo') ? m : (k in mailPreviewTestUser ? mailPreviewTestUser[k] : m));
  }
  function mailPreviewLogoTag() {
    if (!mailPreviewLogo) return '';
    const w = parseInt((document.getElementById('signature-logo-width') || {}).value, 10);
    const h = parseInt((document.getElementById('signature-logo-height') || {}).value, 10);
    const parts = [];
    if (w > 0) parts.push('width:' + w + 'px');
    if (h > 0) parts.push('height:' + h + 'px');
    const style = parts.length ? parts.join(';') : 'max-height:64px';
    return '<img src="' + mailPreviewLogo + '" alt="" style="' + style + '">';
  }
  function buildMailPreview() {
    const sel = document.getElementById('preview-template-select').value;
    let subject, bodyHtml;
    if (sel && mailPreviewTemplates[sel]) {
      subject  = mailPreviewFillVars(mailPreviewTemplates[sel].subject);
      bodyHtml = mailPreviewFillVars(mailPreviewTemplates[sel].body);
    } else {
      subject  = 'Beispiel-E-Mail';
      bodyHtml = '<p>Hallo ' + mailPreviewTestUser.vorname + ',</p>'
        + '<p>dies ist ein Beispieltext, damit du siehst, wie deine Signatur und das Logo darunter wirken.</p>';
    }
    const sig = document.getElementById('signature-html-input').value || '';
    // Genau wie im echten Versand (Mailer::send): Signatur an den Body, dann {{logo}} ersetzen
    // bzw. -- falls kein Platzhalter vorhanden -- das Logo ans Ende hängen.
    let full = bodyHtml + (sig ? '<br><br>' + mailPreviewFillVars(sig) : '');
    const img = mailPreviewLogoTag();
    if (full.indexOf('{{logo}}') !== -1) {
      full = full.split('{{logo}}').join(img);
    } else if (img) {
      full += '<br>' + img;
    }
    return { subject, html: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#111827">' + full + '</div>' };
  }
  function updateMailPreview() {
    const p = buildMailPreview();
    document.querySelectorAll('.mail-preview-body').forEach(el => { el.innerHTML = p.html; });
    document.querySelectorAll('.mail-preview-subject').forEach(el => { el.textContent = p.subject; });
  }
  function onLogoPicked(e) {
    const f = e.target.files && e.target.files[0];
    if (!f) { mailPreviewLogo = mailPreviewInitialLogo; updateMailPreview(); return; }
    const r = new FileReader();
    r.onload = ev => { mailPreviewLogo = ev.target.result; updateMailPreview(); };
    r.readAsDataURL(f);
  }
  // "Logo entfernen"-Checkbox berücksichtigen, falls vorhanden.
  document.addEventListener('change', e => {
    if (e.target && e.target.name === 'signature_logo_remove') {
      mailPreviewLogo = e.target.checked ? '' : mailPreviewInitialLogo;
      updateMailPreview();
    }
  });
  updateMailPreview();
</script>

<div class="card" style="margin-bottom:1.5rem">
  <h3 style="margin-bottom:.5rem">E-Mail-Vorlagen</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Verfügbare Platzhalter je nach Vorlage: <code>{{anrede}}</code> (= „Sehr geehrter Herr" / „Sehr geehrte Frau" /
    „Sehr geehrte Familie", je nach Einstellung am Mitglied), <code>{{nachname}}</code>, <code>{{vorname}}</code>,
    <code>{{link}}</code>, <code>{{gueltigkeit}}</code>,
    <code>{{eeg_name}}</code>, <code>{{hinweis}}</code> (werden beim Versand automatisch ersetzt;
    <code>{{hinweis}}</code> wird bei Vertrags-Mails automatisch mit dem Ungültigkeits-Hinweis befüllt,
    falls eine zuvor gesendete Fassung durch eine korrigierte ersetzt wird, sonst bleibt es leer).
    Der Body darf einfaches HTML enthalten (z. B. <code>&lt;p&gt;</code>).
    Zusätzlich in der SEPA-Vorabinformation: <code>{{rechnungsnummer}}</code>, <code>{{betrag}}</code>,
    <code>{{abbuchung}}</code> (Abbuchungsdatum), <code>{{mandatsreferenz}}</code>, <code>{{creditor_id}}</code>.
    In der Mahnung zusätzlich: <code>{{mahnstufe_text}}</code>, <code>{{gesamt}}</code>, <code>{{gebuehr_zeile}}</code>,
    <code>{{ruecklast_hinweis}}</code>, <code>{{frist}}</code>, <code>{{iban}}</code>.
  </p>
  <?php $templateLabel = [
    'password_reset'       => 'Passwort zurücksetzen',
    'invite'                => 'Erstlogin-Einladung',
    'member_deactivated'    => 'Mitglied deaktiviert ("Wirklich löschen")',
    'contract_bezug'        => 'Vertrag: nur Bezugsvereinbarung',
    'contract_einspeisung'  => 'Vertrag: nur Einspeisevereinbarung',
    'contract_both'         => 'Vertrag: Bezug + Einspeisung gemeinsam',
    'sepa_prenotification'  => 'SEPA-Vorabinformation (Pre-Notification)',
    'mahnung'               => 'Zahlungserinnerung / Mahnung',
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
