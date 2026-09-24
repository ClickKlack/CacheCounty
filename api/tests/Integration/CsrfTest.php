<?php
declare(strict_types=1);

/**
 * Schutz schreibender Requests gegen Cross-Site-Angriffe (Origin-Prüfung im Router).
 *
 * Nachgestellt wird jeweils ein Angriff mit gültigem Session-Cookie des Opfers –
 * so, wie der Browser ihn von einer fremden Seite aus mitschicken würde.
 */
class CsrfTest extends ApiTestCase
{
    private const VISIT_BODY = '{"region_name":"Flensburg"}';

    private function countVisitsOfUserA(): int
    {
        return (int) $this->db()
            ->query('SELECT COUNT(*) FROM visits WHERE user_id = ' . self::USER_A_ID)
            ->fetchColumn();
    }

    public function test_foreign_origin_is_rejected_and_nothing_changes(): void
    {
        $response = $this->rawRequest('POST', '/api/regions/DE-01001/visit', self::VISIT_BODY, [
            'Content-Type: application/json',
            'Origin: https://evil.example',
            'Sec-Fetch-Site: cross-site',
        ], self::TOKEN_A);

        $this->assertSame(403, $response['status']);
        $this->assertSame(1, $this->countVisitsOfUserA());
    }

    public function test_request_without_origin_information_is_rejected(): void
    {
        $response = $this->rawRequest('POST', '/api/regions/DE-01001/visit', self::VISIT_BODY, [
            'Content-Type: application/json',
        ], self::TOKEN_A);

        $this->assertSame(403, $response['status']);
    }

    public function test_same_origin_fetch_metadata_is_enough(): void
    {
        // Moderner Browser auf derselben Origin, ohne Origin-Header
        $response = $this->rawRequest('POST', '/api/regions/DE-01001/visit', self::VISIT_BODY, [
            'Content-Type: application/json',
            'Sec-Fetch-Site: same-origin',
        ], self::TOKEN_A);

        $this->assertSame(200, $response['status']);
    }

    public function test_form_encoded_body_is_rejected_even_from_own_origin(): void
    {
        // Ein <form>-Post von einer fremden Seite kann nur solche Content-Types senden
        $response = $this->rawRequest('POST', '/api/regions/DE-01001/visit', 'region_name=Flensburg', [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . self::baseUrl(),
        ], self::TOKEN_A);

        $this->assertSame(415, $response['status']);
        $this->assertSame(1, $this->countVisitsOfUserA());
    }

    public function test_text_plain_json_is_rejected(): void
    {
        // Klassischer Trick: JSON als text/plain, um den CORS-Preflight zu umgehen
        $response = $this->rawRequest('POST', '/api/regions/DE-01001/visit', self::VISIT_BODY, [
            'Content-Type: text/plain',
            'Origin: ' . self::baseUrl(),
        ], self::TOKEN_A);

        $this->assertSame(415, $response['status']);
    }

    public function test_foreign_origin_cannot_delete_session_as_admin(): void
    {
        $response = $this->rawRequest('DELETE', '/api/admin/sessions/' . self::userBSessionId(), null, [
            'Origin: https://evil.example',
        ], self::TOKEN_ADMIN);

        $this->assertSame(403, $response['status']);
        $this->assertSame(200, $this->request('GET', '/api/auth/me', null, self::TOKEN_B)['status']);
    }

    public function test_login_csrf_via_foreign_origin_is_rejected(): void
    {
        // Angreifer will das Opfer in sein eigenes Konto einloggen
        $this->createMagicLink(self::USER_B_ID, 'b000000000000000000000000000000000000000000000000000000000000009');

        $response = $this->rawRequest(
            'POST',
            '/api/auth/verify',
            '{"token":"b000000000000000000000000000000000000000000000000000000000000009"}',
            ['Content-Type: application/json', 'Origin: https://evil.example']
        );

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsStringIgnoringCase('Set-Cookie: cc_session', $response['headers']);
    }

    public function test_reading_is_not_affected(): void
    {
        // GET ohne Origin (z. B. Link-Aufruf, curl) bleibt möglich
        $response = $this->rawRequest('GET', '/api/leaderboard', null, []);

        $this->assertSame(200, $response['status']);
    }

    public function test_head_request_is_answered_like_get(): void
    {
        $response = $this->rawRequest('HEAD', '/api/countries', null, []);

        $this->assertSame(200, $response['status']);
    }
}
