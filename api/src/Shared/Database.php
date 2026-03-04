<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            // database.local.php overrides database.php (excluded from VCS)
            $configFile = BASE_PATH . '/config/database.local.php';
            if (!file_exists($configFile)) {
                $configFile = BASE_PATH . '/config/database.php';
            }            $config = require $configFile;

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['name']
            );

            try {
                self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$instance->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                // Do not leak connection details
                Response::error('Database connection failed', 500);
            }
        }

        return self::$instance;
    }
}
