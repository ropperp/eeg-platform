<?php
// Rechtsdokumente der EEG bearbeiten -- siehe Routen /portal/dokumente in index.php.
// Erwartet: $community (oder null), $isPlatformAdmin, $editDocs, $consentCurrent, $consentHistory, $infoblatt.
$pageTitle = 'Dokumente';
$rc = $community ? strtolower($community['marktpartner_id']) : null;
$editorName = fn($r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'unbekannt';
ob_start();
?>

<h2 style="margin-bottom:.5rem"><?= icon('file-text') ?> Dokumente</h2>
<p style="color:var(--gray-600);font-size:.875rem;margin-bottom:1.25rem;max-width:52rem">
  Hier pflegen Sie die Unterlagen<?= $community ? ' der <strong>' . htmlspecialchars($community['name']) . '</strong>' : '' ?>,
  die auf der Website erscheinen. Speichern veröffentlicht sofort; jede Speicherung bleibt als
  Fassung im Verlauf erhalten und lässt sich wiederherstellen. Texte aus Word können Sie direkt
  in den Editor einfügen -- Überschriften, Listen und Fettdruck bleiben, Word-Formatierungen
  werden entfernt.
</p>

<?php if (!empty($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php
$tabsKey = 'dokumente';
$tabs = [];
foreach ($editDocs as $tabId => $d) {
    $label = ['agb' => 'AGB', 'statuten' => 'Statuten', 'datenschutz' => 'Datenschutz', 'widerruf' => 'Widerruf',
              'impressum' => 'Impressum', 'barrierefreiheit' => 'Barrierefreiheit', 'kontakt' => 'Kontakt'][$d['doc_key']];
    $tabs[$tabId] = ['label' => $d['platform'] ? 'Plattform: ' . $label : $label];
}
if ($community) {
    $tabs['zustimmungen'] = ['label' => 'Zustimmungen'];
    $tabs['infoblatt'] = ['label' => 'Infoblatt'];
}
$firstTab = array_key_first($tabs);
require __DIR__ . '/../partials/tabs_nav.php';
?>

<?php foreach ($editDocs as $tabId => $d): ?>
<section class="tab-panel" id="tab-<?= $tabId ?>" role="tabpanel" aria-labelledby="tabbtn-<?= $tabId ?>" tabindex="0"<?= $tabId === $firstTab ? '' : ' hidden' ?>>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem">
      <div>
        <h3 style="margin-bottom:.25rem"><?= htmlspecialchars($d['title']) ?><?= $d['platform'] ? ' <span class="badge badge-blue">gilt für die ganze Plattform</span>' : '' ?></h3>
        <div style="font-size:.85rem;color:var(--gray-600)">
          <?php if (!$d['current']): ?>
            Noch nicht veröffentlicht -- auf der Website steht derzeit „folgt in Kürze“.
          <?php elseif ($d['current']['source'] === 'standard'): ?>
            Derzeit gilt die mitgelieferte Standardfassung<?= $d['current']['stand'] !== '' ? ' (Stand ' . htmlspecialchars($d['current']['stand']) . ')' : '' ?>.
          <?php else: ?>
            Eigene Fassung vom <?= htmlspecialchars(fmtVienna($d['current']['row']['created_at'])) ?>,
            gespeichert von <?= htmlspecialchars(trim(($d['current']['row']['editor_first_name'] ?? '') . ' ' . ($d['current']['row']['editor_last_name'] ?? '')) ?: 'unbekannt') ?>.
          <?php endif; ?>
          <?php if ($d['platform'] && in_array($d['doc_key'], ['datenschutz', 'impressum', 'kontakt'], true)): ?>
            <br>Steht auf <a href="/<?= $d['doc_key'] ?>">/<?= $d['doc_key'] ?></a> als allgemeiner Teil <strong>vor</strong> der Auswahl der Energiegemeinschaften<?= $d['doc_key'] === 'kontakt' ? '; das Kontaktformular (an office@stromfueralle.at) wird automatisch darunter angezeigt' : '' ?>.
          <?php endif; ?>
          <?php if ($d['doc_key'] === 'datenschutz' && !$d['platform']): ?>
            <br>Hier steht nur der <strong>Teil Ihrer Energiegemeinschaft</strong> (Verantwortlicher, Zwecke,
            Netzbetreiber …). Der allgemeine Teil der Plattform wird auf der Website automatisch davor angezeigt.
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= htmlspecialchars($d['public_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Auf der Website ansehen</a>
    </div>

    <form method="post" action="/portal/dokumente/<?= $tabId ?>/save" class="rich-editor-form">
      <div class="rich-editor" data-rich-editor>
        <div class="rich-editor-toolbar" role="toolbar" aria-label="Formatierung">
          <button type="button" data-cmd="formatBlock" data-value="h2" title="Überschrift">Überschrift</button>
          <button type="button" data-cmd="formatBlock" data-value="h3" title="Unterüberschrift">Unterüberschrift</button>
          <button type="button" data-cmd="formatBlock" data-value="p" title="Normaler Absatz">Absatz</button>
          <span class="rich-editor-sep"></span>
          <button type="button" data-cmd="bold" title="Fett"><strong>F</strong></button>
          <button type="button" data-cmd="italic" title="Kursiv"><em>K</em></button>
          <button type="button" data-cmd="insertUnorderedList" title="Aufzählung">• Liste</button>
          <button type="button" data-cmd="insertOrderedList" title="Nummerierte Liste">1. Liste</button>
          <button type="button" data-cmd="createLink" title="Link einfügen">Link</button>
          <button type="button" data-cmd="unlink" title="Link entfernen">Link weg</button>
          <span class="rich-editor-sep"></span>
          <button type="button" data-cmd="undo" title="Rückgängig">↶</button>
          <button type="button" data-cmd="redo" title="Wiederholen">↷</button>
        </div>
        <div class="rich-editor-area legal" contenteditable="true" role="textbox" aria-multiline="true"
             aria-label="<?= htmlspecialchars($d['title']) ?>"><?= $d['current']['html'] ?? '' ?></div>
        <noscript><p style="font-size:.8rem;color:var(--gray-600)">Ohne JavaScript bitte HTML direkt im Feld unten bearbeiten.</p></noscript>
        <textarea name="content_html" class="rich-editor-source" hidden><?= htmlspecialchars($d['current']['html'] ?? '') ?></textarea>
      </div>
      <div style="display:flex;gap:.75rem;align-items:center;margin-top:1rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">Speichern &amp; veröffentlichen</button>
        <span style="font-size:.8rem;color:var(--gray-600)">Erlaubt: Überschriften, Absätze, Listen, Fett/Kursiv, Links, einfache Tabellen.</span>
      </div>
    </form>

    <?php if ($d['history'] || ($d['has_default'] && ($d['current']['source'] ?? '') === 'eigen')): ?>
    <details style="margin-top:1.5rem">
      <summary style="cursor:pointer;font-weight:600">Verlauf &amp; frühere Fassungen</summary>
      <ul style="list-style:none;padding:0;margin-top:.75rem">
        <?php foreach ($d['history'] as $i => $h): ?>
          <li style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.4rem 0;border-bottom:1px solid var(--gray-200);font-size:.85rem">
            <span><?= htmlspecialchars(fmtVienna($h['created_at'])) ?> · <?= htmlspecialchars($editorName($h)) ?><?= $i === 0 ? ' <span class="badge badge-green">aktuell</span>' : '' ?></span>
            <?php if ($i > 0): ?>
            <form method="post" action="/portal/dokumente/<?= $tabId ?>/restore" onsubmit="return confirm('Diese frühere Fassung wieder veröffentlichen?')">
              <input type="hidden" name="version_id" value="<?= htmlspecialchars($h['id']) ?>">
              <button type="submit" class="btn btn-secondary" style="padding:.2rem .6rem;font-size:.78rem">Wiederherstellen</button>
            </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if ($d['has_default'] && ($d['current']['source'] ?? '') === 'eigen'): ?>
          <li style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.4rem 0;font-size:.85rem">
            <span>Mitgelieferte Standardfassung</span>
            <form method="post" action="/portal/dokumente/<?= $tabId ?>/restore" onsubmit="return confirm('Standardfassung wieder veröffentlichen?')">
              <input type="hidden" name="version_id" value="standard">
              <button type="submit" class="btn btn-secondary" style="padding:.2rem .6rem;font-size:.78rem">Wiederherstellen</button>
            </form>
          </li>
        <?php endif; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($community): ?>
<section class="tab-panel" id="tab-zustimmungen" role="tabpanel" aria-labelledby="tabbtn-zustimmungen" tabindex="0"<?= $firstTab === 'zustimmungen' ? '' : ' hidden' ?>>
  <div class="card">
    <h3 style="margin-bottom:.25rem">Zustimmungen der Beitrittserklärung</h3>
    <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:1rem">
      Aktuell gilt <strong><?= htmlspecialchars(consentVersion($consentCurrent)['bezeichnung'] ?? $consentCurrent) ?></strong>
      (Version <?= htmlspecialchars($consentCurrent) ?>, gültig ab <?= consentValidFromDisplay($consentCurrent) ?>).
      Die <strong>Langfassung</strong> steht im Online-Formular und auf dem Papierformular, die
      <strong>Kurzfassung</strong> im Ausdruck der Online-Beitrittserklärung. Beim Speichern entsteht
      eine neue Fassung, die ab sofort für neue Beitritte gilt; bereits unterschriebene
      Beitrittserklärungen behalten ihre Fassung.
      Platzhalter: <code>{eeg_name}</code> = Name der EEG, <code>{rc}</code> = <?= htmlspecialchars($rc) ?>
      (z.&nbsp;B. <code>stromfueralle.at/{rc}/agb</code>). Ein Zeilenumbruch beginnt eine neue Zeile.
      <a href="/<?= htmlspecialchars($rc) ?>/zustimmungen" target="_blank" rel="noopener">Alle Fassungen ansehen</a>
    </p>
    <form method="post" action="/portal/dokumente/zustimmungen/save">
      <?php $currentTexts = consentVersion($consentCurrent)['texte'] ?? []; ?>
      <?php foreach (consentFields() as $field => $title): ?>
        <fieldset style="border:1px solid var(--gray-200);border-radius:var(--radius);padding:1rem;margin-bottom:1rem">
          <legend style="font-weight:600;padding:0 .4rem"><?= htmlspecialchars($title) ?></legend>
          <div class="form-group">
            <label for="<?= $field ?>_kurz">Kurzfassung</label>
            <textarea id="<?= $field ?>_kurz" name="<?= $field ?>_kurz" rows="2" required maxlength="5000"><?= htmlspecialchars($currentTexts[$field]['kurz'] ?? '') ?></textarea>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label for="<?= $field ?>_lang">Langfassung</label>
            <textarea id="<?= $field ?>_lang" name="<?= $field ?>_lang" rows="6" required maxlength="5000"><?= htmlspecialchars($currentTexts[$field]['lang'] ?? '') ?></textarea>
          </div>
        </fieldset>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary" onclick="return confirm('Als neue Fassung speichern? Sie gilt ab sofort für neue Beitrittserklärungen.')">Als neue Fassung speichern</button>
    </form>
    <?php if ($consentHistory): ?>
      <details style="margin-top:1.5rem">
        <summary style="cursor:pointer;font-weight:600">Eigene Fassungen</summary>
        <ul style="margin-top:.5rem;font-size:.85rem">
          <?php foreach ($consentHistory as $h): ?>
            <li><?= htmlspecialchars($h['version_key']) ?> · gültig ab <?= date('d.m.Y', strtotime($h['gueltig_ab'])) ?> · <?= htmlspecialchars($editorName($h)) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="tab-infoblatt" role="tabpanel" aria-labelledby="tabbtn-infoblatt" tabindex="0" hidden>
  <div class="card">
    <h3 style="margin-bottom:.25rem">Infoblatt (PDF)</h3>
    <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:1rem">
      Erscheint auf der Beitrittsseite Ihrer Energiegemeinschaft unter „Infoblatt ansehen“.
      <?php if ($infoblatt['own']): ?>
        Hochgeladen am <?= date('d.m.Y H:i', $infoblatt['mtime']) ?> (<?= round($infoblatt['size'] / 1024) ?> kB).
      <?php else: ?>
        Noch kein eigenes Infoblatt hochgeladen.
      <?php endif; ?>
      <a href="/<?= htmlspecialchars($rc) ?>/infoblatt.pdf" target="_blank" rel="noopener">Aktuelles Infoblatt ansehen</a>
    </p>
    <form method="post" action="/portal/dokumente/infoblatt" enctype="multipart/form-data" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
      <input type="file" name="infoblatt" accept="application/pdf,.pdf" required>
      <button type="submit" class="btn btn-primary">Hochladen &amp; veröffentlichen</button>
    </form>
    <?php if ($infoblatt['own']): ?>
      <form method="post" action="/portal/dokumente/infoblatt" style="margin-top:1rem" onsubmit="return confirm('Eigenes Infoblatt löschen?')">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn" style="background:none;color:#b91c1c;padding:0;text-decoration:underline;font-size:.85rem">Eigenes Infoblatt löschen</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<script src="/assets/js/rich-editor.js?v=<?= @filemtime(ROOT . '/public/assets/js/rich-editor.js') ?: time() ?>"></script>
<script src="/assets/js/tabs.js?v=<?= @filemtime(ROOT . '/public/assets/js/tabs.js') ?: time() ?>"></script>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
