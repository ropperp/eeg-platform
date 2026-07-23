<?php
$pageTitle = 'Preisliste — EEG Strompool Feldkirchen Süd-West';
// $tariffHistory kommt aus der Route (/rc108175/preisliste), neueste zuerst.
$history = $tariffHistory ?? [];
$current = $history[0] ?? null;
$fmt = fn($v) => number_format((float)$v, 2, ',', '.');
ob_start();
?>

<div class="legal">
  <h1>Preisliste</h1>
  <div class="legal-meta">
    EEG Strompool Feldkirchen Süd-West
    <?php if ($current): ?>· gültig ab <?= date('d.m.Y', strtotime($current['valid_from'])) ?><?php endif; ?>
  </div>

  <?php if ($current): ?>
  <table>
    <thead>
      <tr><th>Leistung</th><th>Tarif</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Bezug von Energie aus der Gemeinschaft</td>
        <td class="price"><?= $fmt($current['bezug_ct_kwh']) ?> ct/kWh</td>
      </tr>
      <tr>
        <td>Einspeisevergütung an einspeisende Mitglieder</td>
        <td class="price"><?= $fmt($current['einspeisung_ct_kwh']) ?> ct/kWh</td>
      </tr>
      <tr>
        <td>Mitgliedsbeitrag</td>
        <td class="price"><?= $fmt((float)$current['mitgliedsbeitrag_eur'] / 12) ?> € / Monat</td>
      </tr>
      <tr>
        <td>Ausleseeinheit für Smart Meter (P1-Schnittstelle)</td>
        <td class="price">in Vorbereitung – voraussichtlich ca. 20,00 €</td>
      </tr>
    </tbody>
  </table>

  <p>
    Der Mitgliedsbeitrag wird gemeinsam mit der Energieabrechnung in Rechnung gestellt
    (<?= $fmt($current['mitgliedsbeitrag_eur']) ?> € pro Kalenderjahr).
  </p>
  <?php else: ?>
  <p>Für diese Energiegemeinschaft ist derzeit kein Tarif hinterlegt.</p>
  <?php endif; ?>

  <?php if (count($history) > 0): ?>
  <h2 style="margin-top:2rem">Preishistorie</h2>
  <p style="font-size:.9rem">
    Nachvollziehbare Übersicht aller Tarifstände seit Beginn – die oberste Zeile ist der aktuell
    gültige Tarif. So ist jederzeit belegbar, welcher Preis ab welchem Datum galt.
  </p>
  <table>
    <thead>
      <tr>
        <th>Gültig ab</th>
        <th>Bezug</th>
        <th>Einspeisevergütung</th>
        <th>Mitgliedsbeitrag / Jahr</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($history as $i => $t): ?>
      <tr<?= $i === 0 ? ' style="font-weight:600"' : '' ?>>
        <td><?= date('d.m.Y', strtotime($t['valid_from'])) ?><?= $i === 0 ? ' <span class="badge badge-green">aktuell</span>' : '' ?></td>
        <td class="price"><?= $fmt($t['bezug_ct_kwh']) ?> ct/kWh</td>
        <td class="price"><?= $fmt($t['einspeisung_ct_kwh']) ?> ct/kWh</td>
        <td class="price"><?= $fmt($t['mitgliedsbeitrag_eur']) ?> €</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <p>
    Der endgültige Preis der Ausleseeinheit wird dem Mitglied vor Übergabe bekannt gegeben
    (siehe <a href="/rc108175/agb">AGB</a>).
  </p>

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
