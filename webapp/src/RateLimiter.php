<?php

declare(strict_types=1);

/**
 * Brute-Force-Schutz für Login und 2FA-Codeeingabe über Redis-Zähler (INCR + EXPIRE,
 * Fixed-Window). OWASP-Audit 13.08.2026 -- vorher gab es keinerlei Begrenzung, ein Skript
 * konnte beliebig viele Passwörter/TOTP-Codes pro Sekunde durchprobieren.
 *
 * Bewusst zwei unabhängige Zähler beim Login (siehe isLoginBlocked()): einer pro IP (bremst
 * Credential-Stuffing gegen viele Konten von einer Quelle), einer pro E-Mail (bremst
 * Brute-Force gegen ein einzelnes Konto von vielen Quellen). Der E-Mail-Zähler ermöglicht
 * theoretisch, dass jemand ein fremdes Konto 15 Minuten lang aussperrt, indem er absichtlich
 * falsche Passwörter eingibt -- ein bekannter, von OWASP akzeptierter Kompromiss (Kontosperre
 * schlägt reines Brute-Force-Risiko).
 *
 * Fail-open bei Redis-Ausfall: Redis ist bereits fürs Session-Backend ein hartes Erfordernis
 * (siehe Auth::start()) -- fällt Redis aus, ist Login ohnehin nicht mehr möglich, das
 * Rate-Limiting selbst führt also KEINEN neuen Single Point of Failure ein. Trotzdem defensiv
 * try/catch: schlägt NUR der Zähler fehl (z.B. kurzer Netz-Hänger), lässt der Login-Versuch
 * lieber durch, statt die ganze Plattform lahmzulegen.
 */
class RateLimiter
{
    private static ?Redis $redis = null;
    private static bool $unavailable = false;

    private const LOGIN_MAX_PER_EMAIL = 5;
    private const LOGIN_MAX_PER_IP = 20;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 Minuten

    private const TOTP_MAX = 5;
    private const TOTP_WINDOW_SECONDS = 900;

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
            error_log('RateLimiter: Redis nicht erreichbar -- Rate-Limiting deaktiviert (' . $e->getMessage() . ')');
            return null;
        }
    }

    private static function increment(string $key, int $windowSeconds): void
    {
        $redis = self::client();
        if ($redis === null) return;
        try {
            $count = $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $windowSeconds);
            }
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis-Fehler beim Zählen -- ignoriert (' . $e->getMessage() . ')');
        }
    }

    private static function count(string $key): int
    {
        $redis = self::client();
        if ($redis === null) return 0; // fail open
        try {
            $val = $redis->get($key);
            return $val === false ? 0 : (int)$val;
        } catch (\Throwable $e) {
            return 0; // fail open
        }
    }

    private static function reset(string $key): void
    {
        $redis = self::client();
        if ($redis === null) return;
        try {
            $redis->del($key);
        } catch (\Throwable $e) {
            // Ignorieren -- der Zähler läuft ohnehin per EXPIRE ab.
        }
    }

    private static function clientIp(): string
    {
        // Kein Trusted-Proxy-Header (X-Forwarded-For) -- der wird traefik-seitig nicht
        // konsequent gesetzt/validiert, ein Client könnte ihn sonst selbst fälschen, um den
        // IP-Zähler zu umgehen. REMOTE_ADDR ist innerhalb des Docker-Netzes ohnehin die
        // tatsächliche Verbindung von Traefik.
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /** true = Login-Versuch aktuell gesperrt (zu viele Fehlversuche zuletzt). */
    public static function isLoginBlocked(string $email): bool
    {
        $emailKey = 'ratelimit:login:email:' . hash('sha256', strtolower(trim($email)));
        $ipKey = 'ratelimit:login:ip:' . self::clientIp();
        return self::count($emailKey) >= self::LOGIN_MAX_PER_EMAIL
            || self::count($ipKey) >= self::LOGIN_MAX_PER_IP;
    }

    public static function registerLoginFailure(string $email): void
    {
        $emailKey = 'ratelimit:login:email:' . hash('sha256', strtolower(trim($email)));
        $ipKey = 'ratelimit:login:ip:' . self::clientIp();
        self::increment($emailKey, self::LOGIN_WINDOW_SECONDS);
        self::increment($ipKey, self::LOGIN_WINDOW_SECONDS);
    }

    public static function resetLoginAttempts(string $email): void
    {
        $emailKey = 'ratelimit:login:email:' . hash('sha256', strtolower(trim($email)));
        self::reset($emailKey);
        // IP-Zähler bewusst NICHT zurückgesetzt -- ein erfolgreicher Login für EIN Konto soll
        // nicht das Credential-Stuffing-Budget für alle anderen Konten von derselben IP wieder
        // auf null setzen.
    }

    public static function isTotpBlocked(string $userId): bool
    {
        return self::count('ratelimit:2fa:' . $userId) >= self::TOTP_MAX;
    }

    public static function registerTotpFailure(string $userId): void
    {
        self::increment('ratelimit:2fa:' . $userId, self::TOTP_WINDOW_SECONDS);
    }

    public static function resetTotpAttempts(string $userId): void
    {
        self::reset('ratelimit:2fa:' . $userId);
    }
}
