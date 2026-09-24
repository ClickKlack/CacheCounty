<?php
declare(strict_types=1);

use CacheCounty\Shared\Request;
use CacheCounty\Shared\Router;
use PHPUnit\Framework\TestCase;

/**
 * Stellt sicher, dass jede Route aus routes.php in der Autorisierungs-Matrix steht.
 *
 * Läuft ohne Datenbank. Eine neue Route ohne Matrix-Eintrag lässt diesen Test
 * scheitern – wer eine Route anlegt, muss also festlegen, wer sie aufrufen darf.
 */
class RoutesCompletenessTest extends TestCase
{
    /** @return list<string> "METHOD /pfad" in Registrierungsreihenfolge */
    private function registeredRoutes(): array
    {
        $router = new Router(new Request());
        require dirname(__DIR__) . '/src/routes.php';

        return array_map(
            fn(array $r) => $r['method'] . ' ' . $r['path'],
            $router->routes()
        );
    }

    public function test_every_route_has_a_matrix_entry(): void
    {
        $missing = array_diff($this->registeredRoutes(), array_keys(RouteMatrix::routes()));

        $this->assertSame(
            [],
            array_values($missing),
            'Routen ohne Eintrag in tests/Integration/RouteMatrix.php'
        );
    }

    public function test_matrix_has_no_stale_entries(): void
    {
        $stale = array_diff(array_keys(RouteMatrix::routes()), $this->registeredRoutes());

        $this->assertSame(
            [],
            array_values($stale),
            'Matrix-Einträge ohne passende Route in routes.php'
        );
    }

    public function test_declared_access_matches_matrix(): void
    {
        $router = new Router(new Request());
        require dirname(__DIR__) . '/src/routes.php';

        foreach ($router->routes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            $this->assertSame(
                RouteMatrix::routes()[$key]['access'] ?? null,
                $route['access']->name,
                "Zugriffsstufe von $key weicht von der Matrix ab"
            );
        }
    }

    public function test_matrix_statuses_fit_declared_access(): void
    {
        // Plausibilität: Wer laut Stufe keinen Zugriff hat, bekommt 401 bzw. 403
        foreach (RouteMatrix::routes() as $route => $case) {
            $expect = $case['expect'];
            if ($case['access'] === 'User' || $case['access'] === 'Admin') {
                $this->assertSame(401, $expect['anon'], "$route: anonym muss 401 sein");
                $this->assertSame(401, $expect['inactive'], "$route: deaktiviert muss 401 sein");
            }
            if ($case['access'] === 'Admin') {
                $this->assertSame(403, $expect['user'], "$route: normaler User muss 403 sein");
            }
        }
    }

    public function test_every_matrix_entry_covers_all_roles(): void
    {
        foreach (RouteMatrix::routes() as $route => $case) {
            $this->assertEqualsCanonicalizing(
                ['anon', 'user', 'inactive', 'admin'],
                array_keys($case['expect']),
                "Rollen unvollständig für $route"
            );
        }
    }
}
