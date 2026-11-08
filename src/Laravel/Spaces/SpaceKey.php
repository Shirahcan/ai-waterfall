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
    ) {
        if (trim($ref) === '') {
            throw new \InvalidArgumentException('A space owner needs a reference.');
        }
    }

    /** A signed-in person: the product's reference as its transcripts already use it (Porter: the user id; Mira: `user:<id>`). */
    public static function user(string $ref, ?string $audience): self
    {
        return new self(self::USER, $ref, $audience);
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
