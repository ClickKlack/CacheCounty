<?php
declare(strict_types=1);

namespace CacheCounty\Auth;

use CacheCounty\Shared\Config;
use CacheCounty\Shared\Database;
use CacheCounty\Shared\Guard;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;
use CacheCounty\Shared\Token;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class AuthController
{
    // Session lifetime: 365 days
    private const SESSION_TTL_DAYS   = 365;
    // Magic link lifetime: 15 minutes
    private const MAGIC_LINK_TTL_MIN = 15;
    // Probability (1–100) of running garbage collection on each magic-link request
    private const GC_PROBABILITY     = 2;

    // Rate limits for POST /api/auth/magic-link (overridable via app config for tests)
    private const IP_LIMIT_PER_HOUR  = 20;   // requests per IP and hour → 429 beyond
    private const USER_LIMIT_15_MIN  = 3;    // links per account in 15 min → silently skipped
    // Minimum response time, so known and unknown addresses cannot be told apart
    // by timing. Residual risk: if SMTP takes longer than this, the difference shows.
    private const MIN_RESPONSE_MS    = 1500;

    // -------------------------------------------------------------------------

    /**
     * POST /api/auth/magic-link
     * Body: { "email": "user@example.com" }
     *
     * Generates a magic link token and sends it via e-mail.
     * Always returns the same generic success message – same status, same body,
     * same minimum duration – whether the address is known or not (no enumeration).
     *
     * Rate limits: per IP (429 beyond the limit) and per account (further links are
     * silently not sent, so the limit does not reveal whether the address exists).
     */
    public function requestMagicLink(Request $request): void
    {
        $startedAt = microtime(true);
        $email     = trim((string) $request->input('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid e-mail address.');
        }

        $db = Database::get();
        $this->enforceIpLimit($db, $request->ip());

        $stmt = $db->prepare(
            'SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Silently succeed even when the e-mail is unknown (no enumeration)
        if ($user && !$this->userLimitReached($db, (int) $user['id'])) {
            $token     = Token::generate();
            $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . self::MAGIC_LINK_TTL_MIN . ' minutes'));

            // Only the hash is stored; the raw token goes out by e-mail only
            $db->prepare(
                'INSERT INTO magic_links (user_id, token, expires_at, ip_address)
                 VALUES (?, ?, ?, ?)'
            )->execute([$user['id'], Token::hash($token), $expiresAt, $request->ip()]);

            // A mail failure must not change the response – otherwise it would only
            // ever show up for registered addresses
            try {
                $this->sendMagicLinkEmail($email, $token);
            } catch (\Throwable $e) {
                error_log('[CacheCounty] Magic link mail failed: ' . $e->getMessage());
            }
        }

        // Probabilistic garbage collection (no SQL events on shared hosting)
        $this->maybeRunGc();

        $this->padResponseTime($startedAt);
        Response::ok(['message' => 'If this e-mail is registered, a login link has been sent.']);
    }

    // -------------------------------------------------------------------------

    /**
     * POST /api/auth/verify
     * Body: { "token": "<hex>" }
     *
     * Validates the magic link token, creates a session and sets the HttpOnly cookie.
     * POST (not GET) so that the request is subject to the same-origin checks for
     * state-changing requests – a foreign page cannot log a visitor into another account.
     */
    public function verifyToken(Request $request): void
    {
        $token = trim((string) $request->input('token', ''));

        if (strlen($token) !== 64) {
            Response::error('Invalid token.');
        }

        $db        = Database::get();
        $tokenHash = Token::hash($token);

        // Atomically mark token as used — only succeeds if valid, unexpired, unused and user active
        $stmt = $db->prepare(
            'UPDATE magic_links ml
               JOIN users u ON u.id = ml.user_id
                SET ml.used_at = NOW()
              WHERE ml.token = ?
                AND ml.expires_at > NOW()
                AND ml.used_at IS NULL
                AND u.is_active = 1'
        );
        $stmt->execute([$tokenHash]);

        if ($stmt->rowCount() !== 1) {
            Response::error('Token is invalid, expired or has already been used.', 401);
        }

        // Fetch user data for session creation
        $stmt = $db->prepare(
            'SELECT ml.user_id, u.username, u.is_admin
               FROM magic_links ml
               JOIN users u ON u.id = ml.user_id
              WHERE ml.token = ?
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $link = $stmt->fetch();

        // Older, still unused links of this user become worthless after a successful login
        $db->prepare('DELETE FROM magic_links WHERE user_id = ? AND used_at IS NULL')
           ->execute([$link['user_id']]);

        // Create session: the cookie carries the raw token, the database only its hash
        $sessionId = Token::generate();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . self::SESSION_TTL_DAYS . ' days'));

        $db->prepare(
            'INSERT INTO sessions (id, user_id, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            Token::hash($sessionId),
            $link['user_id'],
            $expiresAt,
            $request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        // Set HttpOnly session cookie
        setcookie('cc_session', $sessionId, $this->cookieOptions(
            strtotime('+' . self::SESSION_TTL_DAYS . ' days')
        ));

        // The token itself is only in the cookie, never in the response body
        Response::ok([
            'username' => $link['username'],
            'is_admin' => (bool) $link['is_admin'],
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/auth/me
     *
     * Returns the currently authenticated user based on the session cookie.
     */
    public function me(Request $request): void
    {
        $user = Guard::requireAuth($request);

        Response::ok([
            'username' => $user['username'],
            'is_admin' => (bool) $user['is_admin'],
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * POST /api/auth/logout
     *
     * Invalidates the current session.
     */
    public function logout(Request $request): void
    {
        $token = $request->sessionToken();

        if ($token) {
            Database::get()
                ->prepare('DELETE FROM sessions WHERE id = ?')
                ->execute([Token::hash($token)]);
        }

        // Clear cookie
        setcookie('cc_session', '', $this->cookieOptions(time() - 3600));

        Response::ok(['message' => 'Logged out.']);
    }

    // -------------------------------------------------------------------------

    /**
     * POST /api/auth/logout-all
     *
     * Invalidates all sessions of the current user, on every device.
     */
    public function logoutAll(Request $request): void
    {
        $user = Guard::requireAuth($request);

        Database::get()
            ->prepare('DELETE FROM sessions WHERE user_id = ?')
            ->execute([$user['user_id']]);

        setcookie('cc_session', '', $this->cookieOptions(time() - 3600));

        Response::ok(['message' => 'Logged out everywhere.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Options for the session cookie. 'secure' follows base_url instead of
     * $_SERVER['HTTPS'], which is often unset behind a TLS-terminating proxy.
     */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => Config::isHttps(),
        ];
    }

    /**
     * Sends the magic link e-mail via PHPMailer (SMTP).
     */
    private function sendMagicLinkEmail(string $to, string $token): void
    {
        $config  = Config::app();
        $baseUrl = rtrim($config['base_url'], '/');
        $link    = $baseUrl . '/app/?token=' . $token;

        $fromName = $config['mail_from_name'] ?? 'CacheCounty';
        $fromAddr = $config['mail_from']      ?? 'noreply@example.com';
        $ttl      = self::MAGIC_LINK_TTL_MIN;

        $html = $this->buildEmailHtml($link, $ttl);
        $text = "Dein CacheCounty Login-Link\n\n"
              . "Klicke auf den folgenden Link, um dich anzumelden:\n"
              . "$link\n\n"
              . "Der Link ist $ttl Minuten gültig und kann nur einmal verwendet werden.\n\n"
              . "Falls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.";

        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Timeout    = 10;   // default is 300 s – a hanging mail server must not block logins
        $mail->Host       = $config['smtp_host']   ?? '';
        $mail->Port       = (int) ($config['smtp_port']   ?? 587);
        $mail->Username   = $config['smtp_user'] ?? '';
        $mail->Password   = $config['smtp_pass'] ?? '';
        $mail->SMTPAuth   = $mail->Username !== '';
        $mail->SMTPSecure = match($config['smtp_secure'] ?? 'tls') {
            'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
            'tls'  => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };

        $mail->setFrom($fromAddr, $fromName);
        $mail->addAddress($to);
        $mail->Subject  = 'Dein CacheCounty Login-Link';
        $mail->isHTML(true);
        $mail->Body     = $html;
        $mail->AltBody  = $text;

        $mail->send();
    }

    /**
     * Builds the HTML body for the magic link e-mail.
     */
    private function buildEmailHtml(string $link, int $ttl): string
    {
        $escapedLink = htmlspecialchars($link, ENT_QUOTES);
        $escapedTtl  = (string) $ttl;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Dein CacheCounty Login-Link</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f1eb;font-family:'Segoe UI',Arial,sans-serif;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1eb;padding:40px 0;">
            <tr>
              <td align="center">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;">

                  <!-- Header -->
                  <tr>
                    <td align="center" style="padding-bottom:24px;">
                      <span style="font-size:22px;font-weight:700;color:#2e4f28;letter-spacing:0.5px;">Cache<span style="color:#c45c2a;">County</span></span>
                    </td>
                  </tr>

                  <!-- Card -->
                  <tr>
                    <td style="background:#ffffff;border-radius:12px;padding:40px 48px;box-shadow:0 2px 8px rgba(0,0,0,0.07);">

                      <p style="margin:0 0 8px;font-size:22px;font-weight:600;color:#1a1a2e;">Dein Login-Link</p>
                      <p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#555;">
                        Du hast einen Login für CacheCounty angefordert. Klicke auf den Button, um dich anzumelden.
                      </p>

                      <!-- Button -->
                      <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 28px;">
                        <tr>
                          <td style="border-radius:8px;background:#2e4f28;">
                            <a href="{$escapedLink}"
                               style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;letter-spacing:0.3px;">
                              Jetzt anmelden
                            </a>
                          </td>
                        </tr>
                      </table>

                      <p style="margin:0 0 6px;font-size:13px;color:#888;text-align:center;">
                        Oder kopiere diesen Link in deinen Browser:
                      </p>
                      <p style="margin:0 0 28px;font-size:12px;color:#aaa;text-align:center;word-break:break-all;">
                        <a href="{$escapedLink}" style="color:#8a7055;text-decoration:none;">{$escapedLink}</a>
                      </p>

                      <hr style="border:none;border-top:1px solid #eee;margin:0 0 24px;">

                      <p style="margin:0;font-size:13px;line-height:1.6;color:#999;">
                        Der Link ist <strong>{$escapedTtl} Minuten</strong> gültig und kann nur einmal verwendet werden.
                        Falls du diese E-Mail nicht angefordert hast, kannst du sie einfach ignorieren.
                      </p>
                    </td>
                  </tr>

                  <!-- Footer -->
                  <tr>
                    <td align="center" style="padding-top:24px;">
                      <p style="margin:0;font-size:12px;color:#bbb;">CacheCounty &middot; Geocaching-Karte</p>
                    </td>
                  </tr>

                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>
        HTML;
    }

    /**
     * Counts magic-link requests per IP within the last hour and exits with 429
     * beyond the limit. Every request counts, known address or not.
     */
    private function enforceIpLimit(\PDO $db, string $ip): void
    {
        $limit = (int) (Config::app()['magic_link_ip_limit_per_hour'] ?? self::IP_LIMIT_PER_HOUR);

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM auth_attempts
              WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$ip]);

        if ((int) $stmt->fetchColumn() >= $limit) {
            Response::error('Too many requests.', 429);
        }

        $db->prepare('INSERT INTO auth_attempts (ip_address) VALUES (?)')->execute([$ip]);
    }

    /**
     * True if the account already got the maximum number of links in the last 15 minutes.
     */
    private function userLimitReached(\PDO $db, int $userId): bool
    {
        $limit = (int) (Config::app()['magic_link_user_limit_per_15min'] ?? self::USER_LIMIT_15_MIN);

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM magic_links
              WHERE user_id = ? AND created_at > NOW() - INTERVAL 15 MINUTE'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() >= $limit;
    }

    /**
     * Sleeps until at least MIN_RESPONSE_MS have passed since $startedAt.
     */
    private function padResponseTime(float $startedAt): void
    {
        $minMs     = (int) (Config::app()['magic_link_min_response_ms'] ?? self::MIN_RESPONSE_MS);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        if ($elapsedMs < $minMs) {
            usleep((int) (($minMs - $elapsedMs) * 1000));
        }
    }

    /**
     * Probabilistic garbage collection for expired tokens and sessions.
     * Runs with a probability of GC_PROBABILITY percent.
     */
    private function maybeRunGc(): void
    {
        if (random_int(1, 100) > self::GC_PROBABILITY) {
            return;
        }

        $db = Database::get();
        $db->exec('DELETE FROM magic_links WHERE expires_at < NOW()');
        $db->exec('DELETE FROM sessions     WHERE expires_at < NOW()');
        $db->exec('DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    }
}
