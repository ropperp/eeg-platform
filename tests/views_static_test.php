<?php

declare(strict_types=1);

// Statische Prüfung der Templates: In Heredoc/Nowdoc-Blöcken wird PHP nicht ausgeführt. Ein
// PHP-Ausgabe-Tag darin landet wörtlich im HTML -- so lud die Glas-Login-Seite passkey.js nie und
// "Mit Passkey anmelden" fehlte (Vorfall 17.11.2026, eingeschleppt durch eine automatische Ersetzung).

test('Templates: keine PHP-Tags in Heredoc/Nowdoc-Blöcken', function () {
    $files = array_merge(
        glob(__DIR__ . '/../webapp/src/views/*/*.php') ?: [],
        glob(__DIR__ . '/../webapp/src/views/*.php') ?: [],
        glob(__DIR__ . '/../webapp/routes/*.php') ?: [],
        glob(__DIR__ . '/../konsole/src/views/*.php') ?: []
    );
    assertTrue(count($files) > 20, 'Templates gefunden');
    $open = '<' . '?';
    $bad = [];
    foreach ($files as $f) {
        $s = (string) file_get_contents($f);
        if (preg_match_all("/<<<'?(\\w+)'?\\n(.*?)\\n\\s*\\1\\b/s", $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                if (str_contains($hit[2][0], $open . '=') || str_contains($hit[2][0], $open . 'php')) {
                    $bad[] = basename($f) . ':' . (substr_count(substr($s, 0, $hit[0][1]), "\n") + 1);
                }
            }
        }
    }
    assertSame([], $bad, 'PHP-Tag in Heredoc/Nowdoc: ' . implode(', ', $bad));
});
