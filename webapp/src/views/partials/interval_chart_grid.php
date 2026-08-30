<?php
/**
 * Dezentes Hintergrund-Gitter für die Viertelstunden-Diagramme (my_verbrauch.php,
 * my_einspeisung.php) -- Patrick, 07.09.2026: "beim Diagramm [...] wäre noch interessant, wenn
 * man im Hintergrund so ein graues Gitter einzeichnen würde, mit ein bisschen genauerer
 * Zeitunterteilung, vielleicht im Stunden- oder im 2-Stunden-Takt. Das Gleiche auf der
 * Leistungshöhe [...] in 10 Teilungen". Senkrechte Linien alle 2 Stunden (12 Linien), waagrechte
 * Linien in 10 gleich große Abschnitte der Leistungsachse (11 Linien inkl. 0/Maximum). Muss NACH
 * der Geometrie-Berechnung (x()/yFromW()/$n/$maxW), aber VOR den Flächen/Linien des eigentlichen
 * Diagramms eingebunden werden (Hintergrund).
 *
 * Beschriftung an JEDER Linie (Nachbesserung, Patrick: "Schreib auch bei jedem Gitterstreifen
 * die Uhrzeit unten der x-Achse und bei der y-Achse auch bei jedem Streifen die Leistung in
 * Watt, damit man das besser ablesen kann und nicht ausrechnen müsste") -- ersetzt die bisherige
 * grobe Beschriftung (nur 5 feste Uhrzeiten bzw. nur 0/Maximum), die jeweils direkt in
 * my_verbrauch.php/my_einspeisung.php gezeichnet wurde. Einzige Beschriftungsquelle jetzt hier,
 * damit Gitterlinie und Zahl garantiert zusammenpassen.
 *
 * Erwartet im Scope des einbindenden Views: $x (Closure Intervall-Index -> SVG-X), $yFromW
 * (Closure Watt -> SVG-Y), $n (Intervall-Anzahl), $maxW (oberes Ende der Leistungsachse), $H
 * (SVG-Höhe), $padL (linker Rand).
 */
$gridY0 = $yFromW(0);
?>
<g class="chart-grid">
  <?php for ($h = 0; $h < 24; $h += 2): $gi = (int)round($h * 4); $gi = min($gi, $n - 1); $gx = $x($gi); ?>
    <line x1="<?= $gx ?>" y1="10" x2="<?= $gx ?>" y2="<?= $gridY0 ?>" stroke="var(--gray-200)" stroke-width="1" />
    <text x="<?= $gx ?>" y="<?= $H - 6 ?>" font-size="10" fill="var(--gray-600)" text-anchor="middle"><?= sprintf('%02d:00', $h) ?></text>
  <?php endfor; ?>
  <?php for ($k = 0; $k <= 10; $k++): $gy = $yFromW($maxW * $k / 10); ?>
    <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $x($n - 1) ?>" y2="<?= $gy ?>" stroke="var(--gray-200)" stroke-width="1" />
    <text x="4" y="<?= $gy + 3 ?>" font-size="10" fill="var(--gray-600)"><?= number_format($maxW * $k / 10, 0, ',', '.') ?> W</text>
  <?php endfor; ?>
</g>
