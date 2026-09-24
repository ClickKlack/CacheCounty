<?php
/**
 * PHP Built-in Dev Server Router
 * Bildet die Regeln aus public/.htaccess nach.
 *
 * Starten (aus der Repo-Wurzel):
 *   php -S localhost:8080 -t public scripts/dev-router.php
 *
 * Liegt bewusst außerhalb von public/, damit er in Produktion nie aufrufbar ist.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$public = dirname(__DIR__) . '/public';
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = ltrim($uri, '/');

// API → Front-Controller (auch die Sitemap wird dort erzeugt)
if (preg_match('/^api\//', $path) || $path === 'sitemap.xml') {
    require $public . '/api/index.php';
    return true;
}

// /map/{username}[/{land}] und /country/{land} → index.html
if (preg_match('/^map\/[^\/]+(\/[a-zA-Z]{2})?\/?$/', $path) || preg_match('/^country\/[a-zA-Z]{2}\/?$/', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($public . '/app/index.html');
    return true;
}

// /stats/{username}[/{land}] → stats.html
if (preg_match('/^stats\/[^\/]+(\/[a-zA-Z]{2})?\/?$/', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($public . '/app/stats.html');
    return true;
}

// Root → index.html
if ($path === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($public . '/app/index.html');
    return true;
}

// Statische Dateien (CSS, JS, GeoJSON, …) liefert der Server aus dem Docroot (-t public)
return false;
