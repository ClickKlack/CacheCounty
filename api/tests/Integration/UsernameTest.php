<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Nutzernamen wie bei Geocaching: Anlegen über die Admin-API und Erreichbarkeit
 * der öffentlichen Seiten – kodiert und unkodiert.
 */
class UsernameTest extends ApiTestCase
{
    private function createUser(string $username, string $email = 'neu@example.com'): array
    {
        return $this->request('POST', '/api/admin/users', ['username' => $username, 'email' => $email], self::TOKEN_ADMIN);
    }

    public static function allowedNames(): array
    {
        return [
            'Sternchen'      => ['Ahnungslos*'],
            'Leerzeichen'    => ['Max Mustermann'],
            'Umlaute'        => ['Jörg Schöne'],
            'Satzzeichen'    => ["O'Reilly (DE) & Co."],
            'Prozentzeichen' => ['100%Cacher'],
            'genau 60'       => [str_repeat('ä', 60)],
        ];
    }

    #[DataProvider('allowedNames')]
    public function test_geocaching_style_names_can_be_created(string $name): void
    {
        $this->assertSame(201, $this->createUser($name)['status'], $name);
    }

    public static function rejectedNames(): array
    {
        return [
            'Schrägstrich'   => ['a/b'],
            'Backslash'      => ['a\\b'],
            'Tabulator'      => ["a\tb"],
            'Nullbreite'     => ["a\u{200B}b"],
            'zu kurz'        => ['a'],
            'nur Leerzeichen'=> ['   '],
            'zu lang'        => [str_repeat('x', 61)],
        ];
    }

    #[DataProvider('rejectedNames')]
    public function test_invalid_names_are_rejected(string $name): void
    {
        $this->assertSame(400, $this->createUser($name)['status']);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->createUser('  Ahnungslos*  ');

        $stored = $this->db()->query("SELECT username FROM users WHERE email = 'neu@example.com'")->fetchColumn();
        $this->assertSame('Ahnungslos*', $stored);
    }

    public static function specialNames(): array
    {
        return [['Ahnungslos*'], ['Max Mustermann'], ['Jörg Schöne'], ["O'Reilly (DE)"], ['100%Cacher']];
    }

    #[DataProvider('specialNames')]
    public function test_pages_are_reachable_with_encoded_and_plain_name(string $name): void
    {
        $this->createUser($name);

        // encodeURIComponent-Form (Frontend) und vollständig kodierte Form (rawurlencode)
        foreach (['/api/map/', '/api/stats/'] as $prefix) {
            foreach ([rawurlencode($name), strtr(rawurlencode($name), ['%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'])] as $segment) {
                $response = $this->request('GET', $prefix . $segment);
                $this->assertSame(200, $response['status'], "$prefix$segment");
                $this->assertSame($name, $response['body']['data']['username']);
            }
        }
    }

    public function test_sitemap_uses_the_same_encoding_as_the_frontend(): void
    {
        $this->createUser('Ahnungslos*');
        $id = (int) $this->db()->query("SELECT id FROM users WHERE username = 'Ahnungslos*'")->fetchColumn();
        $this->db()->exec("INSERT INTO visits (user_id, country_code, region_code) VALUES ($id, 'DE', '01001')");

        $xml = $this->rawRequest('GET', '/sitemap.xml', null, [])['raw'];

        $this->assertStringContainsString('/map/Ahnungslos*</loc>', $xml);
        $this->assertStringNotContainsString('%2A', $xml);
    }
}
