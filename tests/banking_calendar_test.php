<?php

declare(strict_types=1);

// Banktag-Kalender (isBankingDay()/nextBankingDay()/addBankingDays()/easterSunday()) --
// österreichische gesetzliche Feiertage + TARGET2-Schließtage, Ostern selbst per Gauß/Meeus
// berechnet. Siehe Aufgabe 18.10.2026 (Ausführungsdatum-Auswahl für SEPA-Dateien).

test('easterSunday: 2026 -> 05.04.2026', function () {
    assertSame('2026-04-05', easterSunday(2026));
});

test('easterSunday: 2027 -> 28.03.2027', function () {
    assertSame('2027-03-28', easterSunday(2027));
});

test('isBankingDay: normaler Werktag ist ein Bankarbeitstag', function () {
    assertTrue(isBankingDay('2026-10-20')); // Dienstag, keine Besonderheit
});

test('isBankingDay: Samstag/Sonntag sind keine Bankarbeitstage', function () {
    assertFalse(isBankingDay('2026-10-24')); // Samstag
    assertFalse(isBankingDay('2026-10-25')); // Sonntag
});

test('isBankingDay: Neujahr (01.01.) ist in beiden Jahren gesperrt', function () {
    assertFalse(isBankingDay('2026-01-01'));
    assertFalse(isBankingDay('2027-01-01'));
});

test('isBankingDay: Karfreitag ist TARGET2-Schließtag (obwohl in AT kein gesetzlicher Feiertag)', function () {
    assertFalse(isBankingDay('2026-04-03'));
    assertFalse(isBankingDay('2027-03-26'));
});

test('isBankingDay: Ostermontag gesperrt', function () {
    assertFalse(isBankingDay('2026-04-06'));
    assertFalse(isBankingDay('2027-03-29'));
});

test('isBankingDay: Christi Himmelfahrt gesperrt', function () {
    assertFalse(isBankingDay('2026-05-14'));
    assertFalse(isBankingDay('2027-05-06'));
});

test('isBankingDay: Pfingstmontag gesperrt', function () {
    assertFalse(isBankingDay('2026-05-25'));
    assertFalse(isBankingDay('2027-05-17'));
});

test('isBankingDay: Fronleichnam gesperrt', function () {
    assertFalse(isBankingDay('2026-06-04'));
    assertFalse(isBankingDay('2027-05-27'));
});

test('isBankingDay: Allerheiligen (01.11.) gesperrt, auch wenn kein Wochenende (2027)', function () {
    assertFalse(isBankingDay('2027-11-01')); // Montag, keine Wochenend-Überschneidung
});

test('isBankingDay: Nationalfeiertag (26.10.) gesperrt, auch wenn kein Wochenende (2026)', function () {
    assertFalse(isBankingDay('2026-10-26')); // Montag
});

test('isBankingDay: Weihnachten/Stefanitag gesperrt', function () {
    assertFalse(isBankingDay('2026-12-25'));
    assertFalse(isBankingDay('2026-12-26'));
});

test('bankingDayBlockReasonAT: liefert den konkreten Grund', function () {
    assertSame('Allerheiligen', bankingDayBlockReasonAT('2027-11-01'));
    assertSame('Sonntag', bankingDayBlockReasonAT('2026-10-25'));
    assertSame(null, bankingDayBlockReasonAT('2026-10-20'));
});

test('nextBankingDay: überspringt Wochenende', function () {
    // Mi 21.10. -> Do 22.10. ist ein normaler Werktag, keine Feiertags-Überschneidung.
    assertSame('2026-10-22', nextBankingDay('2026-10-21'));
});

test('nextBankingDay: überspringt Wochenende, Nationalfeiertag UND Allerheiligen (Beispiel aus der Aufgabe)', function () {
    // Rechnungsdatum 18.10.2026 + 14 Tage = 01.11.2026 (Sonntag + Allerheiligen) -- der
    // angezeigte Zeitraum beginnt am 01.11., wählbar ist aber erst Montag 02.11.
    assertSame('2026-11-02', nextBankingDay('2026-10-31'));
    // Fr 23.10. -> Sa/So (24./25.) -> Mo 26.10. ist zugleich Nationalfeiertag -> erst Di 27.10.
    assertSame('2026-10-27', nextBankingDay('2026-10-23'));
});

test('addBankingDays: 2 Bankarbeitstage nach einem Freitag, der auf den Nationalfeiertag trifft', function () {
    // Fr 23.10. -> Di 27.10. (1, überspringt Wochenende + Nationalfeiertags-Montag) -> Mi 28.10. (2)
    assertSame('2026-10-28', addBankingDays('2026-10-23', 2));
});

test('Jahreswechsel: letzter Bankarbeitstag des Jahres vor Neujahr', function () {
    // 31.12.2026 ist ein Donnerstag, 01.01.2027 Neujahr (Freitag) -- nextBankingDay sollte das
    // Wochenende danach ebenfalls überspringen und erst Montag 04.01.2027 liefern.
    assertTrue(isBankingDay('2026-12-31'));
    assertSame('2027-01-04', nextBankingDay('2026-12-31'));
});

test('Fälligkeit auf Rechnung: Snap auf nächsten Bankarbeitstag (invoicePdfPayload()-Formel, Aufgabenbeispiel)', function () {
    // Gleiche Formel wie in invoicePdfPayload(): released_at + sepa_prenotification_days Tage,
    // dann auf den nächsten Bankarbeitstag verschieben, falls nötig.
    $snap = function (string $releasedAt, int $prenotifDays): string {
        $raw = date('Y-m-d', strtotime($releasedAt . " +{$prenotifDays} days"));
        return isBankingDay($raw) ? $raw : nextBankingDay($raw);
    };
    // Rechnungsdatum 18.10.2026 + 14 Tage = 01.11.2026 (Sonntag + Allerheiligen) -> 02.11.2026.
    assertSame('2026-11-02', $snap('2026-10-18', 14));
    // Fällt die Rohfrist auf einen normalen Bankarbeitstag, bleibt sie unverändert.
    assertSame('2026-10-20', $snap('2026-10-06', 14));
});
