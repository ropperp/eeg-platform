<?php

declare(strict_types=1);

/**
 * Reine (seiteneffektfreie) Hilfsfunktionen -- bewusst aus public/index.php ausgelagert,
 * damit sie ohne den Router/HTTP-Kontext geladen und automatisiert getestet werden können
 * (siehe tests/). index.php bindet diese Datei beim Start ein; die Funktionsnamen bleiben
 * global, alle bestehenden Aufrufstellen funktionieren unverändert weiter.
 */

/**
 * Prüft eine IBAN per Mod-97-Verfahren (ISO 7064). Erwartet die IBAN ohne
 * Leerzeichen/Kleinbuchstaben-Normalisierung durch den Aufrufer.
 */
function validateIban(string $iban): bool
{
    $iban = strtoupper(str_replace(' ', '', $iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
    }
    // Mod-97 blockweise ohne bcmath: Rest + max. 7 neue Ziffern bleibt immer < PHP_INT_MAX
    $remainder = 0;
    for ($offset = 0; $offset < strlen($numeric); $offset += 7) {
        $remainder = (int)((string)$remainder . substr($numeric, $offset, 7)) % 97;
    }
    return $remainder === 1;
}

/**
 * Prüft eine österreichische Zählpunktnummer: "AT" + 31 alphanumerische
 * Stellen = 33 Zeichen gesamt.
 */
function validateZaehlpunkt(string $zp): bool
{
    return (bool)preg_match('/^AT[A-Z0-9]{31}$/', strtoupper(trim($zp)));
}

/**
 * Escaped einen String für sichere Verwendung in LaTeX-Zellwerten.
 * Nicht für RAW_-Variablen verwenden (die enthalten bereits LaTeX-Syntax).
 */
function texEscape(string $s): string
{
    return strtr($s, [
        '\\' => '\\textbackslash{}',
        '&'  => '\\&',
        '%'  => '\\%',
        '$'  => '\\$',
        '#'  => '\\#',
        '_'  => '\\_',
        '{'  => '\\{',
        '}'  => '\\}',
        '~'  => '\\textasciitilde{}',
        '^'  => '\\textasciicircum{}',
        '—'  => '--',
        '–'  => '--',
    ]);
}

/**
 * Baut die LaTeX-Tabellenzeilen für manuelle Rechnungs-Zusatzpositionen (z.B. ein einmaliger
 * Rabatt) -- als RAW_-Variable, da hier bereits fertiges LaTeX (Tabellenzeilen mit \\)
 * hineinkommt. $items: Liste von ['label' => string, 'quantity' => float, 'unit' => string,
 * 'amount_eur' => float]. Leere Liste ergibt einen leeren String (keine zusätzliche Zeile).
 *
 * 4-Spalten-Format (Position / Menge / Tarif / Betrag) passend zur aktuellen rechnung.tex:
 * Zusatzpositionen haben keine Energiemenge/keinen Tarif -> diese beiden Zellen bleiben leer.
 * Eine von 1 abweichende Stückzahl wird in den Positionstext übernommen (die 4-spaltige
 * Tabelle hat keine eigene Mengen-/Einheitenspalte mehr für Nicht-Energie-Positionen).
 */
function rechnungExtraItemsLatex(array $items): string
{
    return implode("\n", array_map(function (array $it): string {
        $amount = (float)$it['amount_eur'];
        $amountStr = ($amount < 0 ? '$-$\\,' : '') . number_format(abs($amount), 2, ',', '.');
        $label = (string)$it['label'];
        $qtyFloat = (float)($it['quantity'] ?? 1);
        $unit = trim((string)($it['unit'] ?? ''));
        if ($qtyFloat != 1.0 || ($unit !== '' && $unit !== 'Stk')) {
            $qty = fmod($qtyFloat, 1.0) === 0.0
                ? number_format($qtyFloat, 0, ',', '.')
                : rtrim(rtrim(number_format($qtyFloat, 3, ',', '.'), '0'), ',');
            $label .= ' (' . $qty . ($unit !== '' ? ' ' . $unit : '') . ')';
        }
        return '  ' . texEscape($label) . ' & & & ' . $amountStr . ' \\\\';
    }, $items));
}

/**
 * Baut die vorformatierten Positionszeilen (RAW_BEZUG_POSITIONEN_LISTE bzw.
 * RAW_EINSPEISUNG_POSITIONEN_LISTE) für rechnung.tex -- eine Zeile PRO Zählpunkt. Jede Zeile
 * zeigt den Produktnamen und, falls eine Zählpunktnummer vorliegt, darunter (via \newline,
 * NICHT \\ -- sonst bricht die Tabellenzeile um) die 33-stellige Zählpunktnummer als kleine
 * graue Zweitzeile. Mehrere Zeilen werden mit einer feinen Trennlinie verbunden (nicht nach
 * der letzten -- die setzt die Vorlage selbst zwischen Bezugs- und Einspeisungsblock).
 * $items: invoice_items eines Typs (bezug oder einspeisung). $gutschrift=true stellt dem
 * Betrag ein Minuszeichen voran (Einspeisung wird von der Summe abgezogen).
 */
function rechnungPositionenLatex(array $items, string $label, bool $gutschrift): string
{
    $zeilen = array_map(function (array $it) use ($label, $gutschrift): string {
        $cell = $label;
        $zp = trim((string)($it['zaehlpunkt_nr'] ?? ''));
        if ($zp !== '') {
            $cell .= '\\newline{\\footnotesize\\color{midgray}Zählpunkt: ' . texEscape($zp) . '}';
        }
        $kwh    = number_format((float)$it['kwh'], 2, ',', '.');
        $tarif  = number_format((float)$it['rate_ct_kwh'], 4, ',', '.');
        $betrag = ($gutschrift ? '$-$\\,' : '') . number_format(abs((float)$it['amount_eur']), 2, ',', '.');
        return '  ' . $cell . ' & ' . $kwh . ' & ' . $tarif . ' & ' . $betrag . ' \\\\';
    }, $items);
    return implode("\n  \\arrayrulecolor{rulegray}\\hline\n", $zeilen);
}
