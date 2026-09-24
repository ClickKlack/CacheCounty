<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Öffentliche Seitenadressen im Dev-Router (scripts/dev-router.php).
 *
 * Dieselben Regeln stehen in public/.htaccess – dieser Test hält den Dev-Router
 * aktuell; die .htaccess selbst lässt sich nur mit Apache prüfen.
 */
class PageRoutesTest extends ApiTestCase
{
    public static function pages(): array
    {
        return [
            'Start'                       => ['/',                     'index'],
            'Karte'                       => ['/map/userA',            'index'],
            'Karte mit Slash'             => ['/map/userA/',           'index'],
            'Karte mit Land'              => ['/map/userA/at',         'index'],
            'Karte mit Land groß'         => ['/map/userA/AT/',        'index'],
            'Karte kodierter Name + Land' => ['/map/Ahnungslos%2A/ch', 'index'],
            'Land ohne Nutzer'            => ['/country/dk',           'index'],
            'Statistik'                   => ['/stats/userA',          'stats'],
            'Statistik mit Land'          => ['/stats/userA/at',       'stats'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_address_serves_the_right_html(string $path, string $page): void
    {
        $response = $this->rawRequest('GET', $path, null, []);

        $this->assertSame(200, $response['status'], $path);
        $marker = $page === 'stats' ? 'js/stats.js' : 'js/app.js';
        $this->assertStringContainsString($marker, $response['raw'], $path);
    }

    public static function invalid(): array
    {
        return [['/map/userA/deu'], ['/country/xyz'], ['/country/'], ['/map/a/b/c'], ['/stats/userA/1']];
    }

    #[DataProvider('invalid')]
    public function test_malformed_addresses_are_not_served_as_page(string $path): void
    {
        $this->assertSame(404, $this->rawRequest('GET', $path, null, [])['status'], $path);
    }
}
