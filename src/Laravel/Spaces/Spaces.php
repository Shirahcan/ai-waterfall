<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;
use Shirahcan\AiClient\AiClient;

/**
 * The signed-in person's space for this product's assistant, resolved once per request (1.57).
 *
 * The assistant is the product's (`ai-client.assistant.key`, set by `AI_ASSISTANT` since 1.56); the
 * owner is whatever the product's `SpaceOwner` says. ai-service makes the space the first time it is
 * asked for, so a product never creates one explicitly.
 */
final class Spaces
{
    /** Where the resolved space is kept for the rest of the request. */
    public const ATTRIBUTE = 'ai-client.space';

    public function __construct(private readonly AiClient $ai, private readonly SpaceOwner $owner) {}

    /**
     * The space id for this request's person, or null when the product says there is no assistant for
     * them. Throws `AiUnavailableException` when ai-service cannot be reached, so a route can say so.
     */
    public function current(Request $request): ?string
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $key = $this->owner->for($request);
        $space = null;
        if ($key !== null) {
            $space = (string) ($this->ai->spaces()->resolve($this->assistant(), $key->kind, $key->ref, $key->audience, $key->memoryRef)['id'] ?? '');
            $space = $space === '' ? null : $space;
        }

        $request->attributes->set(self::ATTRIBUTE, $space);

        return $space;
    }

    /**
     * ⚠ AN UNSET ASSISTANT IS A CONFIGURATION ERROR, NOT "NO ASSISTANT". A product that mounted the
     * space routes without naming its assistant would otherwise answer every person with a 404 and
     * nobody would know why.
     */
    public function assistant(): string
    {
        $key = trim((string) config('ai-client.assistant.key'));
        if ($key === '') {
            throw new \RuntimeException('Assistant spaces need the product\'s assistant: set AI_ASSISTANT (config ai-client.assistant.key).');
        }

        return $key;
    }
}
