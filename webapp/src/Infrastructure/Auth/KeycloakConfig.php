<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Einstellungen für den Anmeldedienst Keycloak (docs/adr/0007-keycloak.md), alle aus der Umgebung:
 *
 *   AUTH_PROVIDER            "keycloak" = Portal-Anmeldung über Keycloak; "keycloak-test" = nur über
 *                            /portal/login?keycloak=1 (alle anderen wie bisher); sonst wie bisher (Standard)
 *   KC_PORTAL_CLIENT_SECRET  Secret des Clients "portal"; leer = Keycloak nicht eingerichtet
 *   KEYCLOAK_INTERNAL_URL    interne Adresse (Standard http://keycloak:8080) für Token, Schlüssel, Admin-API
 *   KEYCLOAK_PUBLIC_URL      öffentliche Adresse (Standard https://auth.<DOMAIN>), dorthin geht der Browser
 *   KEYCLOAK_REALM           Standard stromfueralle
 *   KEYCLOAK_PORTAL_URL      nur für Tests: Basis-URL des Portals (Standard: AppConfig::portalBaseUrl())
 *   KEYCLOAK_POST_LOGOUT_URL nur für Tests: Ziel nach dem Abmelden (Standard: Startseite)
 *
 * Der Abgleich Portal -> Keycloak (KeycloakSync) läuft, sobald das Client-Secret gesetzt ist, auch
 * wenn die Anmeldung noch über das Portal geht. So ist Keycloak beim Umschalten schon aktuell.
 */
final class KeycloakConfig
{
    public function __construct(
        public readonly bool $loginEnabled,
        public readonly string $clientSecret,
        public readonly string $internalUrl,
        public readonly string $publicUrl,
        public readonly string $realm,
        public readonly string $portalUrl,
        public readonly string $postLogoutUrl,
        public readonly string $clientId = 'portal',
        public readonly bool $testOnly = false,
    ) {
    }

    public static function fromEnv(): self
    {
        $env = static fn(string $k): string => trim((string) getenv($k));
        $app = \AppConfig::get();
        $secret = $env('KC_PORTAL_CLIENT_SECRET');
        $publicDefault = $app->domainSplit() ? 'https://auth.' . $app->domain : 'http://localhost:8081';
        return new self(
            loginEnabled: in_array(strtolower($env('AUTH_PROVIDER')), ['keycloak', 'keycloak-test'], true) && $secret !== '',
            clientSecret: $secret,
            internalUrl: rtrim($env('KEYCLOAK_INTERNAL_URL') ?: 'http://keycloak:8080', '/'),
            publicUrl: rtrim($env('KEYCLOAK_PUBLIC_URL') ?: $publicDefault, '/'),
            realm: $env('KEYCLOAK_REALM') ?: 'stromfueralle',
            portalUrl: rtrim($env('KEYCLOAK_PORTAL_URL') ?: $app->portalBaseUrl(), '/'),
            postLogoutUrl: $env('KEYCLOAK_POST_LOGOUT_URL') ?: $app->marketingBaseUrl() . '/',
            testOnly: strtolower($env('AUTH_PROVIDER')) === 'keycloak-test',
        );
    }

    /** Abgleich Portal -> Keycloak aktiv (Client-Secret vorhanden). */
    public function syncEnabled(): bool
    {
        return $this->clientSecret !== '';
    }

    /** Aussteller in den Tokens: immer die öffentliche Adresse (auch wenn intern abgeholt). */
    public function issuer(): string
    {
        return $this->publicUrl . '/realms/' . $this->realm;
    }

    public function redirectUri(): string
    {
        return $this->portalUrl . '/portal/auth/callback';
    }

    public function publicEndpoint(string $path): string
    {
        return $this->issuer() . '/protocol/openid-connect/' . $path;
    }

    public function internalEndpoint(string $path): string
    {
        return $this->internalUrl . '/realms/' . rawurlencode($this->realm) . '/protocol/openid-connect/' . $path;
    }
}
