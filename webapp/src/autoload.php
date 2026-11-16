<?php

declare(strict_types=1);

/**
 * Autoloading für den Namensraum App\ (PSR-4: App\Http\Controllers\X -> src/Http/Controllers/X.php).
 * Gleiche Zuordnung wie webapp/composer.json. Gibt es vendor/autoload.php (composer dump-autoload),
 * wird es verwendet; sonst übernimmt der kleine Loader unten. So laufen Tests und Container ohne
 * Composer-Installationsschritt (siehe docs/adr/0002-composer-psr4-ohne-framework.md).
 *
 * Die älteren Klassen ohne Namensraum (DB, Auth, ...) bindet public/index.php weiterhin direkt ein.
 */

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    return;
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
