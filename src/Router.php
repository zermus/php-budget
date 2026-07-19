<?php

declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * Resolve the route path for the current request.
     *
     * Works with rewrites (flat or public/ document roots, in subdirectories
     * or at the root) and with PATH_INFO (/index.php/dashboard) as a fallback.
     */
    public function currentPath(): string
    {
        if (!empty($_SERVER['PATH_INFO'])) {
            return '/' . trim($_SERVER['PATH_INFO'], '/');
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);

        // Candidate prefixes to strip: the directory of the executing script
        // (e.g. /budget/public), and the same with a trailing /public removed
        // (flat deploys where the URL never contains "public").
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $prefixes = [$scriptDir];
        if (str_ends_with($scriptDir, '/public')) {
            $prefixes[] = substr($scriptDir, 0, -strlen('/public'));
        }

        // Longest prefix first.
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            // Only strip on a whole-segment boundary, so a base dir of "/budget"
            // never mangles a request to "/budgets".
            if ($prefix !== '/' && $prefix !== '' && str_starts_with($uri, $prefix)) {
                $rest = substr($uri, strlen($prefix));
                if ($rest === '' || $rest[0] === '/') {
                    $uri = $rest;
                    break;
                }
            }
        }

        return '/' . trim($uri, '/');
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->currentPath();

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            http_response_code(404);
            echo View::render('error', [
                'title'   => 'Not Found',
                'heading' => '404 – Page Not Found',
                'message' => 'The page you requested does not exist.',
            ]);

            return;
        }

        $handler();
    }
}
