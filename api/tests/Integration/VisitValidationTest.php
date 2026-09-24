<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Eingabevalidierung für Besuche: Regionscode je Land (Muster aus countries.json),
 * Typ und Länge der Freitextfelder.
 */
class VisitValidationTest extends ApiTestCase
{
    private function add(string $code, array $body = []): array
    {
        return $this->request('POST', "/api/regions/$code/visit", $body ?: ['region_name' => 'X'], self::TOKEN_A);
    }

    private function visitCountOfUserA(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM visits WHERE user_id = ' . self::USER_A_ID)->fetchColumn();
    }

    // ── Regionscode ──────────────────────────────────────────────────────────

    public static function invalidCodes(): array
    {
        return [
            'unbekanntes Land'        => ['XX-12345'],
            'DE zu kurz'              => ['DE-1234'],
            'DE zu lang'              => ['DE-123456'],
            'DE mit Buchstaben'       => ['DE-0916A'],
            'AT zu lang'              => ['AT-1011'],
            'AT mit DE-Format'        => ['AT-09162'],
            'ohne Regionsteil'        => ['DE-'],
            'ohne Trennzeichen'       => ['DE09162'],
            'Ländercode dreistellig'  => ['DEU-09162'],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_region_code_is_rejected(string $code): void
    {
        $this->assertSame(400, $this->add($code)['status'], $code);
        $this->assertSame(1, $this->visitCountOfUserA(), 'Kein Besuch angelegt');
    }

    public function test_valid_codes_are_accepted(): void
    {
        $this->assertSame(200, $this->add('DE-01001')['status']);
        $this->assertSame(200, $this->add('AT-101')['status']);
        $this->assertSame(200, $this->add('de-01002')['status'], 'Ländercode ist case-insensitiv');
        $this->assertSame(4, $this->visitCountOfUserA());
    }

    public function test_update_and_delete_validate_the_code_too(): void
    {
        $this->assertSame(400, $this->request('PUT', '/api/regions/XX-1/visit', ['notes' => 'x'], self::TOKEN_A)['status']);
        $this->assertSame(400, $this->request('DELETE', '/api/regions/DE-1/visit', null, self::TOKEN_A)['status']);
    }

    // ── Freitextfelder ───────────────────────────────────────────────────────

    public function test_notes_up_to_limit_are_accepted_counting_characters_not_bytes(): void
    {
        // 2000 Umlaute = 4000 Bytes, aber 2000 Zeichen
        $response = $this->add('DE-01001', ['notes' => str_repeat('ä', 2000)]);
        $this->assertSame(200, $response['status']);
    }

    public function test_too_long_notes_are_rejected(): void
    {
        $this->assertSame(400, $this->add('DE-01001', ['notes' => str_repeat('a', 2001)])['status']);
        $this->assertSame(
            400,
            $this->request('PUT', '/api/regions/' . self::VISIT_A_CODE . '/visit', ['notes' => str_repeat('a', 2001)], self::TOKEN_A)['status']
        );
    }

    public function test_too_long_region_name_is_rejected(): void
    {
        $this->assertSame(400, $this->add('DE-01001', ['region_name' => str_repeat('a', 256)])['status']);
    }

    public function test_non_string_fields_are_rejected(): void
    {
        $this->assertSame(400, $this->add('DE-01001', ['notes' => ['a' => 'b']])['status']);
        $this->assertSame(400, $this->add('DE-01002', ['region_name' => 42])['status']);
    }

    public function test_empty_text_fields_are_stored_as_null(): void
    {
        $this->assertSame(200, $this->add('DE-01001', ['region_name' => '', 'notes' => ''])['status']);

        $row = $this->db()
            ->query("SELECT region_name, notes FROM visits WHERE region_code = '01001'")
            ->fetch();
        $this->assertSame(['region_name' => null, 'notes' => null], $row);
    }

    public function test_countries_are_ordered_germany_first_then_alphabetical(): void
    {
        $labels = array_column($this->request('GET', '/api/countries')['body']['data'], 'label');

        $this->assertSame('Deutschland', $labels[0]);
        $rest = array_slice($labels, 1);
        $sorted = $rest;
        (new Collator('de_DE'))->sort($sorted);
        $this->assertSame($sorted, $rest);
    }

    public function test_countries_endpoint_does_not_expose_internal_fields(): void
    {
        $countries = $this->request('GET', '/api/countries')['body']['data'];

        $this->assertNotEmpty($countries);
        foreach ($countries as $country) {
            $this->assertArrayNotHasKey('region_code_pattern', $country);
            $this->assertArrayNotHasKey('pinned', $country);
        }
    }
}
