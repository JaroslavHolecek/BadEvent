<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** Every page with its own <head> loads the active theme (public/themes/<theme>/theme.css) after main.css. */
final class ThemeTest extends HttpTestCase
{
    /** Přihlášení, stránky aplikace i tisk logů načítají CSS motivu až po main.css (aby ho motiv mohl přepsat) a logo motivu. */
    public function testPagesLoadThemeCssAfterMainCss(): void
    {
        $this->assertThemeCss($this->http->get('/login'), '/login');

        $this->loginAs('techadmin');
        foreach (['/', '/admin/logs/print'] as $path) {
            $page = $this->http->get($path);
            $this->assertOk($page, $path);
            $this->assertThemeCss($page, $path);
        }

        $home = $this->http->get('/')->xpath();
        $this->assertSame(1, $home->query('//img[@class="nav-logo-img"][contains(@src, "/themes/demo/logo.svg")]')->length, 'header logo');
    }

    private function assertThemeCss(\Tests\Support\HttpResponse $page, string $path): void
    {
        $hrefs = [];
        foreach ($page->xpath()->query('//head/link[@rel="stylesheet"]') as $link) {
            $hrefs[] = (string) parse_url($link->getAttribute('href'), PHP_URL_PATH);
        }
        $this->assertSame(['/assets/css/main.css', '/themes/demo/theme.css'], $hrefs, $path);
    }
}
