<?php

declare(strict_types=1);

namespace Konsole;

/**
 * Minimaler Docker-Client über den Unix-Socket. Kann bewusst NUR: Container der festen Liste
 * anzeigen, ihre letzten Logzeilen lesen und sie neu starten. Kein Anlegen, kein exec, keine
 * frei wählbaren Pfade -- der Socket selbst ist so mächtig wie root (docs/adr/0006-steuerzentrale.md).
 */
final class Docker
{
    /** @param list<string> $allowed */
    public function __construct(private readonly string $socket, private readonly array $allowed)
    {
    }

    public function isAllowed(string $name): bool
    {
        return in_array($name, $this->allowed, true);
    }

    /** @return list<array{name: string, image: string, state: string, status: string}> in der Reihenfolge der Liste */
    public function list(): array
    {
        $raw = $this->request('GET', '/containers/json?all=1');
        $data = json_decode($raw, true);
        $byName = [];
        foreach (is_array($data) ? $data : [] as $c) {
            foreach ((array) ($c['Names'] ?? []) as $n) {
                $n = ltrim((string) $n, '/');
                if ($this->isAllowed($n)) {
                    $byName[$n] = ['name' => $n, 'image' => (string) ($c['Image'] ?? ''), 'state' => (string) ($c['State'] ?? ''), 'status' => (string) ($c['Status'] ?? '')];
                }
            }
        }
        $out = [];
        foreach ($this->allowed as $n) {
            $out[] = $byName[$n] ?? ['name' => $n, 'image' => '', 'state' => 'fehlt', 'status' => 'Container nicht gefunden'];
        }
        return $out;
    }

    public function restart(string $name): void
    {
        if (!$this->isAllowed($name)) {
            throw new \InvalidArgumentException('Dienst nicht erlaubt');
        }
        $this->request('POST', '/containers/' . rawurlencode($name) . '/restart?t=10', 204);
    }

    public function logs(string $name, int $tail = 100): string
    {
        if (!$this->isAllowed($name)) {
            throw new \InvalidArgumentException('Dienst nicht erlaubt');
        }
        $tail = max(1, min(500, $tail));
        return self::demux($this->request('GET', '/containers/' . rawurlencode($name) . '/logs?stdout=1&stderr=1&timestamps=1&tail=' . $tail));
    }

    /**
     * Docker liefert Logs von Containern ohne TTY im Multiplex-Format: je Block 8 Byte Kopf
     * (Stream-Typ, 3 Null-Bytes, Länge als uint32 Big Endian), dann die Daten.
     */
    public static function demux(string $raw): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($raw);
        if ($len >= 8 && !in_array(ord($raw[0]), [0, 1, 2], true)) {
            return $raw; // Container mit TTY: Klartext
        }
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($raw, $pos + 4, 4))[1];
            $out .= substr($raw, $pos + 8, $size);
            $pos += 8 + $size;
        }
        return $out;
    }

    private function request(string $method, string $path, int $expect = 200): string
    {
        $ch = curl_init('http://localhost' . $path);
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 30,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($body === false || $code !== $expect) {
            throw new \RuntimeException('Docker-Anfrage fehlgeschlagen (' . ($code ?: $err) . ')');
        }
        return (string) $body;
    }
}
