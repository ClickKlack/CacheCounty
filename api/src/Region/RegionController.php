<?php
declare(strict_types=1);

namespace CacheCounty\Region;

use CacheCounty\Shared\Database;
use CacheCounty\Shared\Guard;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

class RegionController
{
    // Upper bounds for free-text input (visits.region_name is VARCHAR(255))
    private const MAX_NOTES_LENGTH       = 2000;
    private const MAX_REGION_NAME_LENGTH = 255;
    // Fallback when a country defines no region_code_pattern (visits.region_code is VARCHAR(20))
    private const MAX_REGION_CODE_LENGTH = 20;

    private static ?array $countryConfig = null;

    /**
     * GET /api/countries
     *
     * Returns the list of configured countries from countries.json.
     */
    public function countries(Request $request): void
    {
        // region_code_pattern and pinned are deliberately not exposed – only the server needs them
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
        ], self::sortCountries(array_values($this->loadCountries())));

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

        $regionName = $this->optionalText($request, 'region_name', self::MAX_REGION_NAME_LENGTH);
        $visitedAt  = $request->input('visited_at');
        $notes      = $this->optionalText($request, 'notes', self::MAX_NOTES_LENGTH);

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
        $notes     = $this->optionalText($request, 'notes', self::MAX_NOTES_LENGTH);

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
     * Country configuration from config/countries.json, keyed by country code.
     * Exits with 500 if the file is missing or invalid.
     */
    private function loadCountries(): array
    {
        if (self::$countryConfig === null) {
            $file = BASE_PATH . '/../config/countries.json';

            if (!file_exists($file)) {
                Response::error('Country configuration not found.', 500);
            }

            $countries = json_decode(file_get_contents($file), true);

            if (!is_array($countries)) {
                Response::error('Invalid country configuration.', 500);
            }

            self::$countryConfig = array_column($countries, null, 'code');
        }

        return self::$countryConfig;
    }

    /**
     * Display order of the countries: entries with "pinned": true first (in config
     * order), then all others alphabetically by German label (Ö sorts like O).
     * The order of /api/countries drives the country select, the country comparison
     * and the leaderboard tabs – the first entry is the default country.
     */
    public static function sortCountries(array $countries, bool $useIntl = true): array
    {
        $collator = ($useIntl && class_exists(\Collator::class)) ? new \Collator('de_DE') : null;

        usort($countries, function (array $a, array $b) use ($collator): int {
            $pinnedA = !empty($a['pinned']);
            $pinnedB = !empty($b['pinned']);
            if ($pinnedA !== $pinnedB) {
                return $pinnedA ? -1 : 1;
            }
            if ($pinnedA) {
                return 0; // usort is stable: pinned countries keep their config order
            }
            return $collator
                ? $collator->compare($a['label'], $b['label'])
                : strcasecmp(self::foldUmlauts($a['label']), self::foldUmlauts($b['label']));
        });

        return $countries;
    }

    /** Fallback without the intl extension: treat umlauts like their base letters */
    private static function foldUmlauts(string $s): string
    {
        return strtr($s, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    }

    /**
     * Splits "DE-09162" into ["DE", "09162"] and validates both parts:
     * the country must be configured, the region code must match the country's
     * region_code_pattern (if set). Exits with 400 otherwise.
     *
     * This keeps made-up visits out of the leaderboard – the actual list of
     * regions only exists in the GeoJSON, which is too large to parse per request.
     */
    private function parseCode(string $code): array
    {
        $parts = explode('-', $code, 2);

        if (count($parts) !== 2 || strlen($parts[0]) !== 2) {
            Response::error('Invalid region code format. Expected <COUNTRY>-<REGION>, e.g. DE-09162.');
        }

        [$countryCode, $regionCode] = [strtoupper($parts[0]), $parts[1]];
        $country = $this->loadCountries()[$countryCode] ?? null;

        if ($country === null) {
            Response::error('Unknown country code.');
        }

        if ($regionCode === '' || strlen($regionCode) > self::MAX_REGION_CODE_LENGTH) {
            Response::error('Invalid region code.');
        }

        $pattern = $country['region_code_pattern'] ?? null;
        if ($pattern !== null) {
            $match = preg_match('#' . str_replace('#', '\\#', $pattern) . '#', $regionCode);
            if ($match === false) {
                throw new \RuntimeException("Invalid region_code_pattern for $countryCode in countries.json");
            }
            if ($match === 0) {
                Response::error('Invalid region code for this country.');
            }
        }

        return [$countryCode, $regionCode];
    }

    /**
     * Optional text field from the JSON body: null/empty → null, otherwise a string
     * of at most $maxLength characters. Exits with 400 on wrong type or length.
     */
    private function optionalText(Request $request, string $field, int $maxLength): ?string
    {
        $value = $request->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            Response::error("Field '$field' must be a string.");
        }

        if (mb_strlen($value) > $maxLength) {
            Response::error("Field '$field' must not exceed $maxLength characters.");
        }

        return $value;
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
