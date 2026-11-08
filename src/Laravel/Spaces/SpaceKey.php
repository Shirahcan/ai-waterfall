<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

/**
 * Who the person in front of the panel is, to the assistant (1.57): a signed-in user or a guest, the
 * product's opaque reference for them, and the audience they are in. The product's `SpaceOwner`
 * builds it from the session; it never comes from the browser.
 */
final class SpaceKey
{
    public const USER = 'user';

    public const GUEST = 'guest';

    private function __construct(
        public readonly string $kind,
        public readonly string $ref,
        public readonly ?string $audience,
        /** Where the person's OWN memory is, when the product files it apart from their threads (1.57.1). */
        public readonly ?string $memoryRef = null,
    ) {
        if (trim($ref) === '') {
            throw new \InvalidArgumentException('A space owner needs a reference.');
        }
    }

    /**
     * A signed-in person: the product's reference as its transcripts already use it (Porter: the user id;
     * Mira: `user:<id>`). `$memoryRef` when its memory is filed under another (Porter: `user:<id>`), so the
     * space mirrors and levels "What {assistant} knows about you" against the right rows.
     */
    public static function user(string $ref, ?string $audience, ?string $memoryRef = null): self
    {
        return new self(self::USER, $ref, $audience, $memoryRef);
    }

    /**
     * A guest, by the token their browser holds. Hashed here, so the token itself never leaves the product
     * (the reference matches Portify's existing guest threads: `guest:` + sha256).
     */
    public static function guest(string $token, ?string $audience = 'guest'): self
    {
        return new self(self::GUEST, 'guest:'.hash('sha256', $token), $audience);
    }
}
