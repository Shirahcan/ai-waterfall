<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Turns;

use Illuminate\Http\Request;

/**
 * What ONE chat turn does in a product (1.57, plan S2c, owner decision D2): the kit owns the transport
 * (the stream, its events, Stop, the conversation to reopen); the product binds this to supply the
 * turn itself (Porter's tools, Atlas and cards; Mira's) and its own words.
 *
 * The kit calls, in order: `validate()` before any byte is streamed (so a bad request is a plain 422),
 * then streams `openingStages()` (the last one carries the turn id, so a Stop has something to stop
 * before the work starts), then `run()`. A turn the person stopped returns null and nothing is emitted:
 * an answer they declined must not reappear on reload.
 */
interface TurnRunner
{
    /**
     * The product's own fields (page context, attachments, its message length...). The kit has already
     * checked `conversation_id` is a thread of the person's space.
     *
     * @return array<string, mixed> the validated data handed to `run()`
     */
    public function validate(Request $request): array;

    /** @return non-empty-list<string> e.g. Porter ['Understanding your question', 'Working on it'], Mira ['Thinking it through'] */
    public function openingStages(): array;

    /**
     * Do the turn. `$conversationId` is the space's thread (ai-service id), or null for a new one.
     * Stream progress through `$events`; return the thread the turn landed in and the panel's message.
     * Return null when the turn was stopped. Throw `TurnRefused` for a reason the person should read.
     *
     * @param  array<string, mixed>  $data
     */
    public function run(Request $request, array $data, ?string $conversationId, string $turnId, TurnEvents $events): ?TurnResult;

    /** Stop the provider's spend for this turn, not just the socket. */
    public function cancel(Request $request, string $turnId): bool;

    /** What the person reads when the turn fails for a reason they cannot act on. */
    public function failureMessage(): string;
}
