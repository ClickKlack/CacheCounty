<?php
declare(strict_types=1);

use CacheCounty\Auth\AuthController;
use CacheCounty\Region\RegionController;
use CacheCounty\Admin\AdminController;
use CacheCounty\Stats\StatsController;

// --- Public ---
$router->get('/api/countries',               [RegionController::class, 'countries']);
$router->get('/api/map/{username}',          [RegionController::class, 'mapByUser']);

// --- Stats (leaderboard vor {username} definieren, damit es nicht als Username gilt) ---
$router->get('/api/leaderboard',             [StatsController::class, 'leaderboard']);
$router->get('/api/stats/{username}',        [StatsController::class, 'userStats']);

// --- Auth ---
$router->post('/api/auth/magic-link',        [AuthController::class, 'requestMagicLink']);
$router->get('/api/auth/verify',             [AuthController::class, 'verifyToken']);
$router->get('/api/auth/me',                 [AuthController::class, 'me']);
$router->post('/api/auth/logout',            [AuthController::class, 'logout']);

// --- Authenticated (User) ---
$router->post('/api/regions/{code}/visit',   [RegionController::class, 'addVisit']);
$router->put('/api/regions/{code}/visit',    [RegionController::class, 'updateVisit']);
$router->delete('/api/regions/{code}/visit', [RegionController::class, 'removeVisit']);

// --- Admin ---
$router->get('/api/admin/users',                    [AdminController::class, 'listUsers']);
$router->post('/api/admin/users',                   [AdminController::class, 'createUser']);
$router->patch('/api/admin/users/{id}',             [AdminController::class, 'updateUser']);
$router->delete('/api/admin/users/{id}',            [AdminController::class, 'deleteUser']);
$router->get('/api/admin/sessions',                 [AdminController::class, 'listSessions']);
$router->delete('/api/admin/sessions/{token}',      [AdminController::class, 'deleteSession']);
