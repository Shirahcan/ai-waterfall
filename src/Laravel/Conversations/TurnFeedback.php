<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Shirahcan\AiClient\AiConversations;

/**
 * The thumbs on an assistant's answer, in one vocabulary for every product.
 *
 * The reasons are the service's (`AiConversations::FEEDBACK_REASONS`, mirrored by the shared
 * rating control); a product must not keep its own list beside them, or the two drift and a
 * reason the control offers is refused on the wire.
 *
 * ⚠ THE VOTER IS A REF, NEVER A RAW ID OR TOKEN. A guest's token is a credential; storing it in
 * clear beside a vote would let anyone reading the feedback page resume that guest's thread.
 */
final class TurnFeedback
{
    public const POSITIVE = 'positive';

    public const NEGATIVE = 'negative';

    /** @return list<string> */
    public static function reasons(): array
    {
        return array_values(AiConversations::FEEDBACK_REASONS);
    }

    public static function userRef(string $userId): string
    {
        return 'user:'.$userId;
    }

    public static function guestRef(string $guestToken): string
    {
        return 'guest:'.hash('sha256', $guestToken);
    }
}
