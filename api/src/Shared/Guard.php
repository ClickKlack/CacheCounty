<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Guard
{
    /**
     * Validates the session token from the request.
     * Returns the authenticated user row on success.
     * Calls Response::unauthorized() (exits) on failure.
     *
     * @return array{user_id: int, username: string, is_admin: int}
     */
    public static function requireAuth(Request $request): array
    {
        $token = $request->sessionToken();
        if (!$token) {
            Response::unauthorized();
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT s.user_id, u.username, u.is_admin
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = ?
                AND s.expires_at > NOW()
                AND u.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::unauthorized();
        }

        // Update last_seen_at at most once every 5 minutes to limit write load
        $db->prepare(
            'UPDATE sessions SET last_seen_at = NOW()
              WHERE id = ?
                AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 5 MINUTE)'
        )->execute([$token]);

        return $user;
    }

    /**
     * Like requireAuth(), but additionally enforces admin role.
     *
     * @return array{user_id: int, username: string, is_admin: int}
     */
    public static function requireAdmin(Request $request): array
    {
        $user = self::requireAuth($request);
        if (!$user['is_admin']) {
            Response::forbidden();
        }
        return $user;
    }
}
