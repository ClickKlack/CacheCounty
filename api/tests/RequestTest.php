<?php
declare(strict_types=1);

use CacheCounty\Shared\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    private Request $req;

    protected function setUp(): void
    {
        // Reset superglobals before each test
        $_SERVER  = [];
        $_GET     = [];
        $_COOKIE  = [];
        $this->req = new Request();
    }

    // ── method() ──────────────────────────────────────────────────

    public function test_method_returns_uppercase(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $this->assertSame('POST', $this->req->method());
    }

    public function test_method_defaults_to_get(): void
    {
        $this->assertSame('GET', $this->req->method());
    }

    // ── uri() ─────────────────────────────────────────────────────

    public function test_uri_strips_trailing_slash(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/map/user/';
        $this->assertSame('/api/map/user', $this->req->uri());
    }

    public function test_uri_returns_slash_for_root(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame('/', $this->req->uri());
    }

    public function test_uri_ignores_query_string(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/auth/verify?token=abc123';
        $this->assertSame('/api/auth/verify', $this->req->uri());
    }

    // ── param() / setParams() ─────────────────────────────────────

    public function test_param_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->req->param('username'));
    }

    public function test_setParams_and_param(): void
    {
        $this->req->setParams(['username' => 'MaxMustermann', 'code' => 'DE-09162']);
        $this->assertSame('MaxMustermann', $this->req->param('username'));
        $this->assertSame('DE-09162', $this->req->param('code'));
    }

    // ── bearerToken() ─────────────────────────────────────────────

    public function test_bearerToken_extracts_from_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mytoken123';
        $this->assertSame('mytoken123', $this->req->bearerToken());
    }

    public function test_bearerToken_returns_null_without_header(): void
    {
        $this->assertNull($this->req->bearerToken());
    }

    public function test_bearerToken_returns_null_for_non_bearer_scheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $this->assertNull($this->req->bearerToken());
    }

    // ── sessionToken() ────────────────────────────────────────────

    public function test_sessionToken_prefers_cookie_over_bearer(): void
    {
        $_COOKIE['cc_session']         = 'cookie-token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer-token';
        $this->assertSame('cookie-token', $this->req->sessionToken());
    }

    public function test_sessionToken_falls_back_to_bearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer-token';
        $this->assertSame('bearer-token', $this->req->sessionToken());
    }

    public function test_sessionToken_returns_null_without_either(): void
    {
        $this->assertNull($this->req->sessionToken());
    }

    // ── ip() ──────────────────────────────────────────────────────

    public function test_ip_returns_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->assertSame('203.0.113.10', $this->req->ip());
    }

    public function test_ip_prefers_cloudflare_header(): void
    {
        $_SERVER['REMOTE_ADDR']              = '203.0.113.10';
        $_SERVER['HTTP_CF_CONNECTING_IP']    = '198.51.100.42';
        $this->assertSame('198.51.100.42', $this->req->ip());
    }

    public function test_ip_ignores_invalid_cloudflare_header(): void
    {
        $_SERVER['REMOTE_ADDR']           = '203.0.113.10';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
        $this->assertSame('203.0.113.10', $this->req->ip());
    }

    public function test_ip_uses_x_forwarded_for_behind_local_proxy(): void
    {
        $_SERVER['REMOTE_ADDR']          = '127.0.0.1';           // private → behind proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 198.51.100.5'; // first public IP
        $this->assertSame('198.51.100.5', $this->req->ip());
    }

    public function test_ip_skips_private_x_forwarded_for_ips(): void
    {
        $_SERVER['REMOTE_ADDR']          = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.2, 192.168.1.1, 203.0.113.99';
        $this->assertSame('203.0.113.99', $this->req->ip());
    }
}
