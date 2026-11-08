<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;

/**
 * The conversation this person last had BEFORE their panel moved onto spaces (1.57.2, found in the browser
 * 2026-11-08): the first open of the shared panel started fresh instead of reopening it. Asked only when
 * the space has no conversation to reopen yet; the answer (an ai-service thread id) is then kept in the
 * space, so this is asked at most once per person. A product with no earlier pointer binds nothing.
 */
interface PreviousConversation
{
    /** The ai-service thread id, or null. The kit checks it is in the person's space before using it. */
    public function for(Request $request): ?string;
}
