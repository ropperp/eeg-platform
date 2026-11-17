<?php

declare(strict_types=1);

namespace Konsole;

/** Jede Anmeldung und Aktion als JSON-Zeile in /data/audit.log (unabhängig von der Datenbank). */
final class AuditLog
{
    public function __construct(private readonly string $file)
    {
    }

    public function write(string $user, string $action, string $target, string $ip): void
    {
        $line = json_encode(['zeit' => date('c'), 'wer' => $user, 'aktion' => $action, 'ziel' => $target, 'ip' => $ip], JSON_UNESCAPED_UNICODE);
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string, string>> neueste zuerst */
    public function recent(int $n): array
    {
        $lines = @file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_reverse(array_slice($lines, -$n)) as $l) {
            $row = json_decode($l, true);
            if (is_array($row)) {
                $out[] = array_map('strval', $row);
            }
        }
        return $out;
    }
}
