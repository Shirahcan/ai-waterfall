<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Turns;

/**
 * Where a turn landed and what the panel shows for it (1.57). `conversationId` is the ai-service thread
 * of the person's space (the id the panel's list uses); `message` is the product's panel message.
 */
final class TurnResult
{
    /** @param array<string, mixed> $message */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $message,
    ) {}
}
