<?php
declare(strict_types=1);

use CacheCounty\Shared\Config;
use CacheCounty\Shared\OriginCheck;
use PHPUnit\Framework\TestCase;

class OriginCheckTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
        Config::override([
            'base_url'        => 'https://cachecounty.example',
            'allowed_origins' => ['http://localhost:8080'],
        ]);
    }

    protected function tearDown(): void
    {
        Config::override(null);
    }

    // ── Origin ────────────────────────────────────────────────────

    public function test_same_origin_fetch_metadata_is_allowed(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $this->assertTrue(OriginCheck::originAllowed());
    }

    public function test_same_site_subdomain_is_rejected(): void
    {
        // z. B. eine andere Subdomain derselben Domain
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-site';
        $_SERVER['HTTP_ORIGIN']         = 'https://evil.cachecounty.example';
        $this->assertFalse(OriginCheck::originAllowed());
    }

    public function test_origin_matching_base_url_is_allowed(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://cachecounty.example';
        $this->assertTrue(OriginCheck::originAllowed());
    }

    public function test_origin_with_default_port_and_uppercase_is_allowed(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'HTTPS://CacheCounty.example:443';
        $this->assertTrue(OriginCheck::originAllowed());
    }

    public function test_origin_from_allowed_origins_is_allowed(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'http://localhost:8080';
        $this->assertTrue(OriginCheck::originAllowed());
    }

    public function test_foreign_origin_is_rejected(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $_SERVER['HTTP_ORIGIN']         = 'https://evil.example';
        $this->assertFalse(OriginCheck::originAllowed());
    }

    public function test_http_variant_of_base_url_is_rejected(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'http://cachecounty.example';
        $this->assertFalse(OriginCheck::originAllowed());
    }

    public function test_null_origin_is_rejected(): void
    {
        // Sandboxed iframes und data:-URLs senden "null"
        $_SERVER['HTTP_ORIGIN'] = 'null';
        $this->assertFalse(OriginCheck::originAllowed());
    }

    public function test_referer_is_used_when_origin_is_missing(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://cachecounty.example/app/?x=1';
        $this->assertTrue(OriginCheck::originAllowed());
    }

    public function test_foreign_referer_is_rejected(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/cachecounty.example';
        $this->assertFalse(OriginCheck::originAllowed());
    }

    public function test_request_without_any_origin_information_is_rejected(): void
    {
        $this->assertFalse(OriginCheck::originAllowed());
    }

    // ── Content-Type ──────────────────────────────────────────────

    public function test_json_body_is_allowed(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '12';
        $_SERVER['CONTENT_TYPE']   = 'application/json; charset=utf-8';
        $this->assertTrue(OriginCheck::contentTypeAllowed());
    }

    public function test_form_or_text_body_is_rejected(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '12';
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', ''] as $type) {
            $_SERVER['CONTENT_TYPE'] = $type;
            $this->assertFalse(OriginCheck::contentTypeAllowed(), "Content-Type \"$type\"");
        }
    }

    public function test_request_without_body_needs_no_content_type(): void
    {
        $this->assertTrue(OriginCheck::contentTypeAllowed());
    }
}
