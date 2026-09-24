<?php
declare(strict_types=1);

/**
 * Magic-Link-Login, Session-Gültigkeit und Selbstschutz der Admin-Endpunkte.
 */
class AuthFlowTest extends ApiTestCase
{
    private const LINK_TOKEN = 'b000000000000000000000000000000000000000000000000000000000000001';

    // ── Magic Link ───────────────────────────────────────────────────────────

    public function test_verify_creates_session_and_sets_cookie(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $response = $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN);

        $this->assertSame(200, $response['status']);
        $this->assertSame('userA', $response['body']['data']['username']);
        $this->assertFalse($response['body']['data']['is_admin']);
        $this->assertMatchesRegularExpression('/^Set-Cookie: cc_session=[0-9a-f]{64};/mi', $response['headers']);
        $this->assertMatchesRegularExpression('/^Set-Cookie: cc_session=.*HttpOnly/mi', $response['headers']);
    }

    public function test_verify_token_works_only_once(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $this->assertSame(200, $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN)['status']);
        $this->assertSame(401, $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN)['status']);
    }

    public function test_verify_rejects_expired_token(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN, '-1 minute');

        $this->assertSame(401, $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN)['status']);
    }

    public function test_verify_rejects_token_of_inactive_user(): void
    {
        $this->createMagicLink(self::INACTIVE_ID, self::LINK_TOKEN);

        $this->assertSame(401, $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN)['status']);
    }

    // ── Session-Gültigkeit ───────────────────────────────────────────────────

    public function test_expired_session_is_rejected(): void
    {
        $token = $this->createSession(
            self::USER_B_ID,
            'c000000000000000000000000000000000000000000000000000000000000001',
            '-1 minute'
        );

        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, $token)['status']);
    }

    public function test_deactivating_user_invalidates_existing_session(): void
    {
        $this->assertSame(200, $this->request('GET', '/api/auth/me', null, self::TOKEN_A)['status']);

        $this->db()->exec('UPDATE users SET is_active = 0 WHERE id = ' . self::USER_A_ID);

        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, self::TOKEN_A)['status']);
    }

    public function test_revoking_admin_takes_effect_on_same_session(): void
    {
        $this->assertSame(200, $this->request('GET', '/api/admin/users', null, self::TOKEN_ADMIN)['status']);

        $this->db()->exec('UPDATE users SET is_admin = 0 WHERE id = ' . self::ADMIN_ID);

        $this->assertSame(403, $this->request('GET', '/api/admin/users', null, self::TOKEN_ADMIN)['status']);
    }

    public function test_session_deleted_by_admin_is_rejected(): void
    {
        $this->request('DELETE', '/api/admin/sessions/' . self::userBSessionId(), null, self::TOKEN_ADMIN);

        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, self::TOKEN_B)['status']);
    }

    public function test_logout_invalidates_session(): void
    {
        $this->request('POST', '/api/auth/logout', null, self::TOKEN_A);

        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, self::TOKEN_A)['status']);
    }

    // ── Selbstschutz Admin ───────────────────────────────────────────────────

    public function test_admin_cannot_modify_own_account(): void
    {
        $response = $this->request('PATCH', '/api/admin/users/' . self::ADMIN_ID, ['is_admin' => false], self::TOKEN_ADMIN);

        $this->assertSame(403, $response['status']);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $response = $this->request('DELETE', '/api/admin/users/' . self::ADMIN_ID, null, self::TOKEN_ADMIN);

        $this->assertSame(403, $response['status']);
    }

    public function test_admin_cannot_delete_own_session(): void
    {
        $response = $this->request('DELETE', '/api/admin/sessions/' . self::sessionId(self::TOKEN_ADMIN), null, self::TOKEN_ADMIN);

        $this->assertSame(403, $response['status']);
    }
}
