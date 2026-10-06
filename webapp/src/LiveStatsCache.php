<?php

declare(strict_types=1);

/**
 * Kurzzeit-Cache für die öffentliche Live-Anzeige (/api/live/:slug). Patrick, 06.10.2026:
 * "das dauert 5 bis 10 Sekunden [...] Das werden wir irgendwie irgendwo zwischenspeichern
 * [...] und von mir aus immer die Werte aktualisieren."
 *
 * Deckt vor allem den Fall ab, dass dieselbe EEG gerade von mehreren Besuchern gleichzeitig
 * angesehen wird (z.B. Messe-/Präsentationsbetrieb, siehe CLAUDE.md "Messe-Demo") -- die
 * zweite, dritte, ... Anfrage innerhalb des kurzen TTL-Fensters bekommt die Werte direkt aus
 * Redis statt erneut alle Aggregat-Queries laufen zu lassen. TTL (3s) liegt bewusst UNTER dem
 * 5-Sekunden-Poll-Intervall des Frontends (live.php) -- die Werte sind dadurch nie merklich
 * veraltet, wirken aber für überlappende Anfragen praktisch wie "immer aktuell".
 *
 * Löst für sich allein NICHT automatisch jede Langsamkeit: die eigentliche, strukturelle
 * Ursache der 5-10s (unbeschränkter DISTINCT-ON-Scan über die gesamte Messhistorie in der
 * "heute"-Berechnung) ist direkt in der Query selbst behoben (siehe /api/live/:slug in
 * index.php) -- dieser Cache ist eine zusätzliche Entlastung für den Fall gleichzeitiger
 * Zugriffe, kein Ersatz dafür.
 *
 * Fail-open bei Redis-Ausfall, gleiches Muster wie RateLimiter.php: schlägt Redis fehl, wird
 * einfach jedes Mal frisch aus der DB berechnet -- langsamer, aber korrekt, kein Totalausfall
 * der Live-Anzeige wegen eines reinen Performance-Caches.
 */
class LiveStatsCache
{
    private static ?Redis $redis = null;
    private static bool $unavailable = false;

    private const TTL_SECONDS = 3;

    private static function client(): ?Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }
        if (self::$unavailable) {
            return null;
        }
        try {
            $redis = new Redis();
            $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379), 1.0);
            $password = getenv('REDIS_PASSWORD') ?: '';
            if ($password !== '') {
                $redis->auth($password);
            }
            self::$redis = $redis;
            return $redis;
        } catch (\Throwable $e) {
            self::$unavailable = true;
            error_log('LiveStatsCache: Redis nicht erreichbar -- Cache deaktiviert (' . $e->getMessage() . ')');
            return null;
        }
    }

    public static function get(string $communityId): ?array
    {
        $redis = self::client();
        if ($redis === null) return null;
        try {
            $json = $redis->get('livestats:' . $communityId);
            if ($json === false) return null;
            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null; // fail open -- einfach frisch berechnen
        }
    }

    public static function set(string $communityId, array $data): void
    {
        $redis = self::client();
        if ($redis === null) return;
        try {
            $redis->setex('livestats:' . $communityId, self::TTL_SECONDS, json_encode($data));
        } catch (\Throwable $e) {
            // Ignorieren -- der nächste Request berechnet einfach wieder frisch.
        }
    }
}
