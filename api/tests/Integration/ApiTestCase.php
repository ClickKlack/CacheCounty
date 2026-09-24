<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Basis für Integrationstests gegen die echte API.
 *
 * Startet einmal pro PHPUnit-Lauf einen PHP-Dev-Server (scripts/dev-router.php) auf einem
 * freien Port, baut das Schema aus database.sql in einer Testdatenbank auf und legt vor
 * jedem Test dieselben Fixtures an.
 *
 * Aktiv nur, wenn CACHECOUNTY_TEST_DB_NAME gesetzt ist – sonst werden alle Tests
 * übersprungen. Der Name muss auf "_test" enden, damit nie versehentlich eine
 * Entwicklungs- oder Produktionsdatenbank geleert wird.
 */
abstract class ApiTestCase extends TestCase
{
    // Fixture-IDs: TRUNCATE setzt AUTO_INCREMENT zurück, die Reihenfolge in seed() ist fest
    protected const ADMIN_ID    = 1;
    protected const USER_A_ID   = 2;
    protected const USER_B_ID   = 3;
    protected const INACTIVE_ID = 4;

    // Roh-Tokens der Fixture-Sessions (je Rolle eine Session)
    protected const TOKEN_ADMIN    = 'a000000000000000000000000000000000000000000000000000000000000001';
    protected const TOKEN_A        = 'a000000000000000000000000000000000000000000000000000000000000002';
    protected const TOKEN_B        = 'a000000000000000000000000000000000000000000000000000000000000003';
    protected const TOKEN_INACTIVE = 'a000000000000000000000000000000000000000000000000000000000000004';

    // Besuch von User A, den andere Nutzer nicht verändern dürfen
    protected const VISIT_A_CODE = 'DE-09162';

    private const TABLES = ['sessions', 'magic_links', 'visits', 'users'];

    private static ?PDO $pdo = null;
    private static ?string $baseUrl = null;
    /** @var resource|null */
    private static $server = null;

