<?php

declare(strict_types=1);

// Öffentliche Info-Seiten (webapp/src/content/marketing_pages.php): Längen für Suchergebnisse,
// Verweise, schema.org-Daten. Hintergrund: Sichtbarkeits-Check 17.11.2026.

require_once __DIR__ . '/../webapp/src/content/marketing_pages.php';

test('Info-Seiten: Titel max. 60, Beschreibung max. 160 Zeichen, Verweise und FAQ-Schlüssel gültig', function () {
    $pages = marketingPages();
    $faq = marketingFaq();
    foreach ($pages as $slug => $p) {
        assertTrue(mb_strlen($p['title']) <= 60, "$slug: Titel zu lang (" . mb_strlen($p['title']) . ')');
        assertTrue(mb_strlen($p['description']) <= 160, "$slug: Beschreibung zu lang (" . mb_strlen($p['description']) . ')');
        assertTrue(mb_strlen($p['description']) >= 70, "$slug: Beschreibung zu kurz");
        foreach ($p['faq'] as $k) assertTrue(isset($faq[$k]), "$slug: FAQ '$k' fehlt");
        foreach ($p['related'] as $r) assertTrue(isset($pages[$r]), "$slug: Verweis '$r' fehlt");
        assertTrue(count($p['sections']) >= 2, "$slug: zu wenig Inhalt");
    }
    foreach (array_keys(marketingFeatureTeasers()) as $t) assertTrue(isset($pages[$t]), "Teaser '$t' ohne Seite");
});

test('Info-Seiten: genug eigener Text pro Seite (mind. 150 Wörter)', function () {
    foreach (marketingPages() as $slug => $p) {
        $text = $p['lead'];
        foreach ($p['sections'] as $s) $text .= ' ' . implode(' ', $s['p'] ?? []) . ' ' . implode(' ', $s['list'] ?? []);
        $words = count(preg_split('/\s+/u', trim($text)) ?: []);
        assertTrue($words >= 150, "$slug: nur $words Wörter");
    }
});

test('schema.org: Plattform und Verein getrennt, Adresse aus der DB zerlegt, ohne DB Rückfall', function () {
    $ld = marketingOrgJsonLd('https://stromfueralle.at', ['name' => 'EEG Test', 'address' => 'Musterweg 1, 9560 Feldkirchen in Kärnten',
        'zvr' => '123', 'email' => 'x@example.invalid', 'phone' => '', 'members' => 3]);
    $types = array_column($ld['@graph'], '@type');
    assertSame(['SoftwareApplication', 'NGO', 'WebSite'], $types);
    assertSame('https://stromfueralle.at/#verein', $ld['@graph'][0]['provider']['@id']);
    $v = $ld['@graph'][1];
    assertSame(['Musterweg 1', '9560', 'Feldkirchen in Kärnten'], [$v['address']['streetAddress'], $v['address']['postalCode'], $v['address']['addressLocality']]);
    assertSame('123', $v['identifier']['value']);
    assertFalse(isset($v['telephone']), 'leeres Telefon entfällt');
    $fallback = marketingOrgJsonLd('https://stromfueralle.at', null)['@graph'][1];
    assertSame('Feldkirchen in Kärnten', $fallback['address']['addressLocality']);
});

test('schema.org: FAQPage nur mit vorhandenen Fragen, JSON sicher eingebettet', function () {
    $f = marketingFaqJsonLd(['kosten', 'gibtsnicht']);
    assertSame(1, count($f['mainEntity']));
    assertContains('auf Anfrage', $f['mainEntity'][0]['acceptedAnswer']['text']);
    $s = jsonLdScript(['x' => '</script><script>alert(1)</script>']);
    assertFalse(str_contains(substr($s, 35, -9), '</script>'), 'kein schließendes script-Tag im JSON');
});
