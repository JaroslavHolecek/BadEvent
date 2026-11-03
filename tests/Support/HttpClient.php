<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal browser: keeps the session cookie, never follows redirects (tests assert on them).
 * Uses PHP streams only, so no curl extension is needed.
 */
final class HttpClient
{
    private array $cookies = [];

    public function get(string $path, array $query = []): HttpResponse
    {
        $url = $path . ($query !== [] ? '?' . http_build_query($query) : '');
        return $this->request('GET', $url);
    }

    public function post(string $path, array $data = []): HttpResponse
    {
        return $this->request('POST', $path, http_build_query($data));
    }

    /** Logs in through the real login form; fails the test when the app doesn't accept it. */
    public function login(string $email, string $password = Fixtures::PASSWORD): HttpResponse
    {
        $response = $this->post('/login', ['email' => $email, 'password' => $password, 'method' => 'password']);
        if ($response->location() !== '/') {
            throw new \RuntimeException("Login of {$email} failed (redirect to '{$response->location()}').");
        }
        return $response;
    }

    private function request(string $method, string $url, ?string $body = null): HttpResponse
    {
        $headers = [];
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                fn($k, $v) => $k . '=' . $v,
                array_keys($this->cookies),
                $this->cookies
            ));
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headers),
            'content'         => $body ?? '',
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 30,
        ]]);

        $responseBody = @file_get_contents(HttpServer::baseUrl() . $url, false, $context);
        $rawHeaders   = $http_response_header ?? [];
        if ($responseBody === false && $rawHeaders === []) {
            throw new \RuntimeException("{$method} {$url}: no response from the test web server (see tests/tmp/http-server.log).");
        }

        $status  = 0;
        $parsed  = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $parsed = [];
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $parsed[strtolower($name)][] = $value;
            if (strtolower($name) === 'set-cookie') {
                [$pair]       = explode(';', $value, 2);
                [$cName, $cValue] = explode('=', $pair, 2) + [1 => ''];
                if ($cValue === '' || $cValue === 'deleted') {
                    unset($this->cookies[$cName]);
                } else {
                    $this->cookies[$cName] = $cValue;
                }
            }
        }

        return new HttpResponse($status, $parsed, (string) $responseBody);
    }
}
