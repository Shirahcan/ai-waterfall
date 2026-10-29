<?php

namespace Shirahcan\AiClient\Laravel\Tools;

/**
 * Who a chat-tool call is FOR, as the product's per-turn {@see ToolToken} names them.
 *
 * ⚠ THE TOKEN, NOT THE TOOL ARGUMENTS, DECIDES WHO IS ASKING. A model can be talked into
 * putting any user id or audience in an argument; it cannot forge this, because the token is
 * encrypted and authenticated with the product's app key and ai-service never sees inside it.
 *
 * The ids are the PRODUCT's; this package never reads them. `extras` carries anything else one
 * product's tools need for the turn (Portify: the consented IT snapshot key).
 */
final class ToolActor
{
    /** @param  array<string, string|int|bool|null>  $extras */
    public function __construct(
        public readonly ?string $userId,
        public readonly string $audience,
        public readonly string $route = '/',
        public readonly ?string $knowledgeAudience = null,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?string $timezone = null,
        public readonly ?string $conversationId = null,
        /** A guest on a public site: a hash of the visitor's token, never a user id. */
        public readonly ?string $guest = null,
        public readonly array $extras = [],
    ) {}

    /** A visitor with no account: tools that read nothing about anyone. */
    public static function guest(string $guestToken, string $route = '/', ?string $timezone = null): self
    {
        return new self(userId: null, audience: 'public', route: $route, knowledgeAudience: 'public',
            timezone: $timezone, guest: sha1($guestToken));
    }

    public function isGuest(): bool
    {
        return $this->userId === null && $this->guest !== null && $this->audience === 'public';
    }

    public function extra(string $key): mixed
    {
        return $this->extras[$key] ?? null;
    }
}
