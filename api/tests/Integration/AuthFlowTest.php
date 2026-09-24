<?php
declare(strict_types=1);

/**
 * Magic-Link-Login, Session-Gültigkeit und Selbstschutz der Admin-Endpunkte.
 */
class AuthFlowTest extends ApiTestCase
{
    private const LINK_TOKEN = 'b000000000000000000000000000000000000000000000000000000000000001';

    private function verify(string $token): array
    {
        return $this->request('POST', '/api/auth/verify', ['token' => $token]);
    }

    // ── Magic Link ───────────────────────────────────────────────────────────

    public function test_verify_creates_session_and_sets_cookie(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $response = $this->verify(self::LINK_TOKEN);

        $this->assertSame(200, $response['status']);
        $this->assertSame('userA', $response['body']['data']['username']);
        $this->assertFalse($response['body']['data']['is_admin']);
        $this->assertMatchesRegularExpression('/^Set-Cookie: cc_session=[0-9a-f]{64};/mi', $response['headers']);
        $this->assertMatchesRegularExpression('/^Set-Cookie: cc_session=.*HttpOnly/mi', $response['headers']);
    }

    public function test_verify_does_not_return_the_session_token(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $data = $this->verify(self::LINK_TOKEN)['body']['data'];

        $this->assertSame(['username', 'is_admin'], array_keys($data));
    }

    public function test_verify_via_get_is_no_longer_possible(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $this->assertSame(404, $this->request('GET', '/api/auth/verify?token=' . self::LINK_TOKEN)['status']);
        // Das Token bleibt dabei unverbraucht
        $this->assertSame(200, $this->verify(self::LINK_TOKEN)['status']);
    }

    public function test_bearer_header_is_not_accepted(): void
    {
        $response = $this->request('GET', '/api/auth/me', null, null, ['Authorization: Bearer ' . self::TOKEN_A]);

        $this->assertSame(401, $response['status']);
    }

    public function test_verify_token_works_only_once(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);

        $this->assertSame(200, $this->verify(self::LINK_TOKEN)['status']);
        $this->assertSame(401, $this->verify(self::LINK_TOKEN)['status']);
    }

    public function test_verify_rejects_expired_token(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN, '-1 minute');

        $this->assertSame(401, $this->verify(self::LINK_TOKEN)['status']);
    }

    public function test_verify_rejects_token_of_inactive_user(): void
    {
        $this->createMagicLink(self::INACTIVE_ID, self::LINK_TOKEN);

        $this->assertSame(401, $this->verify(self::LINK_TOKEN)['status']);
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

    public function test_logout_all_ends_every_own_session_but_not_others(): void
    {
        $second = $this->createSession(
            self::USER_A_ID,
            'c000000000000000000000000000000000000000000000000000000000000002'
        );

        $this->assertSame(200, $this->request('POST', '/api/auth/logout-all', null, self::TOKEN_A)['status']);

        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, self::TOKEN_A)['status']);
        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, $second)['status']);
        $this->assertSame(200, $this->request('GET', '/api/auth/me', null, self::TOKEN_B)['status']);
    }

    // ── Tokens nur als Hash gespeichert ──────────────────────────────────────

    public function test_session_is_stored_as_hash_only(): void
    {
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);
        $response = $this->verify(self::LINK_TOKEN);

        preg_match('/^Set-Cookie: cc_session=([0-9a-f]{64});/mi', $response['headers'], $m);
        $rawCookie = $m[1];

        $stored = $this->db()
            ->query('SELECT id FROM sessions WHERE user_id = ' . self::USER_A_ID)
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($rawCookie, $stored, 'Roh-Token darf nicht in der DB stehen');
        $this->assertContains(hash('sha256', $rawCookie), $stored);
        $this->assertSame(200, $this->request('GET', '/api/auth/me', null, $rawCookie)['status']);
    }

    public function test_magic_link_is_stored_as_hash_only(): void
    {
        // Ein Link, dessen Roh-Token direkt in der DB steht, darf nicht funktionieren
        $this->db()->prepare(
            'INSERT INTO magic_links (user_id, token, expires_at) VALUES (?, ?, ?)'
        )->execute([self::USER_A_ID, self::LINK_TOKEN, gmdate('Y-m-d H:i:s', strtotime('+15 minutes'))]);

        $this->assertSame(401, $this->verify(self::LINK_TOKEN)['status']);
    }

    public function test_login_invalidates_other_unused_links(): void
    {
        $other = 'b000000000000000000000000000000000000000000000000000000000000002';
        $this->createMagicLink(self::USER_A_ID, self::LINK_TOKEN);
        $this->createMagicLink(self::USER_A_ID, $other);

        $this->assertSame(200, $this->verify(self::LINK_TOKEN)['status']);
        $this->assertSame(401, $this->verify($other)['status']);
    }

    // ── Admin-Sessionliste ───────────────────────────────────────────────────

    public function test_session_list_ids_cannot_be_used_to_log_in(): void
    {
        $list = $this->request('GET', '/api/admin/sessions', null, self::TOKEN_ADMIN);
        $this->assertSame(200, $list['status']);

        $ids = array_column($list['body']['data'], 'id', 'username');
        $this->assertSame(self::sessionId(self::TOKEN_B), $ids['userB']);

        // Die gelistete Kennung als Cookie verwenden → abgelehnt
        $this->assertSame(401, $this->request('GET', '/api/auth/me', null, $ids['userB'])['status']);
    }

    public function test_session_list_marks_current_session(): void
    {
        $list = $this->request('GET', '/api/admin/sessions', null, self::TOKEN_ADMIN);

        $current = array_filter($list['body']['data'], fn($s) => $s['is_current']);
        $this->assertCount(1, $current);
        $this->assertSame('admin', array_values($current)[0]['username']);
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
