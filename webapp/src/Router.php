<?php

declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = strtok($_SERVER['REQUEST_URI'], '?');

        // Zentraler CSRF-Schutz für ALLE POST-Formular-Routen (OWASP-Audit 13.08.2026) -- ein Ort
        // statt ~70 einzelne Handler, siehe csrfValid()/csrfToken() in functions.php. Läuft
        // bewusst VOR dem Pattern-Matching der einzelnen Route, damit kein POST-Handler
        // versehentlich vergessen werden kann.
        //
        // Ausnahme /api/*: CSRF ist ein Angriff auf Cookie-basierte Session-Auth (der Browser
        // schickt Cookies bei einem Cross-Site-Request automatisch mit, ohne dass die fremde
        // Seite sie kennen muss). /api/* nutzt AUSSCHLIESSLICH Bearer-Token im
        // Authorization-Header (member_api_keys, seit 30.08.2026 auch AppApiAuth für die
        // Mitglieder-App) -- kein Cookie, kein automatisches Mitschicken durch den Browser, ein
        // fremder Origin kennt den Token schlicht nicht. Diese Routen haben strukturell KEIN
        // CSRF-Risiko und (mobile App, Skripte) auch keine PHP-Session, aus der ein
        // csrf_token käme -- ein Blanket-CSRF-Check hier würde sie einfach immer aussperren.
        $isApiRoute = str_starts_with($uri, '/api/');
        if ($method === 'POST' && !$isApiRoute && !csrfValid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            require __DIR__ . '/views/pages/csrf_error.php';
            return;
        }

        foreach ($this->routes as [$routeMethod, $path, $handler]) {
            if ($routeMethod !== $method) continue;

            // Einfaches Pattern-Matching: :param wird zu Named-Capture-Group
            $pattern = preg_replace('/:([a-z_]+)/', '(?P<$1>[^/]+)', $path);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                call_user_func($handler, $params);
                return;
            }
        }

        http_response_code(404);
        require __DIR__ . '/views/pages/404.php';
    }
}
