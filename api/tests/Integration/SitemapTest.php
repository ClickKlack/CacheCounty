<?php
declare(strict_types=1);

/**
 * /sitemap.xml: Startseite plus Karte und Statistik aktiver Nutzer mit Besuchen.
 *
 * Fixtures: User A hat einen Besuch, User B und der Admin keinen, der deaktivierte
 * Admin ist inaktiv.
 */
class SitemapTest extends ApiTestCase
{
    /** @return array{0: array, 1: string} Antwort und XML-Text */
    private function sitemap(): array
    {
        $response = $this->rawRequest('GET', '/sitemap.xml', null, []);

        return [$response, $response['raw']];
    }

    /** @return list<string> alle <loc>-Einträge */
    private function locations(string $xml): array
    {
        $doc = new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        return array_map('strval', $doc->xpath('//s:url/s:loc'));
    }

    public function test_sitemap_is_valid_xml_with_correct_content_type(): void
    {
        [$response, $xml] = $this->sitemap();

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('#^Content-Type: application/xml#mi', $response['headers']);
        $this->assertStringStartsWith('<?xml', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_sitemap_lists_start_page_and_pages_of_users_with_visits(): void
    {
        [, $xml] = $this->sitemap();
        $base    = self::baseUrl();

        $this->assertSame(
            ["$base/", "$base/map/userA", "$base/stats/userA"],
            $this->locations($xml)
        );
    }

    public function test_users_without_visits_or_inactive_users_are_left_out(): void
    {
        // Besuch für den deaktivierten Admin – er darf trotzdem nicht erscheinen
        $this->db()->exec(
            "INSERT INTO visits (user_id, country_code, region_code) VALUES (" . self::INACTIVE_ID . ", 'DE', '01001')"
        );

        $locations = implode(' ', $this->locations($this->sitemap()[1]));

        $this->assertStringNotContainsString('userB', $locations);
        $this->assertStringNotContainsString('/admin', $locations);
        $this->assertStringNotContainsString('inactive', $locations);
    }

    public function test_lastmod_is_the_date_of_the_latest_visit_change(): void
    {
        $this->db()->exec("UPDATE visits SET updated_at = '2025-03-14 10:00:00' WHERE user_id = " . self::USER_A_ID);

        $this->assertStringContainsString('<lastmod>2025-03-14</lastmod>', $this->sitemap()[1]);
    }
}
