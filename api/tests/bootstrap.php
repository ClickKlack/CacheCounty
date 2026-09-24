<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Hilfsklassen der Integrationstests (keine *Test.php-Dateien, daher nicht automatisch geladen)
require __DIR__ . '/Integration/ApiTestCase.php';
require __DIR__ . '/Integration/RouteMatrix.php';
