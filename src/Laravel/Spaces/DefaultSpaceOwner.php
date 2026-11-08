<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;

/**
 * The fallback `SpaceOwner`: the authenticated user's id, no audience, no guests. A product with
 * audiences or guests (Portify, MployNow) binds its own; this exists so a product with neither needs
 * no code at all.
 */
final class DefaultSpaceOwner implements SpaceOwner
{
    public function for(Request $request): ?SpaceKey
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        return SpaceKey::user((string) $user->getAuthIdentifier(), null);
    }
}
