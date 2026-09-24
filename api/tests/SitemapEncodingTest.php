<?php
declare(strict_types=1);

use CacheCounty\Seo\SitemapController;
use PHPUnit\Framework\TestCase;

/**
 * Die Sitemap muss Nutzernamen genau wie encodeURIComponent() im Frontend kodieren,
 * sonst weichen Sitemap-URL und canonical voneinander ab.
 */
class SitemapEncodingTest extends TestCase
{
    public function test_matches_javascript_encodeURIComponent(): void
    {
        // Erwartungswerte = encodeURIComponent(...) im Browser
        $cases = [
            'Ahnungslos*'    => 'Ahnungslos*',
            "O'Reilly (DE)!" => "O'Reilly%20(DE)!",
            'Jörg Schöne'    => 'J%C3%B6rg%20Sch%C3%B6ne',
            '100%Cacher'     => '100%25Cacher',
            'a&b=c?d#e'      => 'a%26b%3Dc%3Fd%23e',
            'tilde~_-.'      => 'tilde~_-.',
        ];
        foreach ($cases as $in => $expected) {
            $this->assertSame($expected, SitemapController::encodeLikeJs($in), $in);
        }
    }
}
