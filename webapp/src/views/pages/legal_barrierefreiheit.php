<?php
$pageTitle = 'Barrierefreiheit — EEG Strompool Feldkirchen Süd-West';
$pageDescription = 'Erklärung zur Barrierefreiheit dieser Website und Kontaktmöglichkeit bei Problemen mit der Zugänglichkeit.';
ob_start();
?>

<div class="legal">
  <h1>Erklärung zur Barrierefreiheit</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · Stand: Oktober 2026</div>

  <p>
    Wir möchten, dass diese Website für möglichst alle Menschen gut nutzbar ist -- unabhängig von
    Sehvermögen, Hörvermögen, motorischen Einschränkungen oder dem verwendeten Gerät. Unser Ziel
    ist eine Umsetzung nach den Web Content Accessibility Guidelines (WCAG) 2.1, Stufe AA.
  </p>

  <h2>Stand der Umsetzung</h2>
  <p>
    Diese Website wurde bisher <strong>nicht durch eine formelle, unabhängige Prüfung</strong>
    (z. B. automatisierter Accessibility-Scan, manueller Test mit Screenreader oder
    ausschließlicher Tastaturbedienung) auf Barrierefreiheit getestet. Einzelne Grundlagen sind
    bereits umgesetzt, etwa:
  </p>
  <ul>
    <li>Alternativtexte für Bilder, die Informationen vermitteln</li>
    <li>semantisch strukturierte Überschriften und native HTML-Formularelemente</li>
    <li>ausreichender Farbkontrast in der Standardansicht sowie ein eigener Dunkelmodus</li>
    <li>responsives Layout für unterschiedliche Bildschirmgrößen</li>
  </ul>
  <p>
    Eine vollständige Konformität mit WCAG 2.1 AA können wir zum jetzigen Zeitpunkt noch nicht
    zusichern. Insbesondere an der <strong>Darstellung auf mobilen Geräten</strong> arbeiten wir
    derzeit noch, da uns hier bekannte Darstellungsprobleme vorliegen.
  </p>

  <h2>Barrieren melden</h2>
  <p>
    Stoßen Sie auf eine Barriere oder ein Problem bei der Nutzung dieser Website -- egal ob mit
    Screenreader, Tastatur, Vergrößerung oder auf dem Smartphone -- freuen wir uns über eine
    Rückmeldung, damit wir das beheben können:
    <a href="/rc108175/kontakt">Kontaktformular</a> oder
    <a href="mailto:office@stromfueralle.at">office@stromfueralle.at</a>.
  </p>

  <p style="margin-top:2rem;font-size:.85rem;color:var(--gray-600)">
    Diese Erklärung wird laufend aktualisiert, sobald wir weitere Verbesserungen umgesetzt oder
    geprüft haben.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
