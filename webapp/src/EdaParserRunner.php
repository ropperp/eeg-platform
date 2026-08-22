<?php

declare(strict_types=1);

/**
 * Führt eda-parser/parser.py aus und hält stdout (JSON-Ergebnis bei Erfolg) und stderr
 * (INFO/WARNING-Logzeilen des Parsers, siehe logging.basicConfig() dort -- Default-Handler
 * schreibt auf stderr) sauber getrennt.
 *
 * Vorher liefen beide Streams über ein simples "2>&1" in EINEN String zusammen (siehe
 * public/index.php vor dieser Änderung). Dadurch schlug json_decode() auf dem kombinierten
 * String IMMER fehl, sobald der Parser mindestens eine Logzeile ausgegeben hatte -- was bei
 * JEDEM Lauf der Fall ist (allein schon die "Lese XLSX"-Zeile). Ergebnis: die Plattform zeigte
 * "Parser-Fehler" an, selbst wenn der Import tatsächlich vollständig erfolgreich in der
 * Datenbank gelandet war (Patrick, 06.08.2026 -- ein als Fehler gemeldeter Import hatte
 * tatsächlich 10 Datensätze korrekt importiert). proc_open() mit getrennten Pipes behebt das.
 */
class EdaParserRunner
{
    /** @return array{stdout:string, stderr:string, exit_code:int} */
    public static function run(string $filePath, string $communitySlug, ?string $userId = null): array
    {
        $cmd = 'python3 /var/www/html/eda-parser/parser.py --file ' . escapeshellarg($filePath)
             . ' --community ' . escapeshellarg($communitySlug)
             . ($userId !== null && $userId !== '' ? ' --user-id ' . escapeshellarg($userId) : '');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Parser-Prozess konnte nicht gestartet werden.', 'exit_code' => -1];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => (string)$stdout, 'stderr' => (string)$stderr, 'exit_code' => $exitCode];
    }

    /** Für die Fehleranzeige: stderr (die eigentliche Fehlerursache) vor stdout, zusammen. */
    public static function diagnostics(array $runResult): string
    {
        $combined = trim($runResult['stderr'] . "\n" . $runResult['stdout']);
        return $combined !== '' ? $combined : 'Keine Ausgabe';
    }

    /** Wie run(), aber für den zweiten Export-Typ (Viertelstundenwerte, "Energiedaten"-Sheet,
     *  siehe eda-parser/parser_interval.py) -- eigenes Skript, komplett anderes Dateiformat. */
    public static function runInterval(string $filePath, string $communitySlug, ?string $userId = null): array
    {
        $cmd = 'python3 /var/www/html/eda-parser/parser_interval.py --file ' . escapeshellarg($filePath)
             . ' --community ' . escapeshellarg($communitySlug)
             . ($userId !== null && $userId !== '' ? ' --user-id ' . escapeshellarg($userId) : '');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Parser-Prozess konnte nicht gestartet werden.', 'exit_code' => -1];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => (string)$stdout, 'stderr' => (string)$stderr, 'exit_code' => $exitCode];
    }
}
