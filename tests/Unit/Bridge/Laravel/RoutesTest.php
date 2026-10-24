<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Tests\Unit\Bridge\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tattali\PresignedUrl\Bridge\Laravel\Http\Controllers\ServeController;

final class RoutesTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(self::createStub(Dispatcher::class), new Container());
        Route::swap($this->router);
    }

    protected function tearDown(): void
    {
        Route::clearResolvedInstances();
    }

    #[Test]
    public function it_registers_the_serve_route_for_get_and_head(): void
    {
        require __DIR__ . '/../../../../src/Bridge/Laravel/routes/presigned-url.php';

        $routes = $this->router->getRoutes();
        $routes->refreshNameLookups();
        $route = $routes->getByName('presigned-url.serve');

        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame('storage/serve/{bucket}/{path}', $route->uri());
        self::assertSame('.*', $route->wheres['path']);
        self::assertSame(ServeController::class, $route->getActionName());
        self::assertCount(1, $routes->getRoutes());
    }
}
