<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

/**
 * CSRF protection for state-changing requests (POST, PUT, PATCH, DELETE).
 *
 * Authentication is cookie-only, so a foreign page must not be able to trigger
 * requests that the browser decorates with the session cookie. Two checks:
 *
 * 1. Origin: the request must come from our own origin. Accepted when the browser
 *    reports Sec-Fetch-Site: same-origin, or when Origin (fallback: Referer) matches
 *    base_url or one of allowed_origins. A request without any of these headers is
 *    rejected – there are no cookie-less API clients.
 * 2. Content type: a body must be sent as application/json. Browsers only allow
 *    this cross-origin after a CORS preflight, which the API never grants.
 */
class OriginCheck
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Rejects the request (exits) if one of the checks fails.
     */
    public static function enforce(Request $request): void
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return;
        }

        if (!self::originAllowed()) {
            Response::error('Cross-origin request rejected.', 403);
        }

        if (!self::contentTypeAllowed()) {
            Response::error('Request body must be JSON.', 415);
        }
    }

    public static function originAllowed(): bool
    {
        // Set by the browser, cannot be influenced by page scripts
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') {
            return true;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? self::originOf($_SERVER['HTTP_REFERER'] ?? '');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return false;
        }

        return in_array(self::normalize($origin), self::allowedOrigins(), true);
    }

    public static function contentTypeAllowed(): bool
    {
        $hasBody = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
        if (!$hasBody) {
            return true;
        }

        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        return $type === 'application/json';
    }

    /** @return list<string> */
    private static function allowedOrigins(): array
    {
        $config  = Config::app();
        $origins = array_merge([(string) ($config['base_url'] ?? '')], $config['allowed_origins'] ?? []);

        return array_values(array_filter(array_map(
            fn($o) => self::originOf((string) $o),
            $origins
        )));
    }

    /** "https://example.com:443/path" → "https://example.com" */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        return self::normalize($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }

    /** Lower-case scheme and host, drop default ports */
    private static function normalize(string $origin): string
    {
        $origin = strtolower(rtrim($origin, '/'));
        return preg_replace(['#^(https://[^/]+):443$#', '#^(http://[^/]+):80$#'], '$1', $origin);
    }
}
