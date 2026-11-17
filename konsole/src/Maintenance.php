<?php

declare(strict_types=1);

namespace Konsole;

/**
 * Wartungsmodus. Zustand in maintenance.json; solange aktiv, liegt maintenance.yml im
 * Traefik-Konfigordner: ein Router mit hoher Priorität schickt alle öffentlichen Hosts an die
 * Wartungsseite dieser Konsole -- unabhängig davon, ob die Webapp läuft. Traefik übernimmt die
 * Datei ohne Neustart (providers.file.watch).
 */
final class Maintenance
{
    public const MAX_MESSAGE = 500;

    public function __construct(
        private readonly string $stateFile,
        private readonly string $traefikFile,
        private readonly Config $config,
    ) {
    }

    /** @return array{active: bool, message: string, until: string, since: string} */
    public function state(): array
    {
        $raw = @file_get_contents($this->stateFile);
        $s = $raw !== false ? json_decode($raw, true) : null;
        return [
            'active'  => (bool) ($s['active'] ?? false),
            'message' => (string) ($s['message'] ?? ''),
            'until'   => (string) ($s['until'] ?? ''),
            'since'   => (string) ($s['since'] ?? ''),
        ];
    }

    public function enable(string $message, string $until): void
    {
        $message = mb_substr(trim(str_replace("\r\n", "\n", $message)), 0, self::MAX_MESSAGE);
        $until = trim($until);
        if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $until)) {
            throw new \InvalidArgumentException('Ungültige Zeitangabe.');
        }
        $this->writeState(['active' => true, 'message' => $message, 'until' => $until, 'since' => date('c')]);
        $this->writeAtomic($this->traefikFile, $this->traefikYaml());
    }

    public function disable(): void
    {
        @unlink($this->traefikFile);
        $s = $this->state();
        $this->writeState(['active' => false, 'message' => $s['message'], 'until' => '', 'since' => '']);
    }

    /** Traefik-Konfiguration: alle öffentlichen Hosts → Konsole (die zeigt dort die Wartungsseite). */
    public function traefikYaml(): string
    {
        $rule = implode(' || ', array_map(fn($h) => 'Host(`' . $h . '`)', $this->config->publicHosts()));
        return "# Von der Steuerzentrale (konsole) geschrieben -- Wartungsmodus aktiv. Nicht von Hand bearbeiten.\n"
            . "http:\n"
            . "  routers:\n"
            . "    wartung:\n"
            . "      rule: \"{$rule}\"\n"
            . "      entryPoints: [\"web\"]\n"
            . "      service: konsole-wartung\n"
            . "      priority: 100000\n"
            . "  services:\n"
            . "    konsole-wartung:\n"
            . "      loadBalancer:\n"
            . "        servers:\n"
            . "          - url: \"http://konsole:8090\"\n";
    }

    /** Sekunden bis zum voraussichtlichen Ende (für Retry-After), sonst 1800. */
    public function retryAfter(?int $now = null): int
    {
        $until = $this->state()['until'];
        $ts = $until !== '' ? strtotime($until) : false;
        $diff = $ts ? $ts - ($now ?? time()) : 0;
        return $diff > 60 ? min($diff, 86400) : 1800;
    }

    /** @param array<string, mixed> $s */
    private function writeState(array $s): void
    {
        $this->writeAtomic($this->stateFile, (string) json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function writeAtomic(string $file, string $content): void
    {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $file)) {
            throw new \RuntimeException('Datei nicht schreibbar: ' . $file);
        }
    }
}
