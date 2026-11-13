<?php

declare(strict_types=1);

// Diagramm-Bausteine für das Glas-Design der Dashboards (Patrick, 13.11.2026: "bau bitte auch
// die Diagramme dazu" -- Säulendiagramm mit Zeitraum-Umschalter, Ring mit Prozent, Kreis mit
// Segmenten wie im Video-Vorbild). Reines HTML/SVG, kein externes Paket (CSP: nur 'self').
// Optik in assets/css/glass.css (.gchart-*), Zeitraum-Umschalter in assets/js/glass-charts.js.

/** Kurzer Monatsname, österreichisch ("Jän", "Feb", …). */
function chartMonthShort(string $ym): string
{
    $kurz = [1 => 'Jän', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    $m = (int)substr($ym, 5, 2);
    return $kurz[$m] ?? $ym;
}

/** Die letzten $n Kalendermonate als ['YYYY-MM', …], ältester zuerst, bis einschließlich $now. */
function chartMonthKeys(int $n, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna')))->modify('first day of this month');
    $keys = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $keys[] = $now->modify("-$i months")->format('Y-m');
    }
    return $keys;
}

/**
 * „Schöne“ Achsen-Obergrenze in 3 gleichen Schritten (z.B. 0/20/40/60): liefert [obergrenze, schritt].
 * Schritt ist 1, 2, 2,5 oder 5 × Zehnerpotenz.
 */
function chartNiceScale(float $max): array
{
    if ($max <= 0) return [3.0, 1.0];
    $raw = $max / 3;
    $exp = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $f) {
        if ($raw <= $f * $exp + 1e-9) {
            $step = $f * $exp;
            return [$step * 3, $step];
        }
    }
    return [$raw * 3, $raw];
}

/** Zahl für Achsen/Tooltips: 1.234 bzw. 12,5 (bei kleinen Werten eine Nachkommastelle). */
function chartNumber(float $v): string
{
    return abs($v) < 10 && floor($v) != $v ? number_format($v, 1, ',', '.') : number_format($v, 0, ',', '.');
}

/**
 * Säulendiagramm je Monat mit Umschalter 3/6/12 Monate.
 * $months  = ['YYYY-MM', …] (ältester zuerst)
 * $series  = [['name' => 'Bezug aus der Gemeinschaft', 'class' => 'c1', 'values' => ['YYYY-MM' => float]], …]
 * Ohne JavaScript bleibt der Standard-Zeitraum ($defaultRange) sichtbar und korrekt skaliert.
 */
function renderBarChart(array $months, array $series, string $unit = 'kWh', int $defaultRange = 6, array $ranges = [3, 6, 12]): string
{
    $visible = array_slice($months, -$defaultRange);
    $max = 0.0;
    foreach ($series as $s) {
        foreach ($visible as $ym) { $max = max($max, (float)($s['values'][$ym] ?? 0)); }
    }
    [$top, $step] = chartNiceScale($max);

    $summary = [];
    foreach ($series as $s) {
        $parts = [];
        foreach ($visible as $ym) { $parts[] = chartMonthShort($ym) . ' ' . chartNumber((float)($s['values'][$ym] ?? 0)); }
        $summary[] = $s['name'] . ' in ' . $unit . ': ' . implode(', ', $parts);
    }

    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES);
    $out = '<div class="gchart" data-gchart data-unit="' . $h($unit) . '">';
    $out .= '<div class="gchart-head">';
    $out .= '<div class="gchart-legend">';
    foreach ($series as $s) {
        $out .= '<span class="gchart-key ' . $h($s['class']) . '"><i></i>' . $h($s['name']) . '</span>';
    }
    $out .= '</div><div class="gchart-range" role="group" aria-label="Zeitraum">';
    foreach ($ranges as $r) {
        if ($r > count($months)) continue;
        $out .= '<button type="button" data-range="' . $r . '" aria-pressed="' . ($r === $defaultRange ? 'true' : 'false') . '">' . $r . ' Monate</button>';
    }
    $out .= '</div></div>';

    $out .= '<div class="gchart-plot" role="img" aria-label="' . $h(implode('. ', $summary)) . '">';
    $out .= '<div class="gchart-grid" aria-hidden="true">';
    for ($i = 3; $i >= 0; $i--) {
        $out .= '<span><em data-tick="' . $i . '">' . chartNumber($step * $i) . '</em></span>';
    }
    $out .= '</div><div class="gchart-cols" aria-hidden="true">';
    $lastWithData = null;
    foreach ($months as $ym) {
        foreach ($series as $s) { if ((float)($s['values'][$ym] ?? 0) > 0) $lastWithData = $ym; }
    }
    foreach ($months as $idx => $ym) {
        $hidden = in_array($ym, $visible, true) ? '' : ' hidden';
        $current = $ym === $lastWithData ? ' is-current' : '';
        $out .= '<div class="gchart-col' . $current . '" data-col' . $hidden . '><div class="gchart-bars">';
        foreach ($series as $s) {
            $v = (float)($s['values'][$ym] ?? 0);
            $pct = $top > 0 ? min(100, round($v / $top * 100, 1)) : 0;
            $label = monatsLabel($ym . '-01') . ': ' . chartNumber($v) . ' ' . $unit . ' ' . $s['name'];
            $out .= '<span class="gchart-bar ' . $h($s['class']) . '" data-v="' . $v . '" style="--h:' . $pct . '%" title="' . $h($label) . '"><b>' . chartNumber($v) . '</b></span>';
        }
        $out .= '</div><span class="gchart-x">' . $h(chartMonthShort($ym)) . '</span></div>';
    }
    $out .= '</div></div></div>';
    return $out;
}

