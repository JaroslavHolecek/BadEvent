<?php

declare(strict_types=1);

namespace Tests\Support;

/** Base for end-to-end tests: fresh database + a new "browser" for every test. */
abstract class HttpTestCase extends DatabaseTestCase
{
    protected HttpClient $http;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        HttpServer::ensureStarted();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpClient();
    }

    /** New browser logged in as a fresh user with the given role(s). */
    protected function loginAs(string|array $roles, array $userOptions = []): array
    {
        $user = Fixtures::user($roles, $userOptions['full_name'] ?? null, $userOptions['short_name'] ?? null);
        $this->http = new HttpClient();
        $this->http->login($user['email']);
        return $user;
    }

    /** The app's "no permission" outcome: back to the dashboard (Controller::requirePermission / requireAdmin). */
    protected function assertDenied(HttpResponse $response, string $message = ''): void
    {
        $this->assertTrue($response->isRedirectTo('/'), $message . " — expected redirect to '/', got {$response->status} → " . var_export($response->location(), true));
    }

    protected function assertOk(HttpResponse $response, string $message = ''): void
    {
        $this->assertSame(200, $response->status, $message . ' — got ' . $response->status . ' → ' . var_export($response->location(), true));
    }
}
