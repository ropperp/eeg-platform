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

        // Zentraler CSRF-Schutz für ALLE POST-Routen (OWASP-Audit 13.08.2026) -- ein Ort statt
        // ~70 einzelne Handler, siehe csrfValid()/csrfToken() in functions.php. Läuft bewusst
        // VOR dem Pattern-Matching der einzelnen Route, damit kein POST-Handler versehentlich
        // vergessen werden kann.
        if ($method === 'POST' && !csrfValid($_POST['csrf_token'] ?? null)) {
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
