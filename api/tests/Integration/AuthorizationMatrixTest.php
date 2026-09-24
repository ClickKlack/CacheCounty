<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ruft jede Route mit jeder Rolle auf und prüft den HTTP-Status gegen RouteMatrix.
 */
class AuthorizationMatrixTest extends ApiTestCase
{
    public static function cases(): iterable
    {
        foreach (RouteMatrix::routes() as $route => $case) {
            [$method] = explode(' ', $route, 2);
            foreach ($case['expect'] as $role => $status) {
                yield "$route als $role" => [$method, $case['path'], $case['body'], $role, $status];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_route_returns_expected_status_for_role(
        string $method,
        string $path,
        ?array $body,
        string $role,
        int $expected
    ): void {
        $response = $this->request($method, $path, $body, self::tokenFor($role));

        $this->assertSame(
            $expected,
            $response['status'],
            "$method $path als $role – Antwort: " . json_encode($response['body'])
        );
    }
}
