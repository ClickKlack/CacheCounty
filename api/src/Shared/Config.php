<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Config
{
    private static ?array $app = null;

    /**
     * Returns the application config.
     * CACHECOUNTY_APP_CONFIG (integration tests) > app.local.php > app.php.
     */
    public static function app(): array
    {
        if (self::$app === null) {
            $configFile = getenv('CACHECOUNTY_APP_CONFIG') ?: BASE_PATH . '/config/app.local.php';
            if (!file_exists($configFile)) {
                $configFile = BASE_PATH . '/config/app.php';
            }
            self::$app = require $configFile;
        }

        return self::$app;
    }

    /**
     * Returns true if the application is served via HTTPS according to base_url.
     * Deliberately not derived from $_SERVER['HTTPS'], which is often missing
     * behind a TLS-terminating reverse proxy.
     */
    public static function isHttps(): bool
    {
        return str_starts_with((string) (self::app()['base_url'] ?? ''), 'https://');
    }

    /**
     * Replaces the config for tests. Pass null to reload from disk.
     */
    public static function override(?array $config): void
    {
        self::$app = $config;
    }
}
