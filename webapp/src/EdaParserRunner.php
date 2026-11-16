<?php

declare(strict_types=1);

use App\Support\Services;

/**
 * Synchroner EDA-Import für Hintergrundprozesse (Postfach-Abruf, EdaAutoImporter.php).
 *
 * Seit 16.11.2026 läuft der Parser nicht mehr als Unterprozess der Webapp, sondern im Container
 * eda-worker (docs/adr/0005-eda-import-als-worker.md). Diese Klasse legt einen Job an, wartet auf
 * das Ergebnis und liefert es im bisherigen Format: stdout (JSON-Ergebnis) und stderr (Logzeilen)
 * getrennt, damit json_decode() auf stdout funktioniert (Vorfall 06.08.2026: zusammengelegte
 * Streams ließen erfolgreiche Importe als "Parser-Fehler" erscheinen).
 *
 * Portal-Uploads warten NICHT, sondern zeigen den Job-Status an (routes/portal.php).
 */
class EdaParserRunner
{
    /** @return array{stdout:string, stderr:string, exit_code:int} Monatsexport (parser.py) */
    public static function run(string $filePath, string $communitySlug, ?string $userId = null): array
    {
        return self::runJob('monthly', $filePath, $communitySlug, $userId);
    }

    /** @return array{stdout:string, stderr:string, exit_code:int} Viertelstundenwerte (parser_interval.py) */
    public static function runInterval(string $filePath, string $communitySlug, ?string $userId = null): array
    {
        return self::runJob('interval', $filePath, $communitySlug, $userId);
    }

    /** Für die Fehleranzeige: stderr (die eigentliche Fehlerursache) vor stdout, zusammen. */
    public static function diagnostics(array $runResult): string
    {
        $combined = trim($runResult['stderr'] . "\n" . $runResult['stdout']);
        return $combined !== '' ? $combined : 'Keine Ausgabe';
    }

    /** @return array{stdout:string, stderr:string, exit_code:int} */
    private static function runJob(string $kind, string $filePath, string $communitySlug, ?string $userId): array
    {
        $community = DB::fetchOne('SELECT id FROM communities WHERE slug = ?', [$communitySlug]);
        if (!$community) {
            return ['stdout' => '', 'stderr' => "Community '{$communitySlug}' nicht gefunden", 'exit_code' => 1];
        }
        DB::setCommunity($community['id']);
        return Services::edaImportJobs()->runAndWait($community['id'], $kind, $filePath, $userId);
    }
}
