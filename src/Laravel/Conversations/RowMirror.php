<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Illuminate\Database\Eloquent\Model;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\ConversationTurn;

/**
 * Mirror a product's own conversation and message ROWS into ai-service, the mechanics only.
 *
 * Generalised from Portify's `TranscriptMirror`. The product decides what a row MEANS (owner,
 * audience, title, the structured part of a reply); this class does the parts every product got
 * wrong at least once:
 *
 * ⚠ IDEMPOTENT AT BOTH LEVELS. `client_ref` carries the row's own key, so a retried request, a
 * resumed backfill or a double call returns the FIRST thread or turn instead of a second one -
 * which would otherwise show a person their own question twice with no error anywhere.
 *
 * ⚠ THE SERVICE'S ID IS WRITTEN BACK QUIETLY AND WITHOUT TIMESTAMPS. Touching `updated_at` on a
 * bookkeeping write reorders a person's thread list by when we mirrored, not when they spoke; and
 * firing model events would re-enter the very hook that called the mirror. A row that does not
 * exist yet (the mirror runs in `creating`) carries the id in its INSERT instead.
 *
 * ⚠ IT THROWS WHEN THE SERVICE CANNOT BE REACHED. Once reads come from the service, a swallowed
 * write is a MISSING MESSAGE, so a caller that writes before its own insert lets the refusal stop
 * the local row too, and the two stores cannot disagree about whether a turn exists.
 */
final class RowMirror
{
    public function __construct(private readonly AiClient $ai) {}

    /** The service's thread for this row, started once and remembered in `$column`. */
    public function thread(
        Model $row,
        string $column,
        string $ownerRef,
        ?string $audience = null,
        ?string $title = null,
        ?string $subjectRef = null,
    ): string {
        $known = (string) ($row->getAttribute($column) ?? '');
        if ($known !== '') {
            return $known;
        }

        $thread = $this->ai->conversations()->start(
            ownerRef: $ownerRef,
            audience: $audience,
            subjectRef: $subjectRef,
            title: $title,
            clientRef: (string) $row->getKey(),
        );

        $this->remember($row, $column, $thread->id);

        return $thread->id;
    }

    /**
     * Append this message row as a turn, once, and remember the turn id in `$column`.
     *
     * @param  array{structured?: ?array, refused?: bool, persona_key?: ?string, grounded?: ?bool,
     *               provenance_class?: ?string, occurred_at?: ?\DateTimeInterface}  $turn
     * @return ConversationTurn|null  null when the row already carries its turn id
     */
    public function turn(Model $row, string $column, string $conversationId, string $role, ?string $body, array $turn = []): ?ConversationTurn
    {
        if ((string) ($row->getAttribute($column) ?? '') !== '') {
            return null;
        }

        $appended = $this->ai->conversations()->append(
            conversationId: $conversationId,
            role: $role,
            body: $body,
            structured: $turn['structured'] ?? null,
            refused: (bool) ($turn['refused'] ?? false),
            personaKey: $turn['persona_key'] ?? null,
            clientRef: (string) $row->getKey(),
            grounded: $turn['grounded'] ?? null,
            provenanceClass: $turn['provenance_class'] ?? null,
            occurredAt: $turn['occurred_at'] ?? null,
        );

        $this->remember($row, $column, $appended->id);

        return $appended;
    }

    /** Set a bookkeeping attribute without touching timestamps or firing events. */
    public function remember(Model $row, string $column, mixed $value): void
    {
        $row->setAttribute($column, $value);

        if ($row->exists) {
            $row::withoutTimestamps(fn () => $row->updateQuietly([$column => $value]));
        }
    }
}
