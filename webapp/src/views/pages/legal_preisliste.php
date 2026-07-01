<?php
$pageTitle = 'Preisliste — EEG Strompool Feldkirchen Süd-West';
ob_start();
?>

<div class="legal">
  <h1>Preisliste</h1>
  <div class="legal-meta">EEG Strompool Feldkirchen Süd-West · gültig ab Juli 2026</div>

  <table>
    <thead>
      <tr><th>Leistung</th><th>Tarif</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Bezug von Energie aus der Gemeinschaft</td>
        <td class="price">12,00 ct/kWh</td>
      </tr>
      <tr>
        <td>Einspeisevergütung an einspeisende Mitglieder</td>
        <td class="price">8,00 ct/kWh</td>
      </tr>
    </tbody>
  </table>

  <p>
    Die Abrechnung erfolgt quartalsweise auf Basis der vom Netzbetreiber übermittelten
    Zählpunktdaten. Gemäß § 6 Abs. 1 Z 27 UStG 1994 (Kleinunternehmerregelung) wird keine
    Umsatzsteuer in Rechnung gestellt.
  </p>

  <p>
    Aktuelle Details entnehmen Sie den <a href="/rc108175/agb">AGB</a> sowie den
    <a href="/rc108175/statuten">Statuten</a>.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
