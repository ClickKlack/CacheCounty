<?php
declare(strict_types=1);

namespace CacheCounty\Region;

use CacheCounty\Shared\Database;
use CacheCounty\Shared\Guard;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

class RegionController
{
    /**
     * GET /api/countries
     *
     * Returns the list of configured countries from countries.json.
     */
    public function countries(Request $request): void
    {
        $file = BASE_PATH . '/../config/countries.json';

        if (!file_exists($file)) {
            Response::error('Country configuration not found.', 500);
        }

        $countries = json_decode(file_get_contents($file), true);

        if (!is_array($countries)) {
            Response::error('Invalid country configuration.', 500);
        }

        $result = array_map(fn($c) => [
            'code'                 => $c['code'],
            'label'                => $c['label'],
            'state_label'          => $c['state_label']          ?? null,
            'state_label_plural'   => $c['state_label_plural']   ?? null,
            'geojson'              => $c['geojson']              ?? null,
            'region_name_property' => $c['region_name_property'] ?? null,
            'region_code_property' => $c['region_code_property'] ?? null,
            'state_name_property'  => $c['state_name_property']  ?? null,
            'state_code_property'  => $c['state_code_property']  ?? null,
        ], $countries);

        Response::ok($result);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/map/{username}
     *
     * Returns all visits for a given user (public, no auth required).
     * Optionally filtered by ?country=DE
     */
    public function mapByUser(Request $request): void
    {
        $username = $request->param('username');
        $country  = $request->query('country');

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id FROM users WHERE username = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User not found.');
        }

        $sql    = 'SELECT country_code, region_code, region_name, visited_at, notes,
                          DATE(created_at) AS created_date
                     FROM visits
                    WHERE user_id = ?';
        $params = [$user['id']];

        if ($country) {
            $sql     .= ' AND country_code = ?';
            $params[] = strtoupper($country);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $visits = $stmt->fetchAll();

        // Also return total count per country for the statistics bar
        $stmtStats = $db->prepare(
            'SELECT country_code, COUNT(*) AS visited_count
               FROM visits
              WHERE user_id = ?
              GROUP BY country_code'
        );
        $stmtStats->execute([$user['id']]);
        $stats = $stmtStats->fetchAll();

        Response::ok([
            'username' => $username,
            'visits'   => $visits,
            'stats'    => $stats,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * POST /api/regions/{code}/visit
     * Body: { "region_name": "München", "visited_at": "2024-06-01", "notes": "..." }
     *
     * {code} format: <COUNTRY_CODE>-<REGION_CODE>  e.g. DE-09162
     */
    public function addVisit(Request $request): void
    {
        $user                              = Guard::requireAuth($request);
        [$countryCode, $regionCode]        = $this->parseCode($request->param('code'));

        $regionName = (string) $request->input('region_name', '');
        $visitedAt  = $request->input('visited_at');
        $notes      = $request->input('notes');

        $db = Database::get();

        // Check for duplicate (unique constraint would also catch this, but gives a nicer error)
        $stmt = $db->prepare(
            'SELECT id FROM visits WHERE user_id = ? AND country_code = ? AND region_code = ? LIMIT 1'
        );
        $stmt->execute([$user['user_id'], $countryCode, $regionCode]);

        if ($stmt->fetch()) {
            Response::error('Region already marked as visited.', 409);
        }

        $db->prepare(
            'INSERT INTO visits (user_id, country_code, region_code, region_name, visited_at, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['user_id'],
            $countryCode,
            $regionCode,
            $regionName ?: null,
            $this->validateDate($visitedAt),
            $notes ?: null,
        ]);

        Response::ok(['message' => 'Visit added.']);
    }

    // -------------------------------------------------------------------------

    /**
     * PUT /api/regions/{code}/visit
     * Body: { "visited_at": "2024-06-01", "notes": "..." }
     */
    public function updateVisit(Request $request): void
    {
        $user                       = Guard::requireAuth($request);
        [$countryCode, $regionCode] = $this->parseCode($request->param('code'));

        $visitedAt = $request->input('visited_at');
        $notes     = $request->input('notes');

        $db   = Database::get();
        $stmt = $db->prepare(
            'UPDATE visits
                SET visited_at = ?, notes = ?, updated_at = NOW()
              WHERE user_id = ? AND country_code = ? AND region_code = ?'
        );
        $stmt->execute([
            $this->validateDate($visitedAt),
            $notes ?: null,
            $user['user_id'],
            $countryCode,
            $regionCode,
        ]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Visit not found.');
        }

        Response::ok(['message' => 'Visit updated.']);
    }

    // -------------------------------------------------------------------------

    /**
     * DELETE /api/regions/{code}/visit
     */
    public function removeVisit(Request $request): void
    {
        $user                       = Guard::requireAuth($request);
        [$countryCode, $regionCode] = $this->parseCode($request->param('code'));

        $db   = Database::get();
        $stmt = $db->prepare(
            'DELETE FROM visits WHERE user_id = ? AND country_code = ? AND region_code = ?'
        );
        $stmt->execute([$user['user_id'], $countryCode, $regionCode]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('Visit not found.');
        }

        Response::ok(['message' => 'Visit removed.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Splits "DE-09162" into ["DE", "09162"].
     * Exits with 400 if the format is invalid.
     */
    private function parseCode(string $code): array
    {
        $parts = explode('-', $code, 2);

        if (count($parts) !== 2 || strlen($parts[0]) !== 2) {
            Response::error('Invalid region code format. Expected <COUNTRY>-<REGION>, e.g. DE-09162.');
        }

        return [strtoupper($parts[0]), $parts[1]];
    }

    /**
     * Returns a valid Y-m-d date string or null.
     */
    private function validateDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        $d = \DateTime::createFromFormat('Y-m-d', (string) $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
