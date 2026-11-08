<?php

declare(strict_types=1);

// ─── sanitizeLegalHtml(): Editor-HTML der EEG-Rechtsdokumente ───────────────────────────────

test('sanitizeLegalHtml: erlaubte Struktur bleibt erhalten', function () {
    $in = '<h2>§ 1 Zweck</h2><p>Text mit <strong>fett</strong> und <em>kursiv</em>.</p><ol><li>eins</li><li>zwei</li></ol>';
    assertSame($in, sanitizeLegalHtml($in));
});

test('sanitizeLegalHtml: Skripte, Stile, Event-Handler und gefährliche Links werden entfernt', function () {
    $out = sanitizeLegalHtml('<p onclick="x()" style="color:red">a</p><script>alert(1)</script><style>p{}</style>'
        . '<iframe src="https://x"></iframe><img src=x onerror=alert(1)><p><a href="javascript:alert(1)">j</a>'
        . '<a href="data:text/html,x">d</a><a href="//evil.example">p</a></p>');
    assertSame('<p>a</p><p>jdp</p>', $out);
});

test('sanitizeLegalHtml: sichere Links bleiben (https, mailto, intern, Anker), Zusatzattribute fallen weg', function () {
    $out = sanitizeLegalHtml('<p><a href="https://kaerntennetz.at" target="_blank" class="x">a</a> '
        . '<a href="mailto:office@example.org">b</a> <a href="/rc108175/agb">c</a> <a href="#p7">d</a></p>');
    assertSame('<p><a href="https://kaerntennetz.at">a</a> <a href="mailto:office@example.org">b</a> '
        . '<a href="/rc108175/agb">c</a> <a href="#p7">d</a></p>', $out);
});

test('sanitizeLegalHtml: aus Word eingefügter Text wird sauber', function () {
    $word = '<!--[if gte mso 9]><xml><w:WordDocument></w:WordDocument></xml><![endif]-->'
        . '<h1 class="MsoTitle">Statuten</h1>'
        . '<p class="MsoNormal" style="margin:0cm"><span lang="DE-AT" style="font-family:Calibri">Der Verein führt den Namen „EEG Süd“.</span><o:p></o:p></p>'
        . '<p class="MsoNormal">&nbsp;</p>'
        . '<p class="MsoListParagraph"><b><span style="x">Wichtig</span></b> <i>Hinweis</i></p>';
    assertSame('<h2>Statuten</h2><p>Der Verein führt den Namen „EEG Süd“.</p><p><strong>Wichtig</strong> <em>Hinweis</em></p>',
        sanitizeLegalHtml($word));
});

test('sanitizeLegalHtml: leere Listenpunkte/Absätze weg, Tabellen ohne Attribute bleiben', function () {
    assertSame('<ul><li>x</li></ul><table><tr><td>z</td></tr></table>',
        sanitizeLegalHtml('<ul><li>x</li><li> </li></ul><p></p><table border="1"><tr><td style="x">z</td></tr></table>'));
});

test('sanitizeLegalHtml: leere Eingabe -> leer, reiner Text bleibt Text', function () {
    assertSame('', sanitizeLegalHtml('   '));
    assertSame('nur Text &amp; mehr', sanitizeLegalHtml('nur Text & mehr'));
});

test('datumLang: Wiener Ortszeit, österreichische Monatsnamen', function () {
    assertSame('8. November 2026', datumLang('2026-11-08 10:00:00+00'));
    assertSame('1. Jänner 2027', datumLang('2026-12-31 23:30:00+00'), 'Silvester 23:30 UTC ist in Wien schon Neujahr');
    assertSame('', datumLang(null));
});
