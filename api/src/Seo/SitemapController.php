<?php
declare(strict_types=1);

namespace CacheCounty\Seo;

use CacheCounty\Shared\Config;
use CacheCounty\Shared\Database;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

class SitemapController
{
    /**
     * GET /sitemap.xml
     *
     * Lists the start page plus map and statistics page of every active user who has
     * at least one visit – empty maps are left out as thin content. lastmod is the
     * most recent visit change of that user. URLs are absolute, built from base_url.
     */
    public function sitemap(Request $request): void
    {
        $base = rtrim((string) (Config::app()['base_url'] ?? ''), '/');

        $rows = Database::get()->query(
            'SELECT u.username, MAX(v.updated_at) AS last_change
               FROM users u
               JOIN visits v ON v.user_id = u.id
              WHERE u.is_active = 1
              GROUP BY u.id, u.username
              ORDER BY u.username'
        )->fetchAll();

        $entries = [$this->entry($base . '/', null)];
        foreach ($rows as $row) {
            $user    = rawurlencode($row['username']);
            $lastmod = substr((string) $row['last_change'], 0, 10);
            $entries[] = $this->entry("$base/map/$user", $lastmod);
            $entries[] = $this->entry("$base/stats/$user", $lastmod);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
             . implode('', $entries)
             . "</urlset>\n";

        // Crawlers may cache it for an hour; the list only changes with new visits
        Response::raw($xml, 'application/xml; charset=utf-8', ['Cache-Control: public, max-age=3600']);
    }

    private function entry(string $url, ?string $lastmod): string
    {
        $loc = htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return "  <url>\n    <loc>$loc</loc>\n"
             . ($lastmod ? "    <lastmod>$lastmod</lastmod>\n" : '')
             . "  </url>\n";
    }
}
