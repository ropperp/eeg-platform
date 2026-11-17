<?php

// Nur für PHPStan: Konstanten, die webapp/public/index.php zur Laufzeit definiert.
define('ROOT', __DIR__ . '/webapp');
require __DIR__ . '/webapp/src/autoload.php';
// Steuerzentrale (konsole/): eigener Namensraum, eigener Container.
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Konsole\\')) {
        require __DIR__ . '/konsole/src/' . substr($class, 8) . '.php';
    }
});
