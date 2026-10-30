<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Conversation;
use Shirahcan\AiClient\ConversationTurn;

/**
 * An assistant's threads kept by ai-service ONLY, for a product that holds no local copy.
 *
 * Generalised from Finance's `ReeveThreads` (P20g2) so every product records, lists, reopens,
 * erases and rates its threads the same way. A product that keeps its own rows and MIRRORS them
 * wants {@see RowMirror} instead.
 *
 * ⚠ OWNERSHIP IS CHECKED HERE, ON EVERY READ AND WRITE. The trust key is the PRODUCT's, so the
 * service hands any of that product's threads to any of its callers; which person a thread belongs
 * to is the product's rule, and this class is where it is enforced. A thread that is not the
 * caller's reads as absent, never as forbidden, so its existence is not disclosed either.
 *
 * ⚠ THE OWNER IS A HASH, NOT THE PERSON. `owner_ref` is stored in clear so the service can list and
 * erase by it; an email address there would be personal data outside the encryption that protects
 * the thread itself. Use {@see ownerRef()}.
 */
final class OwnedThreads
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $audience,
        private readonly ?string $personaKey = null,
        private readonly int $titleLength = 80,
    ) {}

    /** A stable, non-identifying owner key: `prefix:sha256(normalised id)`. */
    public static function ownerRef(string $prefix, string $identifier): string
    {
        return $prefix.':'.hash('sha256', mb_strtolower(trim($identifier)));
    }

    /**
     * Record one question and its answer. Starts a thread when none is named, or when the one
     * named is not this owner's.
     *
     * @param  array<string, mixed>|null  $structured  the answer as it was given (kept, not flattened)
     * @return array{conversation_id: string, turn_id: string}
     */
    public function record(
        string $ownerRef,
        ?string $conversationId,
        string $question,
        string $answerBody,
        ?array $structured = null,
        bool $refused = false,
        ?bool $grounded = null,
        ?string $provenanceClass = null,
    ): array {
        $conversations = $this->ai->conversations();
        $thread = $conversationId !== null && $conversationId !== '' ? $this->owned($ownerRef, $conversationId) : null;

        if ($thread === null) {
            $thread = $conversations->start($ownerRef, $this->audience, title: mb_substr(trim($question), 0, $this->titleLength));
        }

        $conversations->append($thread->id, ConversationTurn::ROLE_USER, body: $question);
        $turn = $conversations->append(
            $thread->id,
            ConversationTurn::ROLE_ASSISTANT,
            body: $answerBody,
            structured: $structured,
            refused: $refused,
            personaKey: $this->personaKey,
            grounded: $grounded,
            provenanceClass: $provenanceClass,
        );

        return ['conversation_id' => $thread->id, 'turn_id' => $turn->id];
    }

    /** @return list<array{id: string, title: ?string, updated_at: ?string}> */
    public function list(string $ownerRef, int $limit = 30): array
    {
        return array_values(array_map(static fn (Conversation $c) => [
            'id'         => $c->id,
            'title'      => $c->title,
            'updated_at' => ($c->lastTurnAt ?? $c->createdAt)?->format(DATE_ATOM),
        ], $this->ai->conversations()->forOwner($ownerRef, limit: $limit)));
    }

    /**
     * One thread as a panel reopens it, or null when it is not this owner's.
     *
     * @return array{id: string, messages: list<array{id: string, role: string, content: string, structured: ?array}>}|null
     */
    public function read(string $ownerRef, string $conversationId, int $limit = 200): ?array
    {
        if ($this->owned($ownerRef, $conversationId) === null) {
            return null;
        }

        $page = $this->ai->conversations()->read($conversationId, limit: $limit);

        return [
            'id'       => $conversationId,
            'messages' => array_map(static fn (ConversationTurn $t) => [
                'id'         => $t->id,
                'role'       => $t->role,
                'content'    => (string) ($t->body ?? ''),
                'structured' => $t->role === ConversationTurn::ROLE_ASSISTANT ? $t->structured : null,
            ], $page->turns),
        ];
    }

    public function erase(string $ownerRef, string $conversationId): bool
    {
        if ($this->owned($ownerRef, $conversationId) === null) {
            return false;
        }

        $this->ai->conversations()->erase($conversationId);

        return true;
    }

    /** A vote on one answer; the owner is the voter. False when the thread is not theirs. */
    public function rate(string $ownerRef, string $conversationId, string $turnId, string $sentiment, ?string $reasonCode = null, ?string $reasonText = null): bool
    {
        if ($this->owned($ownerRef, $conversationId) === null) {
            return false;
        }

        $this->ai->conversations()->rate($conversationId, $turnId, $ownerRef, $sentiment, $reasonCode, $reasonText);

        return true;
    }

    public function owned(string $ownerRef, string $conversationId): ?Conversation
    {
        foreach ($this->ai->conversations()->byIds([$conversationId]) as $thread) {
            if ($thread instanceof Conversation && $thread->id === $conversationId && $thread->ownerRef === $ownerRef) {
                return $thread;
            }
        }

        return null;
    }
}
