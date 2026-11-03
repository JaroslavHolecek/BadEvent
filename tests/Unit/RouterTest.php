<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Records which handler the router called and with which params. */
final class RouterSpyController
{
    public static array $calls = [];

    public function __call(string $name, array $arguments): void
    {
        self::$calls[] = [$name, $arguments[1]];
    }
}

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        RouterSpyController::$calls = [];
    }

    private function dispatch(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;

        // Same order as public/index.php — /actions/create must win over /actions/{id}.
        $router = new Router();
        $router->get('/actions',                           [RouterSpyController::class, 'index']);
        $router->get('/actions/create',                    [RouterSpyController::class, 'createForm']);
        $router->get('/actions/{id}',                      [RouterSpyController::class, 'show']);
        $router->post('/actions/{id}',                     [RouterSpyController::class, 'editPost']);
        $router->post('/actions/{id}/transition/{status}', [RouterSpyController::class, 'transition']);
        $router->dispatch(new Request());
    }

    public static function routes(): array
    {
        return [
            'list'                => ['GET',  '/actions',                        'index',      []],
            'trailing slash'      => ['GET',  '/actions/',                       'index',      []],
            'query string'        => ['GET',  '/actions?status[]=draft',         'index',      []],
            'static before param' => ['GET',  '/actions/create',                 'createForm', []],
            'param'               => ['GET',  '/actions/42',                     'show',       ['id' => '42']],
            'POST same path'      => ['POST', '/actions/42',                     'editPost',   ['id' => '42']],
            'two params'          => ['POST', '/actions/7/transition/confirmed', 'transition', ['id' => '7', 'status' => 'confirmed']],
        ];
    }

    /** Router zavolá správný controller s parametry z URL (lomítko na konci, query string, statická cesta má přednost před {id}). */
    #[DataProvider('routes')]
    public function testDispatchesToTheMatchingHandler(string $method, string $uri, string $handler, array $params): void
    {
        $this->dispatch($method, $uri);

        $this->assertSame([[$handler, $params]], RouterSpyController::$calls);
    }

    /** Neznámá adresa vrátí 404 a nezavolá žádný controller. */
    public function testUnknownPathIs404(): void
    {
        $this->expectOutputString('404 – stránka nenalezena');
        $this->dispatch('GET', '/does-not-exist');
        $this->assertSame([], RouterSpyController::$calls);
    }

    /** Existující cesta, ale jiná HTTP metoda (POST místo GET) vrátí 404. */
    public function testWrongMethodIs404(): void
    {
        $this->expectOutputString('404 – stránka nenalezena');
        $this->dispatch('POST', '/actions');   // only GET /actions is registered here
        $this->assertSame([], RouterSpyController::$calls);
    }

    /** Parametr {id} nezachytí víc úseků cesty (/actions/1/2 není akce). */
    public function testParamDoesNotSpanSlashes(): void
    {
        $this->expectOutputString('404 – stránka nenalezena');
        $this->dispatch('GET', '/actions/1/2');
    }
}
