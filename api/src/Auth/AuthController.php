<?php
declare(strict_types=1);

namespace CacheCounty\Auth;

use CacheCounty\Shared\Database;
use CacheCounty\Shared\Guard;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class AuthController
{
    // Session lifetime: 30 days
    private const SESSION_TTL_DAYS   = 365;
    // Magic link lifetime: 15 minutes
    private const MAGIC_LINK_TTL_MIN = 15;
    // Probability (1–100) of running garbage collection on each magic-link request
    private const GC_PROBABILITY     = 2;

    // -------------------------------------------------------------------------

    /**
     * POST /api/auth/magic-link
     * Body: { "email": "user@example.com" }
     *
     * Generates a magic link token and sends it via e-mail.
     * Always returns a generic success message to prevent e-mail enumeration.
     */
    public function requestMagicLink(Request $request): void
    {
        $email = trim((string) $request->input('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid e-mail address.');
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Silently succeed even when the e-mail is unknown (no enumeration)
        if ($user) {
            $token     = bin2hex(random_bytes(32)); // 64 hex chars
            $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . self::MAGIC_LINK_TTL_MIN . ' minutes'));

            $db->prepare(
                'INSERT INTO magic_links (user_id, token, expires_at, ip_address)
                 VALUES (?, ?, ?, ?)'
            )->execute([$user['id'], $token, $expiresAt, $request->ip()]);

            $this->sendMagicLinkEmail($email, $token);
        }

        // Probabilistic garbage collection (no SQL events on shared hosting)
        $this->maybeRunGc();

        Response::ok(['message' => 'If this e-mail is registered, a login link has been sent.']);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/auth/verify?token=<hex>
     *
     * Validates the magic link token, creates a session and sets a cookie.
     */
    public function verifyToken(Request $request): void
    {
        $token = trim((string) $request->query('token'));

        if (strlen($token) !== 64) {
            Response::error('Invalid token.');
        }

        $db = Database::get();

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
        $stmt->execute([$token]);

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
        $stmt->execute([$token]);
        $link = $stmt->fetch();

        // Create session
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . self::SESSION_TTL_DAYS . ' days'));

        $db->prepare(
            'INSERT INTO sessions (id, user_id, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $sessionId,
            $link['user_id'],
            $expiresAt,
            $request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        // Set HttpOnly session cookie
        setcookie('cc_session', $sessionId, [
            'expires'  => strtotime('+' . self::SESSION_TTL_DAYS . ' days'),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']),
        ]);

        Response::ok([
            'username' => $link['username'],
            'is_admin' => (bool) $link['is_admin'],
            'token'    => $sessionId, // also returned for API clients that can't use cookies
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/auth/me
     *
     * Returns the currently authenticated user based on session cookie or bearer token.
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
                ->execute([$token]);
        }

        // Clear cookie
        setcookie('cc_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']),
        ]);

        Response::ok(['message' => 'Logged out.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Sends the magic link e-mail via PHPMailer (SMTP).
     */
    private function sendMagicLinkEmail(string $to, string $token): void
    {
        $configFile = file_exists(BASE_PATH . '/config/app.local.php')
            ? BASE_PATH . '/config/app.local.php'
            : BASE_PATH . '/config/app.php';
        $config  = require $configFile;
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
    }
}
