<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;

/**
 * The ONE thing a product tells the kit about the person in front of its panel (1.57): who they are to
 * the assistant. Null means "no assistant for this request" and every space route answers 404.
 *
 * ⚠ THIS IS THE WHOLE OF PERSON-LEVEL ISOLATION. ai-service trusts the product's key for which person
 * a space belongs to; so an implementation reads the SESSION (the authenticated user, a guest token
 * the product issued) and never a reference or id the browser sent.
 */
interface SpaceOwner
{
    public function for(Request $request): ?SpaceKey;
}
