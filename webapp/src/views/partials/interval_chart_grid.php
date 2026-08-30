<?php
/**
 * Dezentes Hintergrund-Gitter für die Viertelstunden-Diagramme (my_verbrauch.php,
 * my_einspeisung.php) -- Patrick, 07.09.2026: "beim Diagramm [...] wäre noch interessant, wenn
 * man im Hintergrund so ein graues Gitter einzeichnen würde, mit ein bisschen genauerer
 * Zeitunterteilung, vielleicht im Stunden- oder im 2-Stunden-Takt. Das Gleiche auf der
 * Leistungshöhe [...] in 10 Teilungen". Senkrechte Linien alle 2 Stunden (12 Linien, dichter als
 * die bestehende 6-Stunden-Beschriftung, aber noch übersichtlich), waagrechte Linien in 10
 * gleich große Abschnitte der Leistungsachse (9 Trennlinien, die Achsenenden 0/max sind bereits
 * durch die Fläche selbst markiert). Muss NACH der Geometrie-Berechnung (x()/yFromW()/$n/$maxW),
 * aber VOR den Flächen/Linien des eigentlichen Diagramms eingebunden werden (Hintergrund).
 *
 * Erwartet im Scope des einbindenden Views: $x (Closure Intervall-Index -> SVG-X), $yFromW
 * (Closure Watt -> SVG-Y), $n (Intervall-Anzahl), $maxW (oberes Ende der Leistungsachse), $H
 * (SVG-Höhe), $padL (linker Rand).
 */
$gridY0 = $yFromW(0);
?>
<g class="chart-grid">
  <?php for ($h = 2; $h < 24; $h += 2): $gi = (int)round($h * 4); $gi = min($gi, $n - 1); $gx = $x($gi); ?>
    <line x1="<?= $gx ?>" y1="10" x2="<?= $gx ?>" y2="<?= $gridY0 ?>" stroke="var(--gray-200)" stroke-width="1" />
  <?php endfor; ?>
  <?php for ($k = 1; $k <= 9; $k++): $gy = $yFromW($maxW * $k / 10); ?>
    <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $x($n - 1) ?>" y2="<?= $gy ?>" stroke="var(--gray-200)" stroke-width="1" />
  <?php endfor; ?>
</g>
