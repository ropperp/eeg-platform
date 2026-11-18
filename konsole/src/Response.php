<?php

declare(strict_types=1);

namespace Konsole;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];
    /** @var list<array{string, string, int}> */
    private array $cookies = [];

    private function __construct(public readonly int $status, public readonly string $body, string $contentType)
    {
        $this->headers['Content-Type'] = $contentType;
        $this->headers['Cache-Control'] = 'no-store';
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, 'text/html; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, 'text/plain; charset=utf-8');
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'application/json');
    }

    public static function redirect(string $location): self
    {
        return (new self(303, '', 'text/plain'))->header('Location', $location);
    }

    public static function asset(string $file): self
    {
        $type = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css'   => 'text/css; charset=utf-8',
            'png'   => 'image/png',
            default => 'text/javascript; charset=utf-8',
        };
        $r = new self(200, (string) @file_get_contents($file), $type);
        return $r->header('Cache-Control', 'public, max-age=300');
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function headerValue(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function cookie(string $name, string $value, int $ttl): self
    {
        $this->cookies[] = [$name, $value, $ttl];
        return $this;
    }

    /** @return list<array{string, string, int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Content-Security-Policy der Konsole. $formActionOrigin: weiteres erlaubtes Ziel für Formulare, nämlich
     * der Anmeldedienst. "Abmelden" ist ein Formular, das zu Keycloak weiterleitet; mit nur 'self' blockiert
     * der Browser diese Weiterleitung still, und die Keycloak-Sitzung bliebe bestehen (Vorfall 19.11.2026).
     */
    public static function csp(string $formActionOrigin = ''): string
    {
        $formAction = "'self'" . ($formActionOrigin !== '' ? ' ' . $formActionOrigin : '');
        return "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action " . $formAction;
    }

    public function send(bool $secureCookies, string $formActionOrigin = ''): void
    {
        http_response_code($this->status);
        $security = [
            'Content-Security-Policy' => self::csp($formActionOrigin),
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'no-referrer',
            'X-Robots-Tag'            => 'noindex, nofollow',
        ];
        foreach ($security + $this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as [$name, $value, $ttl]) {
            setcookie($name, $value, [
                'expires'  => $ttl > 0 ? time() + $ttl : 1,
                'path'     => '/',
                'secure'   => $secureCookies,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        echo $this->body;
    }
}
