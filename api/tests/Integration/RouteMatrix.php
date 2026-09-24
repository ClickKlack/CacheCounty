<?php
declare(strict_types=1);

/**
 * Autorisierungs-Matrix: erwarteter HTTP-Status je Route und Rolle.
 *
 * Rollen: anon (kein Cookie), user (User A), inactive (deaktivierter Admin), admin.
 *
 * Jede Route aus src/routes.php braucht hier einen Eintrag – RoutesCompletenessTest
 * schlägt sonst fehl. Wer eine Route anlegt, muss also festlegen, wer sie aufrufen darf.
 *
 * Konkrete Pfade und Bodies beziehen sich auf die Fixtures aus ApiTestCase.
 */
final class RouteMatrix
{
    private const PUBLIC = ['anon' => 200, 'user' => 200, 'inactive' => 200, 'admin' => 200];

    /**
     * @return array<string, array{path: string, body: ?array, expect: array<string, int>}>
     *         Schlüssel: "METHOD /routen/muster" exakt wie in routes.php
     */
    public static function routes(): array
    {
        return [
            // ── Öffentlich ──
            'GET /api/countries' => [
                'path' => '/api/countries', 'body' => null, 'expect' => self::PUBLIC,
            ],
            'GET /api/map/{username}' => [
                'path' => '/api/map/userA', 'body' => null, 'expect' => self::PUBLIC,
            ],
            'GET /api/leaderboard' => [
                'path' => '/api/leaderboard', 'body' => null, 'expect' => self::PUBLIC,
            ],
            'GET /api/stats/{username}' => [
                'path' => '/api/stats/userA', 'body' => null, 'expect' => self::PUBLIC,
            ],

            // ── Auth ──
            // Unbekannte Adresse: kein Mailversand, immer generische Erfolgsmeldung
            'POST /api/auth/magic-link' => [
                'path' => '/api/auth/magic-link', 'body' => ['email' => 'unbekannt@example.com'],
                'expect' => self::PUBLIC,
            ],
            // Ungültiges Token: für jede Rolle 401
            'GET /api/auth/verify' => [
                'path' => '/api/auth/verify?token=' . str_repeat('f', 64), 'body' => null,
                'expect' => ['anon' => 401, 'user' => 401, 'inactive' => 401, 'admin' => 401],
            ],
            'GET /api/auth/me' => [
                'path' => '/api/auth/me', 'body' => null,
                'expect' => ['anon' => 401, 'user' => 200, 'inactive' => 401, 'admin' => 200],
            ],
            // Logout ist tolerant: auch ohne gültige Session 200
            'POST /api/auth/logout' => [
                'path' => '/api/auth/logout', 'body' => null, 'expect' => self::PUBLIC,
            ],
            'POST /api/auth/logout-all' => [
                'path' => '/api/auth/logout-all', 'body' => null,
                'expect' => ['anon' => 401, 'user' => 200, 'inactive' => 401, 'admin' => 200],
            ],

            // ── Besuche (nur eigene; der Admin hat keinen Besuch VISIT_A_CODE → 404) ──
            'POST /api/regions/{code}/visit' => [
                'path' => '/api/regions/DE-01001/visit',
                'body' => ['region_name' => 'Flensburg', 'visited_at' => '2024-07-01', 'notes' => 'neu'],
                'expect' => ['anon' => 401, 'user' => 200, 'inactive' => 401, 'admin' => 200],
            ],
            'PUT /api/regions/{code}/visit' => [
                'path' => '/api/regions/' . ApiTestCase::visitCode() . '/visit',
                'body' => ['visited_at' => '2024-08-01', 'notes' => 'geändert'],
                'expect' => ['anon' => 401, 'user' => 200, 'inactive' => 401, 'admin' => 404],
            ],
            'DELETE /api/regions/{code}/visit' => [
                'path' => '/api/regions/' . ApiTestCase::visitCode() . '/visit', 'body' => null,
                'expect' => ['anon' => 401, 'user' => 200, 'inactive' => 401, 'admin' => 404],
            ],

            // ── Admin ──
            'GET /api/admin/users' => [
                'path' => '/api/admin/users', 'body' => null, 'expect' => self::admin(200),
            ],
            'POST /api/admin/users' => [
                'path' => '/api/admin/users',
                'body' => ['username' => 'neuer_user', 'email' => 'neu@example.com', 'is_admin' => false],
                'expect' => self::admin(201),
            ],
            'PATCH /api/admin/users/{id}' => [
                'path' => '/api/admin/users/' . ApiTestCase::userBId(), 'body' => ['is_active' => false],
                'expect' => self::admin(200),
            ],
            'DELETE /api/admin/users/{id}' => [
                'path' => '/api/admin/users/' . ApiTestCase::userBId(), 'body' => null,
                'expect' => self::admin(200),
            ],
            'GET /api/admin/sessions' => [
                'path' => '/api/admin/sessions', 'body' => null, 'expect' => self::admin(200),
            ],
            'DELETE /api/admin/sessions/{token}' => [
                'path' => '/api/admin/sessions/' . ApiTestCase::userBSessionId(), 'body' => null,
                'expect' => self::admin(200),
            ],
        ];
    }

    /**
     * Admin-Route: anonym und deaktiviert 401, normaler User 403, Admin $ok.
     */
    private static function admin(int $ok): array
    {
        return ['anon' => 401, 'user' => 403, 'inactive' => 401, 'admin' => $ok];
    }
}
