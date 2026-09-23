<?php

namespace Shirahcan\AiWaterfall;

/**
 * One event from a token stream.
 *
 * ⚠ `DISCARD` IS THE ONE EVERY CONSUMER FORGETS, AND IT CORRUPTS THE ANSWER.
 * The waterfall can fail a rung AFTER it has already emitted tokens - the
 * provider dies mid-sentence and the next rung starts the answer over. A
 * consumer that ignores `discard` and keeps appending ends up showing two
 * different answers glued together, each half-finished, with no indication that
 * anything went wrong. That is worse than an error, because it reads as
 * finished prose.
 *
 * So this is surfaced as a first-class event rather than handled quietly inside
 * the client: only the CONSUMER knows what it has already painted on screen,
 * and only the consumer can un-paint it.
 */
final class AiStreamEvent
{
    /** The stream id, emitted first so a cancel is possible from the next tick. */
    public const OPEN = 'open';

    /** A waterfall rung started. `provider` / `model` say which. */
    public const STAGE = 'stage';

    /** Append `text`. */
    public const TOKEN = 'token';

    /** ⚠ Throw away everything shown so far; the next rung restarts the answer. */
    public const DISCARD = 'discard';

    /** Terminal: the answer is complete. */
    public const DONE = 'done';

    /** Terminal: stopped, by this caller or by the service. */
    public const CANCELLED = 'cancelled';

    /** Terminal: every rung refused. */
    public const ERROR = 'error';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    /** The text of a TOKEN event; empty for anything else. */
    public function text(): string
    {
        $text = $this->data['text'] ?? '';

        return is_string($text) ? $text : '';
    }

    public function streamId(): ?string
    {
        $id = $this->data['stream_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Is this the last event of the stream?
     *
     * ⚠ A STREAM THAT ENDS WITHOUT ONE OF THESE DID NOT FINISH - the connection
     * dropped mid-answer. `generateStream()` turns that into an exception rather
     * than letting a truncated answer look complete.
     */
    public function isTerminal(): bool
    {
        return in_array($this->type, [self::DONE, self::CANCELLED, self::ERROR], true);
    }
}