    // ── Setup ────────────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        if (!getenv('CACHECOUNTY_TEST_DB_NAME')) {
            return; // setUp() überspringt dann jeden Test
        }

        self::connect();
        self::startServer();
    }

    protected function setUp(): void
    {
        if (!getenv('CACHECOUNTY_TEST_DB_NAME')) {
            $this->markTestSkipped('Integrationstests inaktiv: CACHECOUNTY_TEST_DB_NAME ist nicht gesetzt.');
        }

        $this->resetData();
        $this->seed();
    }

    private static function connect(): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $config = require __DIR__ . '/config/database.php';

        if (!str_ends_with($config['name'], '_test')) {
            throw new RuntimeException(
                "Testdatenbank \"{$config['name']}\" muss auf \"_test\" enden – Abbruch zum Schutz echter Daten."
            );
        }

        self::$pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['name']),
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        self::$pdo->exec("SET time_zone = '+00:00'");

        self::createSchema();
    }

    /**
     * Baut das Schema aus database.sql neu auf – so testen wir immer gegen das
     * versionierte Schema und nicht gegen einen lokal abweichenden Stand.
     */
    private static function createSchema(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $sql = file_get_contents(dirname(__DIR__, 3) . '/database.sql');
        // Kommentarzeilen entfernen (auch die auskommentierten Migrationen am Dateiende)
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        foreach (preg_split('/;\s*$/m', $sql) as $statement) {
            if (trim($statement) !== '') {
                self::$pdo->exec($statement);
            }
        }
    }

    private static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }

        // Freien Port vom Betriebssystem holen
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$baseUrl = "http://127.0.0.1:$port";
        $root = dirname(__DIR__, 3);

        $env = array_merge(getenv(), [
            'CACHECOUNTY_DB_CONFIG'     => __DIR__ . '/config/database.php',
            'CACHECOUNTY_APP_CONFIG'    => __DIR__ . '/config/app.php',
            'CACHECOUNTY_TEST_BASE_URL' => self::$baseUrl,
        ]);

        $logFile = sys_get_temp_dir() . '/cachecounty-integration-server.log';
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', 'public', 'scripts/dev-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $root,
            $env
        );

        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port);
            if ($conn) {
                fclose($conn);
                register_shutdown_function(static function (): void {
                    if (self::$server !== null) {
                        proc_terminate(self::$server);
                    }
                });
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException("Test-Server startet nicht – Log: $logFile");
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function resetData(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            self::$pdo->exec("TRUNCATE TABLE `$table`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seed(): void
    {
        $users = [
            // id => [username, email, is_admin, is_active]
            self::ADMIN_ID    => ['admin',    'admin@example.com',    1, 1],
            self::USER_A_ID   => ['userA',    'a@example.com',        0, 1],
            self::USER_B_ID   => ['userB',    'b@example.com',        0, 1],
            self::INACTIVE_ID => ['inactive', 'inactive@example.com', 1, 0],
        ];
        $stmt = self::$pdo->prepare(
            'INSERT INTO users (id, username, email, is_admin, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($users as $id => $u) {
            $stmt->execute([$id, ...$u]);
        }

        $this->createSession(self::ADMIN_ID, self::TOKEN_ADMIN);
        $this->createSession(self::USER_A_ID, self::TOKEN_A);
        $this->createSession(self::USER_B_ID, self::TOKEN_B);
        $this->createSession(self::INACTIVE_ID, self::TOKEN_INACTIVE);

        [$cc, $rc] = explode('-', self::VISIT_A_CODE, 2);
        self::$pdo->prepare(
            'INSERT INTO visits (user_id, country_code, region_code, region_name, visited_at, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([self::USER_A_ID, $cc, $rc, 'München', '2024-06-01', 'alt']);
    }

    /**
     * Legt eine Session an und gibt den Roh-Token zurück, wie ihn das Cookie trägt.
     */
    protected function createSession(int $userId, string $rawToken, string $expires = '+1 day'): string
    {
        self::$pdo->prepare(
            'INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)'
        )->execute([self::sessionId($rawToken), $userId, gmdate('Y-m-d H:i:s', strtotime($expires))]);

        return $rawToken;
    }

    /**
     * Legt einen Magic Link an und gibt den Roh-Token zurück, wie ihn die Mail enthält.
     */
    protected function createMagicLink(int $userId, string $rawToken, string $expires = '+15 minutes'): string
    {
        self::$pdo->prepare(
            'INSERT INTO magic_links (user_id, token, expires_at) VALUES (?, ?, ?)'
        )->execute([$userId, hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', strtotime($expires))]);

        return $rawToken;
    }

    /**
     * Session-Token für eine Rolle der Autorisierungs-Matrix.
     */
    protected static function tokenFor(string $role): ?string
    {
        return match ($role) {
            'anon'     => null,
            'user'     => self::TOKEN_A,
            'inactive' => self::TOKEN_INACTIVE,
            'admin'    => self::TOKEN_ADMIN,
        };
    }

    protected function db(): PDO
    {
        return self::$pdo;
    }

    // Öffentliche Zugriffe für RouteMatrix (dort werden die konkreten Pfade gebaut)

    public static function visitCode(): string
    {
        return self::VISIT_A_CODE;
    }

    public static function userBId(): int
    {
        return self::USER_B_ID;
    }

    /**
     * Kennung einer Session, wie sie in sessions.id steht und die Admin-Sessionliste
     * liefert: der SHA-256-Hash des Roh-Tokens.
     */
    public static function sessionId(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function userBSessionId(): string
    {
        return self::sessionId(self::TOKEN_B);
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Sendet einen Request an den Test-Server.
     *
     * @return array{status: int, body: ?array, headers: string}
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $token = null,
        array $extraHeaders = []
    ): array {
        $headers = ['Accept: application/json', 'Origin: ' . self::$baseUrl, ...$extraHeaders];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== null) {
            $headers[] = 'Cookie: cc_session=' . $token;
        }

        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response   = curl_exec($ch);
        $status     = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [
            'status'  => $status,
            'body'    => json_decode(substr($response, $headerSize), true),
            'headers' => substr($response, 0, $headerSize),
        ];
    }
}
