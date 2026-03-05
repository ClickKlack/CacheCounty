<?php
declare(strict_types=1);

namespace CacheCounty\Stats;

use CacheCounty\Shared\Database;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

class StatsController
{
    /**
     * GET /api/stats/{username}
     *
     * Returns aggregated statistics for a user (public, no auth required).
     */
    public function userStats(Request $request): void
    {
        $username = $request->param('username');

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id FROM users WHERE username = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User not found.');
        }

        // Timeline: visits grouped by year-month and country
        // Falls kein visited_at gesetzt ist, wird created_at als Fallback verwendet
        $stmtTimeline = $db->prepare(
            'SELECT
               LEFT(COALESCE(visited_at, DATE(created_at)), 7) AS month_key,
               country_code,
               COUNT(*) AS count
             FROM visits
             WHERE user_id = ?
             GROUP BY LEFT(COALESCE(visited_at, DATE(created_at)), 7), country_code
             ORDER BY month_key ASC'
        );
        $stmtTimeline->execute([$user['id']]);
        $timeline = $stmtTimeline->fetchAll();

        // First visit per country
        $stmtFirst = $db->prepare(
            "SELECT
               country_code,
               MIN(COALESCE(visited_at, DATE(created_at))) AS first_date
             FROM visits
             WHERE user_id = ?
             GROUP BY country_code"
        );
        $stmtFirst->execute([$user['id']]);
        $firstVisits = $stmtFirst->fetchAll();

        // Total visited count per country
        $stmtTotal = $db->prepare(
            'SELECT country_code, COUNT(*) AS visited
             FROM visits
             WHERE user_id = ?
             GROUP BY country_code'
        );
        $stmtTotal->execute([$user['id']]);
        $totalByCountry = $stmtTotal->fetchAll();

        Response::ok([
            'username'         => $username,
            'timeline'         => $timeline,
            'first_visits'     => $firstVisits,
            'total_by_country' => $totalByCountry,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/leaderboard?country=DE
     *
     * Returns a ranking of all active users by number of visited regions.
     * Optionally filtered by country code.
     */
    public function leaderboard(Request $request): void
    {
        $country = $request->query('country');

        $db = Database::get();

        if ($country) {
            $country = strtoupper($country);
            $stmt = $db->prepare(
                'SELECT u.username, COUNT(v.id) AS visited
                 FROM users u
                 LEFT JOIN visits v ON v.user_id = u.id AND v.country_code = ?
                 WHERE u.is_active = 1
                 GROUP BY u.id, u.username
                 ORDER BY visited DESC, u.username ASC
                 LIMIT 50'
            );
            $stmt->execute([$country]);
        } else {
            $stmt = $db->prepare(
                'SELECT u.username, COUNT(v.id) AS visited
                 FROM users u
                 LEFT JOIN visits v ON v.user_id = u.id
                 WHERE u.is_active = 1
                 GROUP BY u.id, u.username
                 ORDER BY visited DESC, u.username ASC
                 LIMIT 50'
            );
            $stmt->execute([]);
        }

        $rows = $stmt->fetchAll();

        $rankings = [];
        $rank     = 0;
        $prev     = -1;
        $i        = 0;
        foreach ($rows as $row) {
            $i++;
            $visited = (int) $row['visited'];
            if ($visited !== $prev) {
                $rank = $i;
                $prev = $visited;
            }
            $rankings[] = [
                'rank'     => $rank,
                'username' => $row['username'],
                'visited'  => $visited,
            ];
        }

        Response::ok([
            'country'  => $country ?: null,
            'rankings' => $rankings,
        ]);
    }
}
