<?php

declare(strict_types=1);

/**
 * Teil des Container-Healthchecks (docker-compose.yml, Dienst webapp).
 *
 * Exit 1 nur bei einem eindeutigen Befund: In Produktion fehlt die eingeschränkte Rolle oder sie
 * ist Superuser, hat BYPASSRLS oder besitzt RLS-Tabellen. Dann greift die Mandantentrennung nicht,
 * und scripts/health_monitor.sh schlägt Alarm.
 *
 * Ist die Datenbank nur nicht erreichbar, endet das Skript mit 0: diesen Fall meldet bereits der
 * Healthcheck von timescaledb, ein Neustart der Webapp würde nichts verbessern.
 */

require __DIR__ . '/../src/DB.php';

if (!DB::isProduction()) {
    exit(0);
}

try {
    DB::credentials();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    $problems = DB::runtimeRoleProblems();
} catch (PDOException $e) {
    // Falsches Passwort/unbekannte Rolle ist ein Konfigurationsfehler, kein DB-Ausfall.
    if (preg_match('/authentication failed|role .* does not exist/i', $e->getMessage())) {
        fwrite(STDERR, "Anmeldung der Laufzeit-Rolle an der Datenbank fehlgeschlagen.\n");
        exit(1);
    }
    exit(0);
}

if ($problems) {
    fwrite(STDERR, "Mandantentrennung unwirksam: " . implode('; ', $problems) . "\n");
    exit(1);
}
exit(0);