/** Ring mit Prozentwert in der Mitte (z.B. Autarkie). $pct 0..100. */
function renderRingChart(float $pct, string $sub, string $class = 'c1'): string
{
    $pct = max(0.0, min(100.0, $pct));
    $r = 52;
    $c = 2 * M_PI * $r;
    $dash = round($c * $pct / 100, 2);
    $label = number_format($pct, 0, ',', '.') . ' %';
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES);
    return '<div class="gring ' . $h($class) . '" role="img" aria-label="' . $h($label . ' ' . $sub) . '">'
        . '<svg viewBox="0 0 120 120" aria-hidden="true">'
        . '<circle class="gring-track" cx="60" cy="60" r="' . $r . '"/>'
        . '<circle class="gring-value" cx="60" cy="60" r="' . $r . '" style="--dash:' . $dash . ';--c:' . round($c, 2) . '"'
        . ' stroke-dasharray="' . $dash . ' ' . round($c, 2) . '" transform="rotate(-90 60 60)"/>'
        . '</svg><div class="gring-center"><strong>' . $h($label) . '</strong><span>' . $h($sub) . '</span></div></div>';
}

/**
 * Kreis mit Segmenten und Legende (z.B. Zählpunkte nach Art).
 * $segments = [['label' => 'Verbraucher', 'value' => 12, 'class' => 'c1'], …]
 */
function renderDonutChart(array $segments, string $centerValue, string $centerSub): string
{
    $total = array_sum(array_map(fn($s) => max(0, (float)$s['value']), $segments));
    $r = 50;
    $c = 2 * M_PI * $r;
    $gap = $total > 0 && count(array_filter($segments, fn($s) => $s['value'] > 0)) > 1 ? 3.0 : 0.0;
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES);
    $aria = [];
    $circles = '';
    $offset = 0.0;
    foreach ($segments as $s) {
        $v = max(0, (float)$s['value']);
        $aria[] = $s['label'] . ' ' . chartNumber($v);
        if ($total <= 0 || $v <= 0) continue;
        $len = $c * $v / $total;
        $seg = max(0.5, $len - $gap);
        $circles .= '<circle class="gdonut-seg ' . $h($s['class']) . '" cx="60" cy="60" r="' . $r . '"'
            . ' stroke-dasharray="' . round($seg, 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '"'
            . ' transform="rotate(-90 60 60)"><title>' . $h($s['label'] . ': ' . chartNumber($v)) . '</title></circle>';
        $offset += $len;
    }
    $out = '<div class="gdonut"><div class="gdonut-figure" role="img" aria-label="' . $h($centerSub . ': ' . implode(', ', $aria)) . '">'
        . '<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="gring-track" cx="60" cy="60" r="' . $r . '"/>' . $circles . '</svg>'
        . '<div class="gring-center"><strong>' . $h($centerValue) . '</strong><span>' . $h($centerSub) . '</span></div></div>'
        . '<ul class="gdonut-legend">';
    foreach ($segments as $s) {
        $out .= '<li class="' . $h($s['class']) . '"><i></i><span>' . $h($s['label']) . '</span><strong>' . chartNumber((float)$s['value']) . '</strong></li>';
    }
    return $out . '</ul></div>';
}
