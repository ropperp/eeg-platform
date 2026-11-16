<?php

declare(strict_types=1);

// Content-Security-Policy ohne 'unsafe-inline' für Skripte (functions.php: cspPolicy(), 17.11.2026).
// Statische Prüfung aller Templates: sie fängt jeden Rückfall in Inline-Skript/-Handler ab, BEVOR er
// im Browser als "wirkungsloser Knopf" auffällt.

function csp_view_files(): array
{
    $root = __DIR__ . '/../webapp';
    $files = array_merge([$root . '/public/index.php'], glob($root . '/routes/*.php'));
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src/views'));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) $files[] = $f->getPathname();
    }
    return $files;
}

test('CSP: Policy erlaubt Skripte nur aus eigener Domain oder mit Nonce, keine Inline-Handler', function () {
    $p = cspPolicy('abc123');
    assertContains("script-src 'self' 'nonce-abc123';", $p);
    assertContains("script-src-attr 'none'", $p);
    assertContains("object-src 'none'", $p);
    assertContains("frame-ancestors 'none'", $p);
    $scriptDirective = explode(';', explode("script-src ", $p)[1])[0];
    assertFalse(str_contains($scriptDirective, 'unsafe-inline'), 'script-src ohne unsafe-inline');
    assertFalse(str_contains($scriptDirective, 'unsafe-eval'), 'script-src ohne unsafe-eval');
});

test('CSP: Nonce ist 16 Zufallsbytes in Base64 und je Anfrage stabil', function () {
    $n = cspNonce();
    assertSame(24, strlen($n));
    assertSame(16, strlen(base64_decode($n, true)));
    assertSame($n, cspNonce());
});

test('CSP: kein Template enthält on*-Attribute (onclick, onchange, …)', function () {
    $bad = [];
    foreach (csp_view_files() as $f) {
        // nur Attribute in HTML-Tags: Leerraum + onXYZ= (data-onclick="…" ist erlaubt, davor steht ein "-")
        if (preg_match_all('/[\s"\'](on[a-z]+)\s*=\s*["\']/i', file_get_contents($f), $m)) {
            // JS-Zuweisungen wie r.onload = … haben keine Anführungszeichen direkt dahinter und passen nicht
            $bad[] = basename($f) . ': ' . implode(',', array_unique($m[1]));
        }
    }
    assertSame([], $bad, 'Inline-Handler gefunden');
});

test('CSP: keine javascript:-Adressen in Templates', function () {
    $bad = [];
    foreach (csp_view_files() as $f) {
        if (preg_match('/(href|src|action|formaction)\s*=\s*["\']\s*javascript:/i', file_get_contents($f))) $bad[] = basename($f);
    }
    assertSame([], $bad);
});

test('CSP: jedes Inline-<script> trägt einen Nonce (außer Datenblöcke application/ld+json)', function () {
    $bad = [];
    foreach (csp_view_files() as $f) {
        $src = file_get_contents($f);
        // login_glass.php hält seine Skripte in einem Nowdoc und setzt den Nonce zur Laufzeit per str_replace
        $nowdocNonce = str_contains($src, "str_replace('<script>', '<script nonce=");
        preg_match_all('/<script\b((?:<\?.*?\?>|[^>])*)>/is', $src, $m); // PHP-Tags gelten als ein Stück
        foreach ($m[1] as $attrs) {
            if ($nowdocNonce && trim($attrs) === '') continue;
            if (preg_match('/\bsrc\s*=/i', $attrs)) continue;               // externe Datei: durch 'self' erlaubt
            if (preg_match('/type\s*=\s*["\']application\/ld\+json/i', $attrs)) continue; // wird nicht ausgeführt
            if (!str_contains($attrs, 'nonce="<?= cspNonce() ?>"') && !str_contains($attrs, "nonce=\"' . cspNonce() . '\"")) {
                $bad[] = basename($f) . ' <script' . $attrs . '>';
            }
        }
    }
    assertSame([], $bad);
});

test('CSP: nginx setzt keine eigene CSP mehr (zwei Header würden geschnitten)', function () {
    $conf = file_get_contents(__DIR__ . '/../webapp/docker/nginx.conf');
    assertFalse((bool)preg_match('/^\s*add_header\s+Content-Security-Policy/mi', $conf), 'add_header Content-Security-Policy gefunden');
});

test('CSP: ui-actions.js wird von allen drei Layouts geladen', function () {
    foreach (['base', 'portal', 'auth_glass'] as $l) {
        assertContains('/assets/js/ui-actions.js', file_get_contents(__DIR__ . "/../webapp/src/views/layouts/{$l}.php"), $l);
    }
});
