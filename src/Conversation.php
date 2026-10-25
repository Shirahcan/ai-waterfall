<?php

namespace Shirahcan\AiClient;

use DateTimeImmutable;

/**
 * A conversation as the owning product sees it: the SUMMARY, never the transcript.
 *
 * Store `id` against your own record. The service holds the text; your row holds its own
 * foreign keys and this id, exactly as a `Meeting` row holds a booking id.
 *
 * ⚠ `turnCount` AND `lastTurnAt` ARE THE WHOLE REASON THE HELD PATTERN WORKS HERE. A booking's
 * held facts are a handful of scalars on one row, but a conversation's are a LIST that grows,
 * so hydrating turns for every row of a list view would be N requests of M turns. These three
 * scalars hydrate for a whole collection in one call, and the turns are fetched only for the
 * thread actually on screen.
 *
 * ⚠ `title` CAN BE NULL AND THAT IS NOT AN ERROR - a thread may not have been named yet. Do
 * not fall back to the id for a human: a reference identifies a thread to the SYSTEM, which is
 * the same mistake as showing a case number where a person needs a name.
 */
final class Conversation
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $product,
        public readonly string $ownerRef,
        public readonly ?string $audience,
        public readonly ?string $subjectRef,
        public readonly ?string $clientRef,
        public readonly ?string $title,
        public readonly int $turnCount,
        public readonly ?DateTimeImmutable $lastTurnAt,
        public readonly ?DateTimeImmutable $retentionUntil,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly array $raw = [],

        /*
         * P21h: the assistant's running digest of this thread (encrypted in the service). Last, so
         * no existing caller's argument moves.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $state = null,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) ($a['id'] ?? ''),
            product: (string) ($a['product'] ?? ''),
            ownerRef: (string) ($a['owner_ref'] ?? ''),
            audience: self::text($a['audience'] ?? null),
            subjectRef: self::text($a['subject_ref'] ?? null),
            clientRef: self::text($a['client_ref'] ?? null),
            title: self::text($a['title'] ?? null),
            turnCount: (int) ($a['turn_count'] ?? 0),
            lastTurnAt: self::time($a['last_turn_at'] ?? null),
            retentionUntil: self::time($a['retention_until'] ?? null),
            createdAt: self::time($a['created_at'] ?? null),
            raw: $a,
            state: is_array($a['state'] ?? null) ? $a['state'] : null,
        );
    }

    /**
     * When the service will erase this thread, if nothing else is said in it.
     *
     * ⚠ SURFACE IT RATHER THAN HIDING IT. The window moves forward on every turn, so a thread
     * in use is never stale - but a product that shows a person their own history owes them
     * the truth about how long it is kept, and a product that caches anything derived from a
     * transcript needs to know when the transcript goes.
     */
    public function isPastRetention(?DateTimeImmutable $now = null): bool
    {
        if ($this->retentionUntil === null) {
            return false;
        }

        return $this->retentionUntil <= ($now ?? new DateTimeImmutable);
    }

    /** True once at least one thing has been said. A thread with no turns is a real state. */
    public function hasTurns(): bool
    {
        return $this->turnCount > 0;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return $text === '' ? null : $text;
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            // A date the service sent that we cannot read is a missing date, not a crash in
            // somebody's chat list.
            return null;
        }
    }
}
