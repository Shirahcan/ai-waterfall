<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Http\Request;

/**
 * Who may listen to a conversation's late answers ({@see ChatEventsController}): the only
 * product code behind the push.
 */
interface ChatChannelGuard
{
    /** The product's gate for the push. OFF answers 404, which the shared panel reads as "stop". */
    public function enabled(): bool;

    /**
     * The channel to stream for this request's thread, or null when the caller may not
     * (not theirs, not found). Usually the product's own conversation id.
     */
    public function channelFor(Request $request, string $conversationId): ?string;
}
