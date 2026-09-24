<?php
declare(strict_types=1);

use CacheCounty\Region\RegionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CountryOrderTest extends TestCase
{
    private const COUNTRIES = [
        ['code' => 'AT', 'label' => 'Österreich'],
        ['code' => 'CH', 'label' => 'Schweiz'],
        ['code' => 'DE', 'label' => 'Deutschland', 'pinned' => true],
        ['code' => 'DK', 'label' => 'Dänemark'],
        ['code' => 'NL', 'label' => 'Niederlande'],
    ];

    public static function collationModes(): array
    {
        return ['mit intl' => [true], 'ohne intl (Rückfall)' => [false]];
    }

    #[DataProvider('collationModes')]
    public function test_pinned_country_first_then_alphabetical(bool $useIntl): void
    {
        $codes = array_column(RegionController::sortCountries(self::COUNTRIES, $useIntl), 'code');

        // Dänemark, Niederlande, Österreich (Ö wie O), Schweiz
        $this->assertSame(['DE', 'DK', 'NL', 'AT', 'CH'], $codes);
    }

    public function test_multiple_pinned_countries_keep_config_order(): void
    {
        $countries = [
            ['code' => 'CH', 'label' => 'Schweiz', 'pinned' => true],
            ['code' => 'AT', 'label' => 'Österreich'],
            ['code' => 'DE', 'label' => 'Deutschland', 'pinned' => true],
        ];

        $this->assertSame(['CH', 'DE', 'AT'], array_column(RegionController::sortCountries($countries), 'code'));
    }

    public function test_without_pinned_everything_is_alphabetical(): void
    {
        $countries = [['code' => 'CH', 'label' => 'Schweiz'], ['code' => 'AT', 'label' => 'Österreich']];

        $this->assertSame(['AT', 'CH'], array_column(RegionController::sortCountries($countries), 'code'));
    }
}
