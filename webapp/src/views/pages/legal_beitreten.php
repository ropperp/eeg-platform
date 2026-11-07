<?php
$pageTitle = 'Beitreten — EEG Strompool Feldkirchen Süd-West';
$pageDescription = 'So werden Sie Mitglied der EEG Strompool Feldkirchen Süd-West und nehmen an der gemeinschaftlichen Energieerzeugung teil.';
ob_start();
?>

<div class="legal">
  <h1>Beitreten</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · ZVR 1778816746</div>

  <p>
    Sie möchten Mitglied der Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West
    werden? Füllen Sie die Beitrittserklärung direkt online aus und unterschreiben Sie sie mit
    der Maus oder dem Finger — der Vorstand erhält danach eine Benachrichtigung und schaltet
    Ihre Mitgliedschaft frei. Über die Aufnahme entscheidet der Vorstand.
  </p>

  <div style="margin:1.5rem 0 2.5rem;display:flex;flex-wrap:wrap;gap:1rem">
    <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/beitreten/formular" class="btn btn-primary">
      Online Beitrittserklärung ausfüllen &amp; unterschreiben
    </a>
    <a href="/infoblatt.pdf" class="btn btn-secondary" target="_blank" rel="noopener">
      Infoblatt ansehen (PDF)
    </a>
    <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/beitrittserklaerung.pdf" class="btn btn-secondary" target="_blank" rel="noopener">
      Beitrittserklärung zum Ausdrucken (PDF)
    </a>
  </div>

  <p class="alert" style="background:var(--green-light);font-size:.9rem">
    <?= icon('info') ?> Mitglieder erhalten eine <strong>Ausleseeinheit für den Smart Meter</strong>.
    Ihr Betrieb ist laut <a href="/<?= htmlspecialchars(strtolower($community['marktpartner_id'])) ?>/agb">AGB (§ 7)</a>
    verpflichtender Teil der Teilnahme; voraussichtlicher Preis ca. € 30,–. Die Ausleseeinheit
    befindet sich derzeit in Entwicklung.
  </p>

  <h2>Bevor Sie unterschreiben</h2>
  <p>Bitte lesen Sie vorab die folgenden Unterlagen:</p>
  <ul>
    <li><a href="/rc108175/statuten">Statuten</a> — Vereinszweck, Rechte und Pflichten der Mitglieder</li>
    <li><a href="/rc108175/datenschutz">Datenschutz</a> — Umgang mit Ihren personenbezogenen Daten</li>
    <li><a href="/rc108175/agb">AGBs</a> — allgemeine Geschäftsbedingungen der Mitgliedschaft</li>
    <li><a href="/rc108175/preisliste">Preisliste</a> — aktuelle Tarife und Mitgliedsbeitrag</li>
    <li><a href="/rc108175/zustimmungen">Zustimmungen</a> — vollständiger Wortlaut der Erklärungen in der Beitrittserklärung</li>
  </ul>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
