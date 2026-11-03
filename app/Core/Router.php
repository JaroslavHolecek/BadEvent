<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, array $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri    = rtrim($request->uri(), '/') ?: '/';

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            $params = $this->matchPath($routePath, $uri);
            if ($params !== null) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->$action($request, $params);
                return;
            }
        }

        http_response_code(404);
        echo '404 – stránka nenalezena';
    }

    private function matchPath(string $routePath, string $uri): ?array
    {
        $normalized = rtrim($routePath, '/') ?: '/';
        $pattern    = preg_replace('/\{([a-zA-Z_]+)\}/', '([^/]+)', $normalized);
        $pattern    = '#^' . $pattern . '$#u';

        if (!preg_match($pattern, $uri, $matches)) {
            return null;
        }

        preg_match_all('/\{([a-zA-Z_]+)\}/', $normalized, $paramNames);
        $params = [];
        foreach ($paramNames[1] as $i => $name) {
            $params[$name] = $matches[$i + 1];
        }
        return $params;
    }
}
