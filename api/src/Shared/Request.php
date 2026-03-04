<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Request
{
    private array  $params = [];
    private ?array $body   = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim($uri, '/') ?: '/';
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

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    public function sessionToken(): ?string
    {
        return $_COOKIE['cc_session'] ?? $this->bearerToken();
    }

    public function ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Cloudflare: always trust CF-Connecting-IP when present
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                return $cfIp;
            }
        }

        // If REMOTE_ADDR is a private/reserved address we are behind a local
        // reverse proxy (e.g. Apache mod_proxy on shared hosting).
        // In that case, pick the first public IP from X-Forwarded-For.
        $isPrivate = filter_var(
            $remoteAddr,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;

        if ($isPrivate && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
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
