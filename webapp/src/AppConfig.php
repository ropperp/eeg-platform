<?php

declare(strict_types=1);

/**
 * Zentrale, typisierte Konfiguration der Plattform: einzige Quelle für Hauptdomain, Portal-Host,
 * öffentliche URLs und Mail-Fallback. Werte kommen aus der Umgebung (docker-compose.yml / .env):
 *
 *   DOMAIN         Hauptdomain, z. B. stromfueralle.at (leer: stromfueralle.at)
 *   PORTAL_HOST    Host für Login/Backoffice (leer: portal.<DOMAIN>)
 *   CONTACT_EMAIL  Rückfall-Adresse, wenn eine EEG keine eigene hat (leer: office@<DOMAIN>)
 *   APP_ENV        production (Standard) oder development
 *
 * Bei DOMAIN=localhost oder einer IP-Adresse (lokale Entwicklung) ist die Domain-Trennung
 * zwischen Marketing-Seite und Portal abgeschaltet: Links bleiben relativ, es gibt keine
 * Weiterleitungen auf andere Hosts.
 */
final class AppConfig
{
    private static ?self $instance = null;

    public function __construct(
        public readonly string $domain,
        public readonly string $portalHost,
        public readonly string $contactEmail,
        public readonly bool $production,
    ) {
    }

    /** @param array<string, string|false>|null $env nur für Tests; null = echte Umgebung */
    public static function fromEnv(?array $env = null): self
    {
        $get = static fn(string $k): string => strtolower(trim((string) ($env === null ? getenv($k) : ($env[$k] ?? ''))));
        $domain = preg_replace('#^https?://|/.*$#', '', $get('DOMAIN')) ?: 'stromfueralle.at';
        $appEnv = $get('APP_ENV');
        return new self(
            domain: $domain,
            portalHost: $get('PORTAL_HOST') ?: 'portal.' . $domain,
            contactEmail: $get('CONTACT_EMAIL') ?: 'office@' . $domain,
            production: !in_array($appEnv, ['development', 'dev', 'local', 'test', 'testing'], true),
        );
    }

    public static function get(): self
    {
        return self::$instance ??= self::fromEnv();
    }

    /** Nur für Tests. */
    public static function set(?self $config): void
    {
        self::$instance = $config;
    }

    /** Domain-Trennung (Marketing vs. Portal) nur bei einer echten Domain, nicht lokal. */
    public function domainSplit(): bool
    {
        return $this->domain !== 'localhost'
            && !str_ends_with($this->domain, '.localhost')
            && filter_var($this->domain, FILTER_VALIDATE_IP) === false;
    }

    /** Steuerzentrale (Container konsole, docs/adr/0006-steuerzentrale.md), eigene Subdomain admin.<DOMAIN>. */
    public function controlCenterUrl(): string
    {
        return ($this->domainSplit() ? 'https://admin.' . $this->domain : 'http://' . $this->domain . ':8090') . '/';
    }

    public function marketingBaseUrl(): string
    {
        return ($this->domainSplit() ? 'https://' : 'http://') . $this->domain;
    }

    public function portalBaseUrl(): string
    {
        return $this->domainSplit() ? 'https://' . $this->portalHost : 'http://' . $this->domain;
    }

    public function isMarketingHost(string $host): bool
    {
        $host = self::normalizeHost($host);
        return $this->domainSplit() && ($host === $this->domain || $host === 'www.' . $this->domain);
    }

    public function isPortalHost(string $host): bool
    {
        return $this->domainSplit() && self::normalizeHost($host) === $this->portalHost;
    }

    /**
     * Cookie-Domain für die Session: ".<domain>" auf der Domain und allen Subdomains, damit
     * Marketing-Seite und Portal dieselbe Session teilen; sonst leer (nur exakter Host).
     */
    public function cookieDomainFor(string $host): string
    {
        $host = self::normalizeHost($host);
        if (!$this->domainSplit()) {
            return '';
        }
        return $host === $this->domain || str_ends_with($host, '.' . $this->domain) ? '.' . $this->domain : '';
    }

    /** Link zu /portal- oder /admin-Pfaden: relativ, wenn man schon auf dem Portal-Host ist. */
    public function portalUrl(string $path, string $currentHost): string
    {
        if (!$this->domainSplit() || $this->isPortalHost($currentHost)) {
            return $path;
        }
        return $this->portalBaseUrl() . $path;
    }

    /** Link zu einer öffentlichen Seite: relativ, wenn man schon auf der Hauptdomain ist. */
    public function marketingUrl(string $path, string $currentHost): string
    {
        if (!$this->domainSplit() || $this->isMarketingHost($currentHost)) {
            return $path;
        }
        return $this->marketingBaseUrl() . $path;
    }

    public static function normalizeHost(string $host): string
    {
        return strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');
    }
}
