<?php

declare(strict_types=1);

/**
 * Integrationstests gegen eine echte PostgreSQL/TimescaleDB (Schema + alle Migrationen +
 * Rollen aus scripts/sql/runtime_roles.sql). Vorbereitung: tests/integration/prepare_db.sh.
 * Lokal: `make test-integration`, in der CI: Job "integration" in .github/workflows/ci.yml.
 *
 * Umgebung: TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_OWNER/_PASSWORD (Schema-Besitzer),
 * TEST_APP_USER/_PASSWORD, TEST_WORKER_USER/_PASSWORD.
 */

require __DIR__ . '/../lib.php';
require __DIR__ . '/../../webapp/src/DB.php';

if (!getenv('TEST_DB_HOST')) {
    fwrite(STDERR, "TEST_DB_HOST nicht gesetzt -- Integrationstests übersprungen (siehe tests/integration/run.php).\n");
    exit(1);
}

function testPdo(string $userVar): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('TEST_DB_HOST'),
        getenv('TEST_DB_PORT') ?: '5432',
        getenv('TEST_DB_NAME') ?: 'eeg_platform'
    );
    return new PDO($dsn, (string) getenv($userVar), (string) getenv($userVar . '_PASSWORD'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo "\n" . basename($file) . "\n";
    require $file;
}

testSummaryAndExit();
