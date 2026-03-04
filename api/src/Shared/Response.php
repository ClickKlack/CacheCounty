<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null): never
    {
        self::json(['success' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400): never
    {
        self::json(['success' => false, 'error' => $message], $status);
    }

    public static function unauthorized(): never
    {
        self::error('Unauthorized', 401);
    }

    public static function forbidden(): never
    {
        self::error('Forbidden', 403);
    }

    public static function notFound(string $message = 'Not Found'): never
    {
        self::error($message, 404);
    }

}
