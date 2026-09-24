<?php
declare(strict_types=1);

/**
 * Header, die jede API-Antwort tragen muss – und solche, die sie nicht tragen darf.
 */
class ApiHeadersTest extends ApiTestCase
{
    private function headersOf(string $path): string
    {
        return $this->request('GET', $path)['headers'];
    }

    public function test_api_responses_are_not_indexed(): void
    {
        $this->assertMatchesRegularExpression('/^X-Robots-Tag: noindex\r?$/mi', $this->headersOf('/api/countries'));
    }

    public function test_api_responses_are_not_cached_and_not_sniffed(): void
    {
        $headers = $this->headersOf('/api/leaderboard');

        $this->assertMatchesRegularExpression('/^Cache-Control: no-store\r?$/mi', $headers);
        $this->assertMatchesRegularExpression('/^X-Content-Type-Options: nosniff\r?$/mi', $headers);
    }

    public function test_api_sends_no_cors_headers(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^Access-Control-Allow-/mi', $this->headersOf('/api/countries'));
    }

    public function test_error_responses_carry_the_same_headers(): void
    {
        $headers = $this->request('GET', '/api/auth/me')['headers'];   // 401

        $this->assertMatchesRegularExpression('/^X-Robots-Tag: noindex\r?$/mi', $headers);
        $this->assertMatchesRegularExpression('/^Cache-Control: no-store\r?$/mi', $headers);
    }
}
