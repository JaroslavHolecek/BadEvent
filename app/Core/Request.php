<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $uri;
    private array  $get;
    private array  $post;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $base = defined('APP_BASE') ? APP_BASE : '';
        $fullPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->uri = ($base !== '' && str_starts_with($fullPath, $base))
            ? (substr($fullPath, strlen($base)) ?: '/')
            : ($fullPath ?: '/');
        $this->get    = $_GET;
        $this->post   = $_POST;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /** Returns a single POST value, or all POST data when called without arguments. */
    public function post(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /** Returns raw POST body decoded as JSON, or null on failure. */
    public function json(): ?array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
