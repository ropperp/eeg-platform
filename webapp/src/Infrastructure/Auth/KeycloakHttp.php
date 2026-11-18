<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/** HTTP-Aufrufe an Keycloak (intern, http://keycloak:8080), kurze Zeitlimits, keine Weiterleitungen. */
final class KeycloakHttp
{
    /**
     * @param list<string> $headers
     * @return array{status:int,json:mixed,location:string,body:string}
     */
    public static function request(string $method, string $url, array $headers, ?string $payload): array
    {
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
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

}
