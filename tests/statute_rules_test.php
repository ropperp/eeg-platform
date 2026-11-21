<?php

declare(strict_types=1);

// Statuten-Generator: Normalisierung des Fragebogens und Prüfregeln (ohne DB).

use App\Domain\Statutes\StatuteQuestionnaire as SQ;
use App\Domain\Statutes\StatuteRules as SR;

$statuteValid = function (array $over = []): array {
    return SQ::normalize(array_merge(SQ::defaults(), [
        'name' => 'Erneuerbare-Energie-Gemeinschaft Musterdorf', 'seat' => 'Musterdorf', 'state' => 'kaernten',
        'grid_operator' => 'Musternetz GmbH', 'founders_count' => 3, 'energy_sources' => ['pv'],
        'member_extra' => '1', 'member_honorary' => '1', 'succession' => '1', 'data_release' => '1',
        'cooptation' => '1', 'casting_vote' => '1', 'circular_resolution' => '1', 'invite_email' => '1',
        'virtual' => '1', 'proxy' => '1',
    ], $over));
};
$hasError = fn(array $r, string $needle): bool => (bool) array_filter($r['errors'], fn($e) => str_contains($e, $needle));
$hasHint = fn(array $r, string $needle): bool => (bool) array_filter($r['hints'], fn($e) => str_contains($e, $needle));

test('Statuten: gültige Standardangaben ergeben keine Fehler', function () use ($statuteValid) {
    $r = SR::check($statuteValid());
    assertSame([], $r['errors']);
});

test('Statuten: Pflichtfelder nur beim Erzeugen, nicht beim Zwischenspeichern', function () use ($hasError) {
    $a = SQ::normalize(['founders_count' => 2]);
    assertTrue($hasError(SR::check($a, true), 'Vereinsname'));
    assertFalse($hasError(SR::check($a, false), 'Vereinsname'));
});

test('Statuten: mindestens zwei Gründer, zwei Vorstände, zwei Rechnungsprüfer', function () use ($statuteValid, $hasError) {
    assertTrue($hasError(SR::check($statuteValid(['founders_count' => 1])), 'zwei Gründungsmitglieder'));
    assertTrue($hasError(SR::check($statuteValid(['board_size' => 1])), '§ 5 Abs 3 VerG'));
    assertTrue($hasError(SR::check($statuteValid(['auditors_count' => 1])), '§ 5 Abs 5 VerG'));
});

test('Statuten: Vorstand muss alle gewählten Funktionen fassen', function () use ($statuteValid, $hasError) {
    $r = SR::check($statuteValid(['board_size' => 3, 'has_secretary' => '1', 'deputy_chair' => '1']));
    assertTrue($hasError($r, 'mindestens 4 Personen'));
    assertSame([], SR::check($statuteValid(['board_size' => 4, 'has_secretary' => '1', 'deputy_chair' => '1']))['errors']);
});

test('Statuten: Schriftführer-Stv. ohne Schriftführer wird verworfen', function () {
    $a = SQ::normalize(['deputy_secretary' => '1']);
    assertFalse($a['deputy_secretary']);
});

test('Statuten: Rechnungsprüfer darf nicht im Vorstand sein (Gründerliste)', function () use ($statuteValid, $hasError) {
    $r = SR::check($statuteValid(['founders' => "Person A; Obmann\nPerson B; Kassier\nPerson A; Rechnungsprüfer"]));
    assertTrue($hasError($r, 'Person A'));
    $ok = SR::check($statuteValid(['founders' => "Person A; Obmann\nPerson C; Rechnungsprüferin"]));
    assertSame([], $ok['errors']);
});

test('Statuten: MV höchstens alle 5 Jahre und nicht seltener als die Funktionsperiode', function () use ($statuteValid, $hasError) {
    assertTrue($hasError(SR::check($statuteValid(['mv_interval' => 6, 'board_term' => 5])), 'fünf Jahre'));
    assertTrue($hasError(SR::check($statuteValid(['mv_interval' => 4, 'board_term' => 3])), 'Funktionsperiode'));
    assertSame([], SR::check($statuteValid(['mv_interval' => 3, 'board_term' => 3]))['errors']);
});

test('Statuten: EEG nur im Lokal- oder Regionalbereich', function () use ($statuteValid, $hasError) {
    assertTrue($hasError(SR::check($statuteValid(['area' => 'oesterreichweit'])), 'Lokal- oder Regionalbereich'));
    assertSame([], SR::check($statuteValid(['area' => 'lokal']))['errors']);
});

test('Statuten: Kleinunternehmerregelung über 55.000 € Umsatz nicht möglich', function () use ($statuteValid, $hasError, $hasHint) {
    assertTrue($hasError(SR::check($statuteValid(['expected_turnover' => 60000, 'vat_mode' => 'klein'])), 'Kleinunternehmerregelung nicht möglich'));
    assertSame([], SR::check($statuteValid(['expected_turnover' => 60000, 'vat_mode' => 'regel']))['errors']);
    assertTrue($hasHint(SR::check($statuteValid(['expected_turnover' => 50000])), 'nahe an der Kleinunternehmergrenze'));
    assertTrue($hasHint(SR::check($statuteValid(['vat_mode' => 'regel'])), 'U12'));
    assertTrue($hasHint(SR::check($statuteValid(['expected_turnover' => 1500000, 'vat_mode' => 'regel'])), '§ 22 Abs 1 VerG'));
    assertTrue($hasHint(SR::check($statuteValid(['expected_turnover' => 3500000, 'vat_mode' => 'regel'])), 'Abschlussprüfer'));
});

test('Statuten: Hinweise zu Austrittsfrist, Stellvertretung und Körperschaftsteuer', function () use ($statuteValid, $hasHint) {
    $r = SR::check($statuteValid(['exit_notice' => 'q']));
    assertTrue($hasHint($r, 'Verbraucher'));
    assertTrue($hasHint($r, 'Quartalsende'));
    assertTrue($hasHint($r, 'vertritt bei Verhinderung des Obmanns der Kassier'));
    assertTrue($hasHint($r, 'körperschaftsteuerpflichtig'));
});

test('Statuten: Mindestbeitrag nicht über Höchstbeitrag', function () use ($statuteValid, $hasError) {
    assertTrue($hasError(SR::check($statuteValid(['fee_min' => 200, 'fee_max' => 100])), 'Mindest-Mitgliedsbeitrag'));
});

test('Statuten: Normalisierung verwirft unbekannte Felder und Werte', function () {
    $a = SQ::normalize(['evil' => 'x', 'vat_mode' => 'bogus', 'prices_by' => 'jemand', 'invite_days' => 99, 'name' => "  A\x00B  "]);
    assertFalse(array_key_exists('evil', $a));
    assertSame('klein', $a['vat_mode']);
    assertSame('vorstand', $a['prices_by']);
    assertSame(14, $a['invite_days']);
    assertSame('A B', $a['name']);
});

test('Statuten: Vorbelegung nur für erlaubte Felder', function () {
    $a = SQ::withPrefill(['area' => 'lokal', 'grid_operator' => 'Musternetz GmbH', 'name' => 'nicht erlaubt']);
    assertSame('lokal', $a['area']);
    assertSame('Musternetz GmbH', $a['grid_operator']);
    assertSame('', $a['name']);
});
