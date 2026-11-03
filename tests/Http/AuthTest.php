<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Repositories\UserRepository;
use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/** Login through BadLogin (password and emailed link), logout, and session invalidation. */
final class AuthTest extends HttpTestCase
{
    private function mailDir(): string
    {
        return dirname(__DIR__) . '/tmp/mail';
    }

    private function clearMails(): void
    {
        array_map('unlink', glob($this->mailDir() . '/*.eml') ?: []);
    }

    /** Nepřihlášený návštěvník je přesměrován na přihlášení; přihlašovací stránka se zobrazí. */
    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->assertTrue($this->http->get('/actions')->isRedirectTo('/login'));
        $this->assertTrue($this->http->get('/')->isRedirectTo('/login'));
        $this->assertOk($this->http->get('/login'));
    }

    /** Přihlášení heslem funguje a na přehledu je vidět jméno uživatele. */
    public function testLoginWithPassword(): void
    {
        $user = Fixtures::user('organizer', 'Petra Testovací');

        $this->assertTrue($this->http->post('/login', ['email' => $user['email'], 'password' => $user['password']])->isRedirectTo('/'));

        $page = $this->http->get('/');
        $this->assertOk($page);
        $this->assertStringContainsString('Petra Testovací', $page->text());
    }

    /** Email při přihlášení nerozlišuje velká a malá písmena. */
    public function testEmailIsCaseInsensitive(): void
    {
        $user = Fixtures::user('organizer');

        $this->assertTrue($this->http->post('/login', ['email' => strtoupper($user['email']), 'password' => $user['password']])->isRedirectTo('/'));
    }

    /** Špatné heslo: zpět na přihlášení s chybou, uživatel není přihlášen, zapíše se do auditu. */
    public function testWrongPasswordIsRejected(): void
    {
        $user = Fixtures::user('organizer');

        $this->assertTrue($this->http->post('/login', ['email' => $user['email'], 'password' => 'spatne'])->isRedirectTo('/login'));
        $this->assertStringContainsString('Neplatné přihlašovací údaje.', $this->http->get('/login')->text());
        $this->assertTrue($this->http->get('/')->isRedirectTo('/login'));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE operation = 'login_failed'"));
    }

    /** Odhlášení ukončí session. */
    public function testLogout(): void
    {
        $this->loginAs('organizer');

        $this->assertTrue($this->http->post('/logout')->isRedirectTo('/login'));
        $this->assertTrue($this->http->get('/')->isRedirectTo('/login'));
    }

    /** Přihlášení odkazem z emailu funguje a odkaz nejde použít podruhé. */
    public function testLoginWithEmailedLinkWorksOnlyOnce(): void
    {
        $user = Fixtures::user('organizer');
        $this->clearMails();

        $this->assertTrue($this->http->post('/login', ['email' => $user['email'], 'method' => 'link'])->isRedirectTo('/login'));

        $mails = glob($this->mailDir() . '/*.eml') ?: [];
        $this->assertCount(1, $mails, 'the login link is "sent" to tests/tmp/mail');
        $this->assertMatchesRegularExpression('#/login\?token=([A-Za-z0-9_\-]+)#', (string) file_get_contents($mails[0]));
        preg_match('#/login\?token=([A-Za-z0-9_\-]+)#', (string) file_get_contents($mails[0]), $m);

        $this->assertTrue($this->http->get('/login', ['token' => $m[1]])->isRedirectTo('/'));
        $this->assertOk($this->http->get('/'));

        $other = new \Tests\Support\HttpClient();
        $reuse = $other->get('/login', ['token' => $m[1]]);
        $this->assertOk($reuse, 'a used link must not log in again');
        $this->assertStringContainsString('neplatný nebo vypršel', $reuse->text());
    }

    /** Žádost o odkaz pro neexistující email se tváří stejně a nic neodešle (neprozradí, zda účet existuje). */
    public function testLinkForUnknownEmailRevealsNothing(): void
    {
        $this->clearMails();

        $response = $this->http->post('/login', ['email' => 'nikdo@example.test', 'method' => 'link']);

        $this->assertTrue($response->isRedirectTo('/login'));
        $this->assertSame([], glob($this->mailDir() . '/*.eml') ?: []);
    }

    /** Smazaný uživatel je odhlášen i z již běžící session. */
    public function testDeletedUserLosesTheirSession(): void
    {
        $user = $this->loginAs('organizer');
        $this->assertOk($this->http->get('/'));

        (new UserRepository())->softDelete($user['id']);

        $this->assertTrue($this->http->get('/')->isRedirectTo('/login'));
    }
}
