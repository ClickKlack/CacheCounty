<?php
declare(strict_types=1);

/**
 * Autorisierung auf Objektebene: Nutzer dürfen nur ihre eigenen Besuche ändern,
 * und Felder wie user_id aus dem Request dürfen nichts bewirken.
 */
class ObjectLevelTest extends ApiTestCase
{
    private function visitOfUserA(): ?array
    {
        [$cc, $rc] = explode('-', self::VISIT_A_CODE, 2);
        $stmt = $this->db()->prepare(
            'SELECT user_id, visited_at, notes FROM visits WHERE user_id = ? AND country_code = ? AND region_code = ?'
        );
        $stmt->execute([self::USER_A_ID, $cc, $rc]);

        return $stmt->fetch() ?: null;
    }

    public function test_other_user_cannot_update_visit(): void
    {
        $response = $this->request(
            'PUT',
            '/api/regions/' . self::VISIT_A_CODE . '/visit',
            ['visited_at' => '2020-01-01', 'notes' => 'fremd'],
            self::TOKEN_B
        );

        $this->assertSame(404, $response['status']);
        $this->assertSame('alt', $this->visitOfUserA()['notes']);
        $this->assertSame('2024-06-01', $this->visitOfUserA()['visited_at']);
    }

    public function test_other_user_cannot_delete_visit(): void
    {
        $response = $this->request('DELETE', '/api/regions/' . self::VISIT_A_CODE . '/visit', null, self::TOKEN_B);

        $this->assertSame(404, $response['status']);
        $this->assertNotNull($this->visitOfUserA());
    }

    public function test_user_id_in_body_is_ignored_when_adding_visit(): void
    {
        $response = $this->request(
            'POST',
            '/api/regions/DE-01001/visit',
            ['region_name' => 'Flensburg', 'user_id' => self::USER_B_ID],
            self::TOKEN_A
        );

        $this->assertSame(200, $response['status']);

        $owners = $this->db()
            ->query("SELECT user_id FROM visits WHERE country_code = 'DE' AND region_code = '01001'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([self::USER_A_ID], array_map('intval', $owners));
    }

    public function test_admin_update_ignores_fields_outside_allowlist(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/admin/users/' . self::USER_B_ID,
            ['is_active' => true, 'email' => 'gekapert@example.com', 'username' => 'gekapert'],
            self::TOKEN_ADMIN
        );

        $this->assertSame(200, $response['status']);

        $stmt = $this->db()->prepare('SELECT username, email FROM users WHERE id = ?');
        $stmt->execute([self::USER_B_ID]);
        $this->assertSame(['username' => 'userB', 'email' => 'b@example.com'], $stmt->fetch());
    }

    public function test_foreign_map_is_readable_but_writes_hit_own_visits(): void
    {
        // Fremde Karte lesen ist öffentlich …
        $map = $this->request('GET', '/api/map/userA', null, self::TOKEN_B);
        $this->assertSame(200, $map['status']);
        $this->assertCount(1, $map['body']['data']['visits']);

        // … ein Schreibzugriff landet aber immer beim eingeloggten Nutzer
        $this->request('POST', '/api/regions/DE-01001/visit', ['region_name' => 'Flensburg'], self::TOKEN_B);

        $mapA = $this->request('GET', '/api/map/userA');
        $mapB = $this->request('GET', '/api/map/userB');
        $this->assertCount(1, $mapA['body']['data']['visits']);
        $this->assertCount(1, $mapB['body']['data']['visits']);
    }
}
