<?php

declare(strict_types=1);

/** Gemeinsame Mini-Assertions für tests/run.php und tests/integration/run.php. */

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        $GLOBALS['__t']['fail']++;
        echo "  \033[31m✗ {$name}\033[0m\n      " . $e->getMessage() . "\n";
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(($msg ? $msg . ' -- ' : '')
            . 'erwartet ' . var_export($expected, true) . ', war ' . var_export($actual, true));
    }
}
function assertTrue($v, string $msg = ''): void { assertSame(true, $v, $msg ?: 'sollte true sein'); }
function assertFalse($v, string $msg = ''): void { assertSame(false, $v, $msg ?: 'sollte false sein'); }
function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \Exception(($msg ? $msg . ' -- ' : '') . "'{$needle}' nicht enthalten in: {$haystack}");
    }
}

function testSummaryAndExit(): never
{
    $t = $GLOBALS['__t'];
    $total = $t['pass'] + $t['fail'];
    echo "\n" . ($t['fail'] === 0
        ? "\033[32m✓ Alle {$total} Tests bestanden\033[0m\n"
        : "\033[31m✗ {$t['fail']} von {$total} Tests fehlgeschlagen\033[0m\n");
    exit($t['fail'] === 0 ? 0 : 1);
}
