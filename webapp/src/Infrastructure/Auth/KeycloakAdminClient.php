<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Schmale Anbindung an die Admin-REST-API von Keycloak (docs/adr/0007-keycloak.md).
 *
 * Meldet sich als Dienstkonto des Clients "portal" an (client_credentials). Das Dienstkonto hat im Realm
 * nur Benutzer-Rechte (manage-users, view-users, query-users, view-realm), keine Realm-Verwaltung.
 * Spricht Keycloak intern über http://keycloak:8080 an, nie über die öffentliche Adresse.
 */
final class KeycloakAdminClient
{
    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $realm,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public static function fromEnv(): self
    {
        $secret = (string) getenv('KC_PORTAL_CLIENT_SECRET');
        if ($secret === '') {
            throw new \RuntimeException('Keycloak ist nicht eingerichtet (KC_PORTAL_CLIENT_SECRET fehlt, siehe scripts/keycloak_setup.sh).');
        }
        return new self(
            rtrim((string) (getenv('KEYCLOAK_INTERNAL_URL') ?: 'http://keycloak:8080'), '/'),
            (string) (getenv('KEYCLOAK_REALM') ?: 'stromfueralle'),
            'portal',
            $secret
        );
    }

    /** @return array<string,mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        $res = $this->request('GET', '/users?exact=true&briefRepresentation=true&email=' . rawurlencode($email));
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Keycloak: Benutzersuche fehlgeschlagen (HTTP ' . $res['status'] . ')');
        }
        foreach ((array) $res['json'] as $u) {
            if (is_array($u) && strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Legt einen Benutzer samt Credentials an und gibt seine Keycloak-ID zurück.
     *
     * @param array<string,mixed> $rep UserRepresentation
     */
    public function createUser(array $rep): string
    {
        $res = $this->request('POST', '/users', $rep);
        if ($res['status'] !== 201 || !preg_match('#/users/([0-9a-f-]{36})$#', $res['location'], $m)) {
            throw new \RuntimeException('Keycloak: Benutzer anlegen fehlgeschlagen (HTTP ' . $res['status'] . ') ' . self::errorText($res));
        }
        return $m[1];
    }

    public function assignRealmRole(string $userId, string $roleName): void
    {
        $role = $this->request('GET', '/roles/' . rawurlencode($roleName));
        if ($role['status'] !== 200) {
            throw new \RuntimeException('Keycloak: Rolle ' . $roleName . ' nicht gefunden (HTTP ' . $role['status'] . ')');
        }
        // Nur id und name senden: ein leeres "attributes" käme aus json_decode/json_encode als [] statt {}
        // zurück, das lehnt Keycloak mit HTTP 400 ab.
        $ref = ['id' => (string) $role['json']['id'], 'name' => (string) $role['json']['name']];
        $res = $this->request('POST', '/users/' . rawurlencode($userId) . '/role-mappings/realm', [$ref]);
        if ($res['status'] !== 204) {
            throw new \RuntimeException('Keycloak: Rolle zuweisen fehlgeschlagen (HTTP ' . $res['status'] . ')');
        }
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 30) {
            return $this->token;
        }
        $res = $this->http('POST', $this->baseUrl . '/realms/' . rawurlencode($this->realm) . '/protocol/openid-connect/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            // Seit Keycloak 26.8 braucht die Admin-API diese Zielgruppe im Token (optionaler Scope,
            // normale Anmelde-Tokens des Portals bekommen sie nicht).
            'scope' => 'kc-admin-api',
        ]));
        $json = $res['json'];
        if ($res['status'] !== 200 || !is_array($json) || empty($json['access_token'])) {
            throw new \RuntimeException('Keycloak: Anmeldung des Dienstkontos fehlgeschlagen (HTTP ' . $res['status'] . ')');
        }
        $this->token = (string) $json['access_token'];
        $this->tokenExpires = time() + (int) ($json['expires_in'] ?? 60);
        return $this->token;
    }

    /**
     * @param array<mixed>|null $body
     * @return array{status:int,json:mixed,location:string,body:string}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->accessToken()];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $this->http($method, $this->baseUrl . '/admin/realms/' . rawurlencode($this->realm) . $path, $headers, $payload);
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,json:mixed,location:string,body:string}
     */
    private function http(string $method, string $url, array $headers, ?string $payload): array
    {
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Keycloak nicht erreichbar: ' . $error);
        }
        $body = (string) $body;
        return ['status' => $status, 'json' => json_decode($body, true), 'location' => $location, 'body' => $body];
    }

    /** @param array{json:mixed,body:string} $res */
    private static function errorText(array $res): string
    {
        $j = $res['json'];
        if (is_array($j)) {
            return (string) ($j['errorMessage'] ?? $j['error_description'] ?? $j['error'] ?? '');
        }
        return substr($res['body'], 0, 200);
    }
}
