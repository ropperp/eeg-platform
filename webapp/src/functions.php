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

/**
 * Erzeugt eine SEPA-Basislastschrift (SDD CORE) als pain.008-XML — die Datei, die der Obmann in
 * sein Online-Banking hochlädt. Version umschaltbar: '08' = pain.008.001.08 (neu, Standard),
 * '02' = pain.008.001.02 (alt). Reine Funktion (kein DB/HTTP) -> automatisiert testbar.
 *
 * WICHTIG (Compliance): Das Zielformat muss zu dem passen, was die konkrete Bank akzeptiert.
 * Vor dem Echtbetrieb die erzeugte Datei mit dem Prüf-/Testtool der Bank validieren.
 *
 * @param array $creditor ['name','iban','bic'(optional),'creditor_id']
 * @param array $txns Liste von ['end_to_end_id','amount'(float>0),'mandate_ref','mandate_date'(Y-m-d),
 *                    'debtor_name','debtor_iban','debtor_bic'(optional),'remittance']
 * @param string $collectionDate Fälligkeit/Einzugsdatum (Y-m-d)
 * @param string $version '08' oder '02'
 * @param string $seqType SEPA-Sequenztyp: FRST | RCUR | OOFF | FNAL
 * @param string $msgId eindeutige Nachrichten-ID (Default: generiert)
 */
function sepaPain008Xml(
    array $creditor, array $txns, string $collectionDate,
    string $version = '08', string $seqType = 'RCUR', string $msgId = ''
): string {
    $version = $version === '02' ? '02' : '08';
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $money = fn($v) => number_format((float)$v, 2, '.', '');
    $ns = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.' . $version;
    // BIC-Element heißt in .02 <BIC>, in der 2019er-Fassung .08 <BICFI>.
    $bicTag = $version === '02' ? 'BIC' : 'BICFI';
    $agent = function (?string $bic) use ($x, $bicTag): string {
        $bic = trim((string)$bic);
        return $bic !== ''
            ? "<FinInstnId><{$bicTag}>" . $x($bic) . "</{$bicTag}></FinInstnId>"
            : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
    };

    $msgId  = $msgId !== '' ? $msgId : ('SFA-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6));
    $creDt  = date('Y-m-d\TH:i:s');
    $nbTx   = count($txns);
    $ctrl   = $money(array_sum(array_map(fn($t) => (float)$t['amount'], $txns)));

    $txXml = '';
    foreach ($txns as $t) {
        $txXml .=
            '<DrctDbtTxInf>'
          .   '<PmtId><EndToEndId>' . $x($t['end_to_end_id'] ?? 'NOTPROVIDED') . '</EndToEndId></PmtId>'
          .   '<InstdAmt Ccy="EUR">' . $money($t['amount']) . '</InstdAmt>'
          .   '<DrctDbtTx><MndtRltdInf>'
          .     '<MndtId>' . $x($t['mandate_ref']) . '</MndtId>'
          .     '<DtOfSgntr>' . $x($t['mandate_date']) . '</DtOfSgntr>'
          .   '</MndtRltdInf></DrctDbtTx>'
          .   '<DbtrAgt>' . $agent($t['debtor_bic'] ?? '') . '</DbtrAgt>'
          .   '<Dbtr><Nm>' . $x($t['debtor_name']) . '</Nm></Dbtr>'
          .   '<DbtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $t['debtor_iban'])) . '</IBAN></Id></DbtrAcct>'
          .   '<RmtInf><Ustrd>' . $x($t['remittance'] ?? '') . '</Ustrd></RmtInf>'
          . '</DrctDbtTxInf>';
    }

    $xml =
        '<?xml version="1.0" encoding="UTF-8"?>'
      . '<Document xmlns="' . $ns . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<CstmrDrctDbtInitn>'
      .   '<GrpHdr>'
      .     '<MsgId>' . $x($msgId) . '</MsgId>'
      .     '<CreDtTm>' . $creDt . '</CreDtTm>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<InitgPty><Nm>' . $x($creditor['name']) . '</Nm></InitgPty>'
      .   '</GrpHdr>'
      .   '<PmtInf>'
      .     '<PmtInfId>' . $x($msgId) . '-1</PmtInfId>'
      .     '<PmtMtd>DD</PmtMtd>'
      .     '<NbOfTxs>' . $nbTx . '</NbOfTxs>'
      .     '<CtrlSum>' . $ctrl . '</CtrlSum>'
      .     '<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm>'
      .       '<SeqTp>' . $x($seqType) . '</SeqTp></PmtTpInf>'
      .     '<ReqdColltnDt>' . $x($collectionDate) . '</ReqdColltnDt>'
      .     '<Cdtr><Nm>' . $x($creditor['name']) . '</Nm></Cdtr>'
      .     '<CdtrAcct><Id><IBAN>' . $x(str_replace(' ', '', $creditor['iban'])) . '</IBAN></Id></CdtrAcct>'
      .     '<CdtrAgt>' . $agent($creditor['bic'] ?? '') . '</CdtrAgt>'
      .     '<ChrgBr>SLEV</ChrgBr>'
      .     '<CdtrSchmeId><Id><PrvtId><Othr><Id>' . $x($creditor['creditor_id']) . '</Id>'
      .       '<SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>'
      .     $txXml
      .   '</PmtInf>'
      . '</CstmrDrctDbtInitn></Document>';

    return $xml;
}

// ─── TOTP (RFC 6238) für die optionale Zwei-Faktor-Authentifizierung ───────────────
// Bewusst abhängigkeitsfrei (nur hash_hmac/random_bytes), damit keine Composer-Pakete nötig
// sind und die Logik testbar bleibt. Kompatibel mit Apple Passwörter, Google Authenticator etc.

/** Base32-Dekodierung (RFC 4648, ohne Padding) für den geteilten TOTP-Schlüssel. */
function base32Decode(string $b32): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $bits .= str_pad(decbin(strpos($map, $b32[$i])), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    }
    return $out;
}

