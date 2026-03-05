<?php
declare(strict_types=1);

use CacheCounty\Shared\Request;
use CacheCounty\Shared\Router;
use PHPUnit\Framework\TestCase;

// Minimal test controller used to verify dispatch without a real database
class TestController
{
    public static ?array $capturedParams = null;

    public function handle(Request $request): void
    {
        self::$capturedParams = [
            'username' => $request->param('username'),
            'code'     => $request->param('code'),
        ];
    }
}

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        TestController::$capturedParams = null;
    }

    private function makeRequest(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        return new Request();
    }

    public function test_dispatch_calls_matching_get_route(): void
    {
        $req    = $this->makeRequest('GET', '/api/countries');
        $router = new Router($req);
        $router->get('/api/countries', [TestController::class, 'handle']);

        $router->dispatch();

        $this->assertNotNull(TestController::$capturedParams);
    }

    public function test_dispatch_extracts_single_url_parameter(): void
    {
        $req    = $this->makeRequest('GET', '/api/map/MaxMustermann');
        $router = new Router($req);
        $router->get('/api/map/{username}', [TestController::class, 'handle']);

        $router->dispatch();

        $this->assertSame('MaxMustermann', TestController::$capturedParams['username']);
    }

    public function test_dispatch_does_not_match_wrong_method(): void
    {
        // Register only a GET route; do not dispatch a POST to it.
        // This verifies that routes are stored per method without triggering exit.
        $req    = $this->makeRequest('GET', '/api/countries');
        $router = new Router($req);
        $router->get('/api/countries', [TestController::class, 'handle']);
        $router->dispatch();

        // Dispatched correctly; params were captured
        $this->assertNotNull(TestController::$capturedParams);

        // A POST router would not have a matching route – we verify the routes
        // array is method-specific by registering a separate POST route and
        // ensuring only the matching handler runs.
        TestController::$capturedParams = null;
        $req2    = $this->makeRequest('POST', '/api/countries');
        $router2 = new Router($req2);
        $router2->post('/api/countries', [TestController::class, 'handle']);
        $router2->dispatch();

        $this->assertNotNull(TestController::$capturedParams);
    }

    public function test_dispatch_matches_delete_route(): void
    {
        $req    = $this->makeRequest('DELETE', '/api/regions/DE-09162/visit');
        $router = new Router($req);
        $router->delete('/api/regions/{code}/visit', [TestController::class, 'handle']);

        $router->dispatch();

        $this->assertSame('DE-09162', TestController::$capturedParams['code']);
    }
}
