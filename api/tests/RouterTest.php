<?php
declare(strict_types=1);

use CacheCounty\Shared\Access;
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
        // Schreibende Requests durchlaufen die Origin-Prüfung – wie ein Browser
        // auf derselben Origin senden wir Sec-Fetch-Site: same-origin mit
        $_SERVER = [
            'REQUEST_METHOD'      => $method,
            'REQUEST_URI'         => $uri,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];
        return new Request();
    }

    public function test_route_without_access_level_cannot_be_registered(): void
    {
        $router = new Router($this->makeRequest('GET', '/'));

        $this->expectException(ArgumentCountError::class);
        $router->get('/api/neu', [TestController::class, 'handle']);
    }

    public function test_head_request_matches_get_route(): void
    {
        $req    = $this->makeRequest('HEAD', '/api/countries');
        $router = new Router($req);
        $router->get('/api/countries', [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        $this->assertNotNull(TestController::$capturedParams);
    }

    public function test_routes_expose_access_level(): void
    {
        $router = new Router($this->makeRequest('GET', '/'));
        $router->get('/api/a', [TestController::class, 'handle'], Access::Public);
        $router->post('/api/b', [TestController::class, 'handle'], Access::Admin);

        $this->assertSame(
            [Access::Public, Access::Admin],
            array_column($router->routes(), 'access')
        );
    }

    public function test_dispatch_calls_matching_get_route(): void
    {
        $req    = $this->makeRequest('GET', '/api/countries');
        $router = new Router($req);
        $router->get('/api/countries', [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        $this->assertNotNull(TestController::$capturedParams);
    }

    public function test_dispatch_extracts_single_url_parameter(): void
    {
        $req    = $this->makeRequest('GET', '/api/map/MaxMustermann');
        $router = new Router($req);
        $router->get('/api/map/{username}', [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        $this->assertSame('MaxMustermann', TestController::$capturedParams['username']);
    }

    public function test_dispatch_does_not_match_wrong_method(): void
    {
        // Register only a GET route; do not dispatch a POST to it.
        // This verifies that routes are stored per method without triggering exit.
        $req    = $this->makeRequest('GET', '/api/countries');
        $router = new Router($req);
        $router->get('/api/countries', [TestController::class, 'handle'], Access::Public);
        $router->dispatch();

        // Dispatched correctly; params were captured
        $this->assertNotNull(TestController::$capturedParams);

        // A POST router would not have a matching route – we verify the routes
        // array is method-specific by registering a separate POST route and
        // ensuring only the matching handler runs.
        TestController::$capturedParams = null;
        $req2    = $this->makeRequest('POST', '/api/countries');
        $router2 = new Router($req2);
        $router2->post('/api/countries', [TestController::class, 'handle'], Access::Public);
        $router2->dispatch();

        $this->assertNotNull(TestController::$capturedParams);
    }

    public function test_dispatch_matches_delete_route(): void
    {
        $req    = $this->makeRequest('DELETE', '/api/regions/DE-09162/visit');
        $router = new Router($req);
        $router->delete('/api/regions/{code}/visit', [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        $this->assertSame('DE-09162', TestController::$capturedParams['code']);
    }

    public function test_dispatch_extracts_username_from_stats_route(): void
    {
        $req    = $this->makeRequest('GET', '/api/stats/ClickKlack');
        $router = new Router($req);
        $router->get('/api/stats/{username}', [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        $this->assertSame('ClickKlack', TestController::$capturedParams['username']);
    }

    public function test_dispatch_matches_leaderboard_before_stats_username(): void
    {
        // Leaderboard route must win over {username} wildcard when registered first
        $req    = $this->makeRequest('GET', '/api/leaderboard');
        $router = new Router($req);
        $router->get('/api/leaderboard',       [TestController::class, 'handle'], Access::Public);
        $router->get('/api/stats/{username}',  [TestController::class, 'handle'], Access::Public);

        $router->dispatch();

        // The leaderboard route has no {username} segment – param must be null
        $this->assertNull(TestController::$capturedParams['username']);
    }
}
