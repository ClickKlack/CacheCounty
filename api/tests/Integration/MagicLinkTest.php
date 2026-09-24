<?php
declare(strict_types=1);

/**
 * Magic-Link-Anforderung: Rate Limiting und Schutz vor E-Mail-Enumeration.
 *
 * Die Test-Konfiguration setzt kleine Limits (5 pro IP und Stunde, 3 pro Konto in
 * 15 Minuten) und eine Mindestantwortzeit von 150 ms. SMTP zeigt auf einen
 * geschlossenen Port – der Versand an bekannte Adressen scheitert also immer.
 */
class MagicLinkTest extends ApiTestCase
{
    private const IP_LIMIT       = 5;
    private const USER_LIMIT     = 3;
    private const MIN_RESPONSE_S = 0.15;

    private function requestLink(string $email, array $extraHeaders = []): array
    {
        return $this->request('POST', '/api/auth/magic-link', ['email' => $email], null, $extraHeaders);
    }

    private function linkCount(int $userId): int
    {
        return (int) $this->db()
            ->query('SELECT COUNT(*) FROM magic_links WHERE user_id = ' . $userId)
            ->fetchColumn();
    }

    // ── Enumeration ──────────────────────────────────────────────────────────

    public function test_known_and_unknown_address_get_identical_response(): void
    {
        $known   = $this->requestLink('a@example.com');
        $unknown = $this->requestLink('niemand@example.com');

        $this->assertSame(200, $known['status']);
        $this->assertSame($unknown['status'], $known['status']);
        $this->assertSame($unknown['body'], $known['body']);
    }

    public function test_mail_failure_does_not_change_the_response(): void
    {
        // SMTP ist in den Tests nicht erreichbar – früher gab das nur für bekannte Adressen 500
        $response = $this->requestLink('a@example.com');

        $this->assertSame(200, $response['status']);
        $this->assertSame(1, $this->linkCount(self::USER_A_ID), 'Link wird trotzdem angelegt');
    }

    public function test_response_takes_at_least_the_minimum_time(): void
    {
        foreach (['a@example.com', 'niemand@example.com'] as $email) {
            $start = microtime(true);
            $this->requestLink($email);
            $this->assertGreaterThanOrEqual(self::MIN_RESPONSE_S, microtime(true) - $start, $email);
        }
    }

    public function test_invalid_address_is_rejected(): void
    {
        $this->assertSame(400, $this->requestLink('keine-adresse')['status']);
    }

    // ── Rate Limiting ────────────────────────────────────────────────────────

    public function test_ip_limit_returns_429(): void
    {
        for ($i = 1; $i <= self::IP_LIMIT; $i++) {
            $this->assertSame(200, $this->requestLink("unbekannt$i@example.com")['status'], "Anfrage $i");
        }

        $response = $this->requestLink('noch-einer@example.com');
        $this->assertSame(429, $response['status']);
    }

    public function test_ip_limit_counts_known_and_unknown_addresses_alike(): void
    {
        for ($i = 1; $i <= self::IP_LIMIT; $i++) {
            $this->requestLink($i % 2 ? 'a@example.com' : "unbekannt$i@example.com");
        }

        $this->assertSame(429, $this->requestLink('b@example.com')['status']);
    }

    public function test_forged_x_forwarded_for_does_not_bypass_ip_limit(): void
    {
        // Wie in Produktion hinter nginx: Der Client schickt bei jeder Anfrage eine andere
        // gefälschte IP, der Proxy hängt die echte (hier 203.0.113.50) hinten an.
        // Der Test-Server läuft auf 127.0.0.1, also wird X-Forwarded-For ausgewertet.
        for ($i = 1; $i <= self::IP_LIMIT; $i++) {
            $this->requestLink("x$i@example.com", ["X-Forwarded-For: 198.51.100.$i, 203.0.113.50"]);
        }

        $response = $this->requestLink('x-last@example.com', ['X-Forwarded-For: 198.51.100.99, 203.0.113.50']);
        $this->assertSame(429, $response['status']);

        $ips = $this->db()->query('SELECT DISTINCT ip_address FROM auth_attempts')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['203.0.113.50'], $ips, 'Gezählt wird die vom Proxy angehängte Adresse');
    }

    public function test_user_limit_silently_stops_new_links(): void
    {
        for ($i = 1; $i <= self::USER_LIMIT + 1; $i++) {
            $response = $this->requestLink('a@example.com');
            $this->assertSame(200, $response['status'], "Anfrage $i – Antwort bleibt generisch");
        }

        $this->assertSame(self::USER_LIMIT, $this->linkCount(self::USER_A_ID));
    }

    public function test_user_limit_does_not_affect_other_accounts(): void
    {
        for ($i = 1; $i <= self::USER_LIMIT; $i++) {
            $this->requestLink('a@example.com');
        }

        $this->requestLink('b@example.com');
        $this->assertSame(1, $this->linkCount(self::USER_B_ID));
    }

    public function test_attempts_are_recorded_per_ip(): void
    {
        $this->requestLink('niemand@example.com');

        $rows = $this->db()->query('SELECT ip_address FROM auth_attempts')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['127.0.0.1'], $rows);
    }
}
