<?php

declare(strict_types=1);

// Einstieg der Steuerzentrale (Router-Skript für den eingebauten PHP-Server, siehe konsole/Dockerfile).
date_default_timezone_set('Europe/Vienna');

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Konsole\\')) {
        require __DIR__ . '/../src/' . substr($class, 8) . '.php';
    }
});
require __DIR__ . '/../src/WebAuthn.php'; // aus webapp/src beim Bauen kopiert (gleiche Prüf-Logik wie das Portal)
require __DIR__ . '/../src/Jwt.php';      // aus webapp/src/Infrastructure/Auth kopiert (Token-Prüfung wie das Portal)

use Konsole\App;
use Konsole\AuditLog;
use Konsole\Config;
use Konsole\Docker;
use Konsole\Maintenance;
use Konsole\PasskeyStore;
use Konsole\Sso;

try {
    $config = Config::fromEnv();
    $app = new App(
        $config,
        new PasskeyStore($config->dataDir . '/passkeys.json', PasskeyStore::dbLoader($config)),
        new Docker($config->dockerSocket, $config->services),
        new Maintenance($config->dataDir . '/maintenance.json', $config->traefikDir . '/maintenance.yml', $config),
        new AuditLog($config->dataDir . '/audit.log'),
        new Sso($config),
    );
    $app->handle($_SERVER, $_POST, $_COOKIE, (string) file_get_contents('php://input'))->send(!$config->dev, $config->kcOrigin());
} catch (\Throwable $e) {
    error_log('[konsole] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Interner Fehler der Steuerzentrale.';
}
