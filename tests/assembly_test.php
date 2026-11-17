<?php

declare(strict_types=1);

// Protokolle von Mitgliederversammlungen: Eingabeprüfung und LaTeX-Aufbereitung (ohne DB).

use App\Domain\Assembly\AssemblyException;
use App\Domain\Assembly\AssemblyService;
use App\Infrastructure\Pdf\LatexAssemblyPdfRenderer;

$asmThrows = function (callable $fn, string $needle): void {
    try {
        $fn();
    } catch (AssemblyException $e) {
        assertContains($needle, $e->getMessage());
        return;
    }
    throw new Exception('AssemblyException erwartet: ' . $needle);
};

test('Protokoll-Kopf: Datum Pflicht, Art/Titel mit Standardwerten, Zahlen und Uhrzeiten geprüft', function () use ($asmThrows) {
    $h = AssemblyService::header(['held_on' => '2026-11-14', 'kind' => 'quatsch', 'attendees_count' => '23', 'time_from' => '18:30', 'quorum' => '1', 'location' => '  Gemeindesaal ']);
    assertSame('ordentlich', $h['kind']);
    assertSame('Ordentliche Mitgliederversammlung', $h['title']);
    assertSame(23, $h['attendees_count']);
    assertSame(null, $h['voting_count']);
    assertSame('18:30', $h['time_from']);
    assertSame(true, $h['quorum']);
    assertSame('Gemeindesaal', $h['location']);
    assertSame(null, AssemblyService::header(['held_on' => '2026-11-14'])['quorum']);
    $asmThrows(fn() => AssemblyService::header(['held_on' => '']), 'Datum');
    $asmThrows(fn() => AssemblyService::header(['held_on' => '2026-02-30']), 'Datum');
    $asmThrows(fn() => AssemblyService::header(['held_on' => '2026-11-14', 'attendees_count' => '-3']), 'ganze Zahl');
    $asmThrows(fn() => AssemblyService::header(['held_on' => '2026-11-14', 'time_to' => '25:00']), 'HH:MM');
});

test('Tagesordnungspunkt: Titel Pflicht, leere Felder werden NULL', function () use ($asmThrows) {
    $i = AssemblyService::item(['title' => ' Bericht des Obmanns ', 'body' => '', 'votes_yes' => '12']);
    assertSame('Bericht des Obmanns', $i['title']);
    assertSame(null, $i['body']);
    assertSame(12, $i['votes_yes']);
    $asmThrows(fn() => AssemblyService::item(['title' => '  ']), 'Titel');
});

test('Unterschrift: nur echtes PNG als data-URI wird angenommen', function () use ($asmThrows) {
    $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 20));
    assertSame($png, AssemblyService::signature($png));
    $asmThrows(fn() => AssemblyService::signature(''), 'unterschreiben');
    $asmThrows(fn() => AssemblyService::signature('data:image/png;base64,' . base64_encode('<svg>')), 'nicht gelesen');
    $asmThrows(fn() => AssemblyService::signature('data:image/svg+xml;base64,PHN2Zz4='), 'unterschreiben');
});

test('Protokoll-PDF: Freitext wird escaped, Absätze bleiben erhalten', function () {
    assertSame('50\\% \\& mehr\\newline zweite Zeile' . "\n\n" . 'neuer Absatz', LatexAssemblyPdfRenderer::paragraphs("50% & mehr\nzweite Zeile\n\n\nneuer Absatz"));
    assertSame('a\\newline\\newline b', LatexAssemblyPdfRenderer::paragraphs("a\n\nb", true));
    assertSame('\\textbackslash{}input\\{x\\}', LatexAssemblyPdfRenderer::paragraphs('\\input{x}'));
});

test('Protokoll-PDF: Variablen mit Kopfdaten, Tagesordnung, Abstimmung und Unterschriften', function () {
    $assets = [];
    $vars = LatexAssemblyPdfRenderer::vars(
        ['title' => 'Ordentliche Mitgliederversammlung', 'kind' => 'ordentlich', 'held_on' => '2026-11-14', 'time_from' => '18:00', 'time_to' => '20:00',
         'location' => 'Saal', 'attendees_count' => 23, 'voting_count' => null, 'chair_name' => null, 'minutes_taker' => null, 'quorum' => 't', 'notes' => null],
        [['title' => 'Kassabericht & Entlastung', 'body' => 'Bericht', 'resolution' => 'Entlastet', 'votes_yes' => 20, 'votes_no' => 0, 'votes_abstain' => 3]],
        [['name' => 'Erika Muster', 'role_label' => 'Obfrau', 'signature_image' => 'data:image/png;base64,AAA', 'signed_at' => '2026-11-15 10:00:00+01']],
        ['name' => 'EEG Test', 'address' => 'Weg 1, 1000 Ort', 'zvr_number' => '123'],
        false,
        $assets
    );
    assertSame('14.11.2026', $vars['DATUM']);
    assertContains('18:00 bis 20:00 Uhr', $vars['RAW_KOPFDATEN']);
    assertContains('Beschlussfähigkeit & gegeben', $vars['RAW_KOPFDATEN']);
    assertFalse(str_contains($vars['RAW_KOPFDATEN'], 'stimmberechtigt'), 'leere Felder entfallen');
    assertContains('\\item Kassabericht \\& Entlastung', $vars['RAW_TAGESORDNUNG']);
    assertContains('20 dafür, 0 dagegen, 3 Enthaltungen', $vars['RAW_PUNKTE']);
    assertContains('unterschrift_1.png', $vars['RAW_UNTERSCHRIFTEN']);
    assertContains('unterschrieben am 15.11.2026', $vars['RAW_UNTERSCHRIFTEN']);
    assertSame('data:image/png;base64,AAA', $assets['unterschrift_1.png']);
    assertSame('', $vars['RAW_ENTWURF']);

    $draftAssets = [];
    $draft = LatexAssemblyPdfRenderer::vars(['title' => 'X', 'kind' => 'ordentlich', 'held_on' => '2026-11-14'], [], [['name' => 'A', 'role_label' => 'B', 'signature_image' => 'data:x', 'signed_at' => null]], [], true, $draftAssets);
    assertContains('ENTWURF', $draft['RAW_ENTWURF']);
    assertSame([], $draftAssets, 'Entwurf ohne Unterschriftsbilder');
});
