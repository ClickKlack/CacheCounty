<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Request
{
    private array  $params = [];
    private ?array $body   = null;
    private ?array $user   = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim($uri, '/') ?: '/';
    }

    /**
     * Authenticated user, set by the Router/Guard once per request.
     *
     * @return array{user_id: int, username: string, is_admin: int}|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function query(string $key): ?string
    {
        return isset($_GET[$key]) ? (string) $_GET[$key] : null;
    }

    public function body(): array
    {
        if ($this->body === null) {
            $raw        = file_get_contents('php://input');
            $this->body = json_decode($raw ?: '{}', true) ?? [];
        }
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $default;
    }

    /**
     * Session token from the HttpOnly cookie – the only supported transport.
     * (A Bearer header is deliberately not accepted: the token must never be
     * readable by JavaScript.)
     */
    public function sessionToken(): ?string
    {
        $token = $_COOKIE['cc_session'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    public function ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Cloudflare: trust CF-Connecting-IP only when explicitly configured –
        // otherwise any client could spoof its IP via this header
        if (!empty(Config::app()['trust_cloudflare']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                return $cfIp;
            }
        }

        // If REMOTE_ADDR is a private/reserved address we are behind a local
        // reverse proxy (e.g. nginx in front of Apache on shared hosting).
        // In that case, use the LAST public IP from X-Forwarded-For: our proxy
        // appends the real client address at the end, everything before it was
        // sent by the client and can be forged (e.g. to bypass rate limits).
        $isPrivate = filter_var(
            $remoteAddr,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;

        if ($isPrivate && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var(
                    $candidate,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) !== false) {
                    return $candidate;
                }
            }
        }

        return $remoteAddr;
    }
}