/** Base32-Kodierung (RFC 4648, ohne Padding) -- für die Anzeige neu erzeugter Secrets. */
function base32Encode(string $bin): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $map[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/** Erzeugt den 6-stelligen TOTP-Code für einen bestimmten Zeitpunkt (Default: 30s-Fenster). */
function totpCodeAt(string $secretBase32, int $timestamp, int $period = 30, int $digits = 6): string
{
    $counter = intdiv($timestamp, $period);
    $binCounter = pack('N*', 0) . pack('N*', $counter);   // 8-Byte-Big-Endian-Zähler
    $hash = hash_hmac('sha1', $binCounter, base32Decode($secretBase32), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          |  (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen eingegebenen TOTP-Code gegen das Secret, mit ±$window Zeitfenstern Toleranz
 * (Standard 1 = ±30s, gleicht kleine Uhr-Abweichungen aus). Zeitkonstanter Vergleich.
 */
function totpVerify(string $secretBase32, string $code, ?int $timestamp = null, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $timestamp = $timestamp ?? time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCodeAt($secretBase32, $timestamp + $i * 30), $code)) return true;
    }
    return false;
}

/** Neues, zufälliges Base32-Secret (Standard 20 Byte = 160 Bit, wie in RFC 6238 empfohlen). */
function totpGenerateSecret(int $bytes = 20): string
{
    return base32Encode(random_bytes($bytes));
}

/** otpauth://-URI zum Einrichten (QR-Code/Setup-Schlüssel in Apple Passwörter & Co.). */
function totpProvisioningUri(string $secretBase32, string $account, string $issuer): string
{
    $label = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label . '?secret=' . $secretBase32
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Normalisiert einen Wert für den Audit-Vergleich in einen gut lesbaren String: null/'' -> '',
 * bool -> 'ja'/'nein', DB-Bool-Strings 't'/'f' ebenso, Zahlen als String, sonst getrimmt.
 */
function auditNormalizeValue($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'ja' : 'nein';
    $s = trim((string)$v);
    if ($s === 't' || $s === 'true')  return 'ja';
    if ($s === 'f' || $s === 'false') return 'nein';
    return $s;
}

/**
 * Vergleicht zwei Datensätze (vorher/nachher) feldweise und liefert je geändertem Feld
 * ['label' => Anzeigename, 'von' => alt, 'auf' => neu]. Nur Felder aus $labels werden geprüft
 * (so bleiben interne/sensible Spalten außen vor); zusätzlich schützt $ignore einzelne Keys.
 * Rückgabe ist nach Feld-Key indiziert und enthält nur tatsächliche Änderungen.
 */
function auditDiff(array $before, array $after, array $labels, array $ignore = []): array
{
    $changes = [];
    foreach ($labels as $key => $label) {
        if (in_array($key, $ignore, true)) continue;
        if (!array_key_exists($key, $after)) continue;
        $von = auditNormalizeValue($before[$key] ?? null);
        $auf = auditNormalizeValue($after[$key]);
        if ($von !== $auf) {
            $changes[$key] = ['label' => $label, 'von' => $von, 'auf' => $auf];
        }
    }
    return $changes;
}

/**
 * Formatiert einen Änderungssatz (aus auditDiff) als lesbaren Satz:
 * „Name: „Alt" → „Neu"; IBAN: „—" → „AT..."". Leere Werte werden als „—" dargestellt.
 */
function auditChangesText(array $changes): string
{
    $parts = [];
    foreach ($changes as $c) {
        $von = $c['von'] === '' ? '—' : $c['von'];
        $auf = $c['auf'] === '' ? '—' : $c['auf'];
        $parts[] = $c['label'] . ': „' . $von . '" → „' . $auf . '"';
    }
    return implode('; ', $parts);
}

/**
 * Bezeichnung einer Mahnstufe: 1 = Zahlungserinnerung, 2 = 1. Mahnung, 3+ = 2./letzte Mahnung.
 * Stufe 0 (noch nicht gemahnt) ergibt einen leeren String.
 */
function mahnstufeText(int $stufe): string
{
    return match (true) {
        $stufe <= 0 => '',
        $stufe === 1 => 'Zahlungserinnerung',
        $stufe === 2 => '1. Mahnung',
        default => '2. Mahnung (letzte Aufforderung)',
    };
}

/**
 * Baut die formelle E-Mail-Anrede eines Mitglieds: ['anrede' => 'Sehr geehrter Herr',
 * 'nachname' => '<Titel> <Nachname>']. Getrennt vom Geschlecht (salutation), das die Person
 * selbst angibt -- email_anrede_mode überschreibt nur die Anrede:
 *   auto    -> aus salutation ableiten (Herr/Frau; sonst neutral "Guten Tag")
 *   herr    -> "Sehr geehrter Herr"
 *   frau    -> "Sehr geehrte Frau"
 *   familie -> "Sehr geehrte Familie"
 * Der Nachname bleibt immer der (Titel +) Nachname des Vertragspartners. Firmenmitglieder ohne
 * Personennamen bekommen "Sehr geehrte Damen und Herren" ohne Nachname.
 */
function mailSalutation(array $m): array
{
    $titel = trim((string)($m['titel'] ?? ''));
    $last  = trim((string)($m['last_name'] ?? ''));
    if ($last === '' && trim((string)($m['company_name'] ?? '')) !== '') {
        return ['anrede' => 'Sehr geehrte Damen und Herren', 'nachname' => ''];
    }
    $mode = $m['email_anrede_mode'] ?? 'auto';
    if ($mode === 'auto') {
        $sal = trim((string)($m['salutation'] ?? ''));
        $mode = $sal === 'Herr' ? 'herr' : ($sal === 'Frau' ? 'frau' : 'neutral');
    }
    $anrede = match ($mode) {
        'herr'    => 'Sehr geehrter Herr',
        'frau'    => 'Sehr geehrte Frau',
        'familie' => 'Sehr geehrte Familie',
        default   => 'Guten Tag',
    };
    return ['anrede' => $anrede, 'nachname' => trim($titel . ' ' . $last)];
}

/**
 * Umsatzsteuer-Aufteilung für einen Netto-Betrag. Die Tarife der Plattform sind grundsätzlich
 * netto hinterlegt. Bei 'kleinunternehmer' (§ 6 Abs 1 Z 27 UStG) fällt keine USt an, netto =
 * brutto. Bei 'standard' wird der USt-Satz (Default 20 %) auf den Netto-Betrag aufgeschlagen.
 * 'brutto' ist der tatsächlich zu zahlende bzw. einzuziehende Betrag (auch für SEPA/Vorabinfo).
 * Funktioniert vorzeichenrichtig: bei negativem Netto (Guthaben/Einspeisung) ist auch die USt
 * negativ, brutto = netto * (1 + Satz).
 * Rückgabe: ['model','rate','netto','ust','brutto'] (Beträge auf 2 Nachkommastellen gerundet).
 */
function taxBreakdown(float $netto, ?string $model, $rate): array
{
    $model = $model === 'standard' ? 'standard' : 'kleinunternehmer';
    $ratePct = $model === 'standard'
        ? (float)str_replace(',', '.', (string)($rate ?? 20))
        : 0.0;
    $netto = round($netto, 2);
    $ust   = round($netto * $ratePct / 100, 2);
    return [
        'model'  => $model,
        'rate'   => $ratePct,
        'netto'  => $netto,
        'ust'    => $ust,
        'brutto' => round($netto + $ust, 2),
    ];
}
