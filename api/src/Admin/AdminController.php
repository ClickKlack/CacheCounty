<?php
declare(strict_types=1);

namespace CacheCounty\Admin;

use CacheCounty\Shared\Database;
use CacheCounty\Shared\Guard;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

class AdminController
{
    /**
     * GET /api/admin/users
     *
     * Returns a list of all users (admin only).
     */
    public function listUsers(Request $request): void
    {
        Guard::requireAdmin($request);

        $db   = Database::get();
        $stmt = $db->query(
            'SELECT id, username, email, is_admin, is_active, created_at
               FROM users
              ORDER BY created_at DESC'
        );

        Response::ok($stmt->fetchAll());
    }

    // -------------------------------------------------------------------------

    /**
     * POST /api/admin/users
     * Body: { "username": "MaxMustermann", "email": "max@example.com", "is_admin": false }
     *
     * Creates a new user (admin only).
     */
    public function createUser(Request $request): void
    {
        Guard::requireAdmin($request);

        $username = trim((string) $request->input('username', ''));
        $email    = trim((string) $request->input('email', ''));
        $isAdmin  = (bool) $request->input('is_admin', false);

        // Validation
        if (!preg_match('/^[a-zA-Z0-9_\-]{2,60}$/', $username)) {
            Response::error('Username must be 2–60 characters and may only contain letters, numbers, _ and -.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid e-mail address.');
        }

        $db = Database::get();

        // Uniqueness checks
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            Response::error('Username is already taken.', 409);
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::error('E-mail address is already registered.', 409);
        }

        $db->prepare(
            'INSERT INTO users (username, email, is_admin, is_active)
             VALUES (?, ?, ?, 1)'
        )->execute([$username, $email, (int) $isAdmin]);

        $newId = (int) $db->lastInsertId();

        Response::json([
            'success' => true,
            'data'    => ['id' => $newId, 'username' => $username, 'email' => $email],
        ], 201);
    }

    // -------------------------------------------------------------------------

    /**
     * PATCH /api/admin/users/{id}
     * Body: { "is_active": true }  or  { "is_admin": false }
     *
     * Activates/deactivates a user or toggles the admin flag.
     */
    public function updateUser(Request $request): void
    {
        $currentAdmin = Guard::requireAdmin($request);

        $id   = (int) $request->param('id');
        $body = $request->body();

        if ($id <= 0) {
            Response::error('Invalid user ID.');
        }

        // Prevent admin from accidentally deactivating themselves
        if ($id === (int) $currentAdmin['user_id']) {
            Response::error('You cannot modify your own account.', 403);
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('User not found.');
        }

        $allowed = ['is_active', 'is_admin'];
        $sets    = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]   = "$field = ?";
                $params[] = (int) (bool) $body[$field];
            }
        }

        if (empty($sets)) {
            Response::error('No valid fields to update.');
        }

        $params[] = $id;
        $db->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?'
        )->execute($params);

        Response::ok(['message' => 'User updated.']);
    }

    // -------------------------------------------------------------------------

    /**
     * DELETE /api/admin/users/{id}
     *
     * Deletes a user and all related data via CASCADE.
     */
    public function deleteUser(Request $request): void
    {
        $currentAdmin = Guard::requireAdmin($request);

        $id = (int) $request->param('id');

        if ($id <= 0) {
            Response::error('Invalid user ID.');
        }

        if ($id === (int) $currentAdmin['user_id']) {
            Response::error('You cannot delete your own account.', 403);
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('User not found.');
        }

        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        Response::ok(['message' => 'User deleted.']);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /api/admin/sessions
     *
     * Returns all active (non-expired) sessions, joined with the owning user.
     * Marks the requesting admin's own session with is_current = true.
     */
    public function listSessions(Request $request): void
    {
        Guard::requireAdmin($request);

        $currentToken = $request->sessionToken();

        $db   = Database::get();
        $stmt = $db->query(
            'SELECT s.id, u.username, s.ip_address, s.user_agent,
                    s.created_at, s.last_seen_at, s.expires_at
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.expires_at > NOW()
              ORDER BY COALESCE(s.last_seen_at, s.created_at) DESC'
        );

        $sessions = array_map(function (array $row) use ($currentToken): array {
            return [
                'id'           => $row['id'],
                'username'     => $row['username'],
                'ip_address'   => $row['ip_address'],
                'user_agent'   => $row['user_agent'],
                'created_at'   => $row['created_at'],
                'last_seen_at' => $row['last_seen_at'],
                'expires_at'   => $row['expires_at'],
                'is_current'   => $row['id'] === $currentToken,
            ];
        }, $stmt->fetchAll());

        Response::ok($sessions);
    }

    // -------------------------------------------------------------------------

    /**
     * DELETE /api/admin/sessions/{token}
     *
     * Deletes a specific session by its token.
     * Refuses to delete the requesting admin's own session.
     */
    public function deleteSession(Request $request): void
    {
        Guard::requireAdmin($request);

        $currentToken = $request->sessionToken();
        $token        = (string) $request->param('token');

        if (strlen($token) !== 64) {
            Response::error('Invalid session token.');
        }

        if ($token === $currentToken) {
            Response::error('You cannot delete your own session.', 403);
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$token]);
        if (!$stmt->fetch()) {
            Response::notFound('Session not found.');
        }

        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);

        Response::ok(['message' => 'Session deleted.']);
    }
}
