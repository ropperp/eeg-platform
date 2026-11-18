<?php

declare(strict_types=1);

namespace Konsole;

/** Einstellungen aus der Umgebung (docker-compose.yml, Werte aus der .env). */
final class Config
{
    /** Dienste, die die Konsole anzeigen und neu starten darf (= container_name in docker-compose.yml). */
    public const DEFAULT_SERVICES = ['webapp', 'eda-worker', 'mqtt-subscriber', 'latex-service', 'mosquitto', 'redis', 'timescaledb', 'traefik'];

    /** @param list<string> $services */
    public function __construct(
        public readonly string $domain,
        public readonly string $adminHost,
        public readonly string $secret,
        public readonly string $dataDir,
        public readonly string $traefikDir,
        public readonly array $services,
        public readonly bool $dev,
        public readonly string $trustedProxy,
        public readonly string $dockerSocket,
        public readonly string $dbDsn,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        // Anmeldung über Keycloak (docs/adr/0007-keycloak.md). Leeres Secret = nur Passkey wie bisher.
        public readonly string $kcClientSecret = '',
        public readonly string $kcPublicUrl = '',
        public readonly string $kcInternalUrl = 'http://keycloak:8080',
        public readonly string $kcRealm = 'stromfueralle',
        public readonly string $baseUrl = '',
    ) {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('APP_SECRET fehlt oder ist zu kurz (mind. 32 Zeichen).');
        }
    }

    public static function fromEnv(): self
    {
        $env = fn(string $k, string $d = ''): string => (string) (getenv($k) ?: $d);
        $domain = strtolower($env('DOMAIN', 'stromfueralle.at'));
        $services = array_values(array_filter(array_map('trim', explode(',', $env('KONSOLE_SERVICES', implode(',', self::DEFAULT_SERVICES)))),
            fn($s) => (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $s)));
        return new self(
            domain: $domain,
            adminHost: strtolower($env('KONSOLE_HOST', 'admin.' . $domain)),
            secret: $env('APP_SECRET'),
            dataDir: rtrim($env('KONSOLE_DATA_DIR', '/data'), '/'),
            traefikDir: rtrim($env('KONSOLE_TRAEFIK_DIR', '/traefik'), '/'),
            services: $services,
            dev: $env('KONSOLE_DEV') === '1',
            trustedProxy: $env('TRUSTED_PROXY_IP', '10.0.0.144'),
            dockerSocket: $env('DOCKER_SOCKET', '/var/run/docker.sock'),
            dbDsn: sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST', 'timescaledb'), $env('DB_PORT', '5432'), $env('DB_NAME', 'eeg_platform')),
            dbUser: $env('APP_DB_USER'),
            dbPassword: $env('APP_DB_PASSWORD'),
            kcClientSecret: $env('KC_KONSOLE_CLIENT_SECRET'),
            kcPublicUrl: rtrim($env('KEYCLOAK_PUBLIC_URL', 'https://auth.' . $domain), '/'),
            kcInternalUrl: rtrim($env('KEYCLOAK_INTERNAL_URL', 'http://keycloak:8080'), '/'),
            kcRealm: $env('KEYCLOAK_REALM', 'stromfueralle'),
            baseUrl: rtrim($env('KONSOLE_BASE_URL', 'https://' . strtolower($env('KONSOLE_HOST', 'admin.' . $domain))), '/'),
        );
    }

    /** Herkunft des Anmeldedienstes (z. B. https://auth.stromfueralle.at) für die CSP; leer ohne Keycloak. */
    public function kcOrigin(): string
    {
        if ($this->kcClientSecret === '' || $this->kcPublicUrl === '') {
            return '';
        }
        $p = parse_url($this->kcPublicUrl);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || !in_array($p['scheme'], ['https', 'http'], true)
            || !preg_match('/^[a-z0-9.-]+$/i', (string) $p['host'])) {
            return '';
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** Öffentliche Hosts der Plattform, die im Wartungsmodus auf die Wartungsseite zeigen. */
    public function publicHosts(): array
    {
        return [$this->domain, 'www.' . $this->domain, 'portal.' . $this->domain, 'live.' . $this->domain];
    }

    /** Schlüssel für einen Zweck aus APP_SECRET ableiten (nie das Secret selbst verwenden). */
    public function key(string $purpose): string
    {
        return hash_hmac('sha256', 'konsole:' . $purpose, $this->secret, true);
    }
}
