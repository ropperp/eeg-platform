<?php
/**
 * Monats-Navigation + Tages-Raster für die Viertelstunden-Diagramme (my_verbrauch.php,
 * my_einspeisung.php) -- ersetzt das bisherige reine "Vortag"/"Folgetag"/<input type="date">,
 * mit dem man sich Tag für Tag durchklicken musste (Patrick, 06.09.2026 [Folgetermin]: "beim
 * langsam hin- und herscrollen gefällt mir das nicht [...] wenn man sagt, man möchte zu einem
 * gewissen Datum gehen [...] ist das einfach ein bisschen zu langsam. Interessant wäre, wenn man
 * so über eine Eingabe oder über Pfeiltasten zu den Monaten springen kann und dann [...] mit
 * Zahlen [...] Wenn Daten vorhanden sind [...] grün, bei den Einspeisern gelb. Wenn noch keine
 * Daten vorhanden sind [...] in Grau").
 *
 * Erwartet im Scope des einbindenden Views:
 *  $date              string  Y-m-d, aktuell ausgewählter Tag
 *  $month             string  Y-m, aktuell angezeigter Monat (kann von $date abweichen, wenn
 *                              man nur den Monat gewechselt hat ohne schon einen Tag zu wählen)
 *  $monthAvailability array   Tag-Nummer (1..31) => true, für Tage MIT Daten in $month
 *  $pickerColor       string  'green' (Verbraucher) oder 'yellow' (Einspeiser)
 *  $baseUrl           string  z.B. '/portal/my/verbrauch'
 */
$pickerColors = [
    'green'  => ['bg' => '#86efac', 'border' => '#16a34a', 'text' => '#14532d'],
    'yellow' => ['bg' => '#fde68a', 'border' => '#b45309', 'text' => '#78350f'],
];
$pc = $pickerColors[$pickerColor] ?? $pickerColors['green'];

$monthTs = strtotime($month . '-01');
$prevMonth = date('Y-m', strtotime('-1 month', $monthTs));
$nextMonth = date('Y-m', strtotime('+1 month', $monthTs));
$todayMonth = date('Y-m');
$nextMonthDisabled = $nextMonth > $todayMonth;
$daysInMonth = (int)date('t', $monthTs);
$today = date('Y-m-d');
?>
<div class="interval-day-picker" style="margin-bottom:1rem">
  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;flex-wrap:wrap">
    <a href="<?= htmlspecialchars($baseUrl) ?>?month=<?= $prevMonth ?>" class="btn" style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem;padding:.35rem .6rem">&larr;</a>
    <form method="get" style="display:inline">
      <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= $todayMonth ?>"
             onchange="this.form.submit()" style="padding:.4rem .6rem;border:1px solid var(--gray-200);border-radius:6px">
    </form>
    <a href="<?= $nextMonthDisabled ? '#' : htmlspecialchars($baseUrl) . '?month=' . $nextMonth ?>" class="btn"
       style="background:var(--gray-100);color:var(--gray-700);font-size:.8rem;padding:.35rem .6rem<?= $nextMonthDisabled ? ';pointer-events:none;opacity:.4' : '' ?>">&rarr;</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:.4rem">
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
      $dayDate = $month . '-' . sprintf('%02d', $d);
      $hasData = !empty($monthAvailability[$d]);
      $isFuture = $dayDate > $today;
      $isSelected = $dayDate === $date;
      if ($isFuture) {
          $bg = 'var(--gray-100)'; $border = 'var(--gray-200)'; $text = 'var(--gray-600)';
      } elseif ($hasData) {
          $bg = $pc['bg']; $border = $pc['border']; $text = $pc['text'];
      } else {
          $bg = 'var(--gray-100)'; $border = 'var(--gray-200)'; $text = 'var(--gray-600)';
      }
    ?>
      <?php if ($isFuture): ?>
        <span style="width:2.1rem;height:2.1rem;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:.8rem;background:<?= $bg ?>;border:1px solid <?= $border ?>;color:<?= $text ?>;opacity:.5"><?= $d ?></span>
      <?php else: ?>
        <a href="<?= htmlspecialchars($baseUrl) ?>?date=<?= $dayDate ?>&month=<?= $month ?>"
           title="<?= $hasData ? 'Daten vorhanden' : 'Noch keine Daten für diesen Tag' ?>"
           style="width:2.1rem;height:2.1rem;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:.8rem;font-weight:<?= $isSelected ? '700' : '400' ?>;text-decoration:none;background:<?= $bg ?>;border:<?= $isSelected ? '2px solid #1e293b' : '1px solid ' . $border ?>;color:<?= $isSelected ? '#1e293b' : $text ?>"><?= $d ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <div style="display:flex;gap:1.25rem;margin-top:.5rem;font-size:.75rem;color:var(--gray-600)">
    <span><span style="display:inline-block;width:.7rem;height:.7rem;background:<?= $pc['bg'] ?>;border:1px solid <?= $pc['border'] ?>;border-radius:3px;margin-right:.3rem;vertical-align:middle"></span>Daten vorhanden</span>
    <span><span style="display:inline-block;width:.7rem;height:.7rem;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:3px;margin-right:.3rem;vertical-align:middle"></span>noch keine Daten</span>
  </div>
</div>
<script>
// Pfeiltasten-Navigation zwischen Monaten (Patrick: "über [...] Pfeiltasten zu den Monaten
// springen") -- nur wenn der Fokus NICHT in einem Eingabefeld liegt, damit z.B. Tippen im
// Monats-Feld selbst (Pfeiltasten zum Ändern von Tag/Monat/Jahr im nativen Picker) nicht
// versehentlich gleich die ganze Seite wechselt.
(function () {
  document.addEventListener('keydown', function (e) {
    var tag = (document.activeElement || {}).tagName;
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft') {
      window.location.href = <?= json_encode($baseUrl) ?> + '?month=' + <?= json_encode($prevMonth) ?>;
    } else if (e.key === 'ArrowRight' && !<?= $nextMonthDisabled ? 'true' : 'false' ?>) {
      window.location.href = <?= json_encode($baseUrl) ?> + '?month=' + <?= json_encode($nextMonth) ?>;
    }
  });
})();
</script>
