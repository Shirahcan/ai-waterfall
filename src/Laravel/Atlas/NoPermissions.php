<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The kit's default {@see AtlasAccess}: nobody holds any permission. A product that has not said how
 * its permissions work still serves open entities and row-scoped audiences, and hides the rest.
 */
final class NoPermissions implements AtlasAccess
{
    public function mayHold(Authenticatable $user, string $permission): bool
    {
        return false;
    }
}
