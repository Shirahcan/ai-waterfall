<?php

namespace Shirahcan\AiClient;

/**
 * One page of a product's turns from ACROSS its threads, newest first.
 *
 * ⚠ THIS IS NOT `ConversationPage`, AND THE DIFFERENCE IS NOT COSMETIC. That one is a thread on
 * a screen: it carries the conversation it belongs to and reads oldest-first within the page,
 * because that is how a transcript is rendered. This one answers a question about the corpus -
 * "what did people ask that we could not answer this week" - so there is no single conversation
 * to carry, and newest-first is what every caller wants.
 *
 * ⚠ PAGE BACKWARDS WITH `nextBefore`, AND DO NOT INVENT A CURSOR. The service resolves it
 * against (created_at, id) because a backfilled transcript can put ten turns in one second, so a
 * cursor built from a timestamp would skip the rest of that second or repeat it for ever while
 * every response still looked valid. An unrecognised cursor is a 422 rather than page one.
 */
final class CorpusPage
{
    /**
     * @param list<ConversationTurn> $turns
     */
    public function __construct(
        public readonly array $turns,
        public readonly bool $hasMore,
        public readonly ?string $nextBefore,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            turns: array_map(
                fn (array $turn) => ConversationTurn::fromArray($turn),
                array_values((array) ($a['turns'] ?? [])),
            ),
            hasMore: (bool) ($a['has_more'] ?? false),
            nextBefore: ($a['next_before'] ?? null) === null ? null : (string) $a['next_before'],
        );
    }

    /**
     * The turns' bodies, blanks dropped.
     *
     * ⚠ A TURN CAN HAVE NO BODY AT ALL - the service accepts prose OR structure - so a caller
     * mapping bodies straight into a list of questions would be building a list with holes in
     * it. The composer's recall ring did exactly this against SQL and filtered afterwards.
     *
     * @return list<string>
     */
    public function bodies(): array
    {
        $out = [];

        foreach ($this->turns as $turn) {
            $body = trim((string) ($turn->body ?? ''));

            if ($body !== '') {
                $out[] = $body;
            }
        }

        return $out;
    }

    /** @return list<ConversationTurn> */
    public function fromThePerson(): array
    {
        return array_values(array_filter($this->turns, fn (ConversationTurn $t) => $t->isFromThePerson()));
    }
}
