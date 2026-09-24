<?php
declare(strict_types=1);

use CacheCounty\Auth\AuthController;
use CacheCounty\Region\RegionController;
use CacheCounty\Admin\AdminController;
use CacheCounty\Stats\StatsController;
use CacheCounty\Seo\SitemapController;
use CacheCounty\Shared\Access;

// Jede Route braucht eine Zugriffsstufe (Access::Public|User|Admin) – ohne sie
// schlägt die Registrierung fehl. Der Router prüft sie, bevor der Controller läuft.
// Neue Route? Auch in api/tests/Integration/RouteMatrix.php eintragen.

// --- Öffentlich ---
$router->get('/api/countries',               [RegionController::class, 'countries'],   Access::Public);
$router->get('/api/map/{username}',          [RegionController::class, 'mapByUser'],   Access::Public);

// Stats: leaderboard vor {username} definieren, damit es nicht als Username gilt
$router->get('/api/leaderboard',             [StatsController::class, 'leaderboard'],  Access::Public);
$router->get('/api/stats/{username}',        [StatsController::class, 'userStats'],    Access::Public);

// Sitemap für Suchmaschinen (per Rewrite unter /sitemap.xml erreichbar)
$router->get('/sitemap.xml',                 [SitemapController::class, 'sitemap'],    Access::Public);

// Login und Logout funktionieren ohne (gültige) Session
$router->post('/api/auth/magic-link',        [AuthController::class, 'requestMagicLink'], Access::Public);
$router->post('/api/auth/verify',            [AuthController::class, 'verifyToken'],   Access::Public);
$router->post('/api/auth/logout',            [AuthController::class, 'logout'],        Access::Public);

// --- Eingeloggte Nutzer ---
$router->get('/api/auth/me',                 [AuthController::class, 'me'],            Access::User);
$router->post('/api/auth/logout-all',        [AuthController::class, 'logoutAll'],     Access::User);
$router->post('/api/regions/{code}/visit',   [RegionController::class, 'addVisit'],    Access::User);
$router->put('/api/regions/{code}/visit',    [RegionController::class, 'updateVisit'], Access::User);
$router->delete('/api/regions/{code}/visit', [RegionController::class, 'removeVisit'], Access::User);

// --- Admin ---
$router->get('/api/admin/users',             [AdminController::class, 'listUsers'],     Access::Admin);
$router->post('/api/admin/users',            [AdminController::class, 'createUser'],    Access::Admin);
$router->patch('/api/admin/users/{id}',      [AdminController::class, 'updateUser'],    Access::Admin);
$router->delete('/api/admin/users/{id}',     [AdminController::class, 'deleteUser'],    Access::Admin);
$router->get('/api/admin/sessions',          [AdminController::class, 'listSessions'],  Access::Admin);
$router->delete('/api/admin/sessions/{token}', [AdminController::class, 'deleteSession'], Access::Admin);
