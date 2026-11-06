<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;

/** A test {@see AtlasAccess}: grants exactly the permissions it was given, to everyone. */
final class FakeAtlasAccess implements AtlasAccess
{
    /** @param  list<string>  $granted */
    public function __construct(private array $granted = []) {}

    public function mayHold(Authenticatable $user, string $permission): bool
    {
        return in_array($permission, $this->granted, true);
    }
}
