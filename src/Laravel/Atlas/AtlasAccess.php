<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The product's answer to "does this person hold this permission?", the only thing the data engine
 * cannot know for itself (plan mploynow-mira-parity P0a).
 *
 * An entity's `permission` is a BROAD-BROWSE gate (may this user see every row of this type?) and a
 * dimension's or metric's `permission` hides one field. A row-scoped audience bypasses the broad gate
 * (its row scope IS its access control) but never a field gate.
 *
 * ⚠ FAIL CLOSED. A permission the product does not recognise must answer false. Portify answers with
 * `User::hasPermission()`; a product with no permission system (MployNow) answers from roles and says
 * so in its own implementation.
 */
interface AtlasAccess
{
    public function mayHold(Authenticatable $user, string $permission): bool;
}
