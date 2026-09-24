<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

/**
 * Access level of a route. Every route in routes.php must declare one –
 * the Router enforces it before the controller runs.
 */
enum Access
{
    /** No login required */
    case Public;
    /** Valid session of an active user */
    case User;
    /** Valid session of an active admin */
    case Admin;
}
