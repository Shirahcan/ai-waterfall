<?php

namespace Shirahcan\AiClient;

/**
 * One page of a transcript, oldest-first within the page, newest page first.
 *
 * ⚠ PAGE BACKWARDS WITH `nextBefore`, AND DO NOT INVENT A CURSOR. The service's cursor is a
 * turn id resolved against (created_at, id), because `created_at` has no fractional seconds
 * and an exchange writes several turns inside one second - so a cursor built from a timestamp
 * would skip the rest of that second or repeat it for ever while every response still looked
 * valid. A cursor the service does not recognise is a 422, which is deliberate: silently
 * restarting is how a paging loop runs for ever.
 *
 *     $page = $ai->conversations()->read($id);
 *     while ($page->hasMore) {
 *         $page = $ai->conversations()->read($id, before: $page->nextBefore);
 *     }
 */
final class ConversationPage
{
    /**
     * @param list<ConversationTurn> $turns
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $turns,
        public readonly bool $hasMore,
        public readonly ?string $nextBefore,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            conversation: Conversation::fromArray((array) ($a['conversation'] ?? [])),
            turns: array_map(
                fn (array $turn) => ConversationTurn::fromArray($turn),
                array_values((array) ($a['turns'] ?? [])),
            ),
            hasMore: (bool) ($a['has_more'] ?? false),
            nextBefore: ($a['next_before'] ?? null) === null ? null : (string) $a['next_before'],
        );
    }

    /** @return list<ConversationTurn> */
    public function fromTheAssistant(): array
    {
        return array_values(array_filter($this->turns, fn (ConversationTurn $t) => $t->isFromTheAssistant()));
    }

    /** How many of the assistant's turns on this page were refusals. */
    public function refusals(): int
    {
        return count(array_filter($this->turns, fn (ConversationTurn $t) => $t->refused));
    }
}
