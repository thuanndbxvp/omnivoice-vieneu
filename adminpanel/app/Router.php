<?php
/**
 * Router — ánh xạ URL → Controller action.
 * Entry point duy nhất: public/index.php
 */

declare(strict_types=1);

class Router {

    private static array $routes = [];

    public static function add(string $method, string $path, callable $handler): void {
        self::$routes[] = ['method' => strtoupper($method), 'path' => $path, 'handler' => $handler];
    }

    public static function dispatch(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri    = '/' . trim((string) $uri, '/');

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') continue;
            if ($route['path'] === $uri) {
                ($route['handler'])();
                return;
            }
        }

        http_response_code(404);
        echo '404 Not Found';
    }
}
