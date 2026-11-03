<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        $target = (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))
            ? $url
            : (defined('APP_BASE') ? APP_BASE : '') . $url;
        header('Location: ' . $target);
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}
