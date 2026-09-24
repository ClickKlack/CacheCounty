<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

/**
 * Session- und Magic-Link-Tokens.
 *
 * The raw token only ever leaves the server once (cookie or e-mail). The database
 * stores its SHA-256 hash, so a leaked dump or the admin session list cannot be
 * used to log in. A plain hash is sufficient: the tokens carry 256 bits of entropy.
 */
class Token
{
    /** 64 hex chars (256 bit) */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** 64 hex chars, as stored in sessions.id and magic_links.token */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
