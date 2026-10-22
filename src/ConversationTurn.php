<?php

namespace Shirahcan\AiClient;

use DateTimeImmutable;

/**
 * One turn: what a person asked, or what the assistant answered.
 *
 * ⚠ `body` AND `structured` ARE SEPARATE AND MUST STAY SEPARATE. A `ReeveAnswer` holds six
 * fields whose SEPARATION is a safety control - a ledger figure and a regulation must never
 * share a paragraph - and `GroundedAnswer` exists in `ai-ui` because a chat bubble could not
 * express that. Flattening `structured` into prose for display undoes the control after the
 * store went to the trouble of keeping it.
 *
 * ⚠ `refused` IS A FACT ABOUT THE TURN, NOT AN ERROR. The assistant declining is an answer; it
 * arrived as a 200 and it belongs in the transcript. Rendering it as an alert is the defect
 * `role="alert"` audits keep looking for.
 */
final class ConversationTurn
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * @param array<string, mixed>|null $structured
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly ?string $body,
        public readonly ?array $structured,
        public readonly bool $refused,

        /*
         * ⚠ IN CLEAR, AND NULLABLE, BECAUSE "NOT APPLICABLE" IS NOT "NOT GROUNDED". A person's
         * question cannot be grounded or invented. These two are what `porter:self-reflect`
         * groups by, and they are outside `structured` because ciphertext cannot be grouped -
         * inside it, the weekly backlog would return zeros from a 200.
         */
        public readonly ?bool $grounded,
        public readonly ?string $provenanceClass,

        public readonly ?string $personaKey,
        public readonly ?int $personaVersion,
        public readonly ?int $usageId,
        public readonly ?string $clientRef,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly array $raw = [],

        /*
         * ⚠ SET ONLY BY A CORPUS READ, AND NULL EVERYWHERE ELSE. Reading one thread, the caller
         * already holds the conversation - `ConversationPage` carries it - so repeating its id
         * and audience on every turn would be noise. Reading ACROSS threads they are the whole
         * point: a caller mining a window has to know which conversation a question came from
         * and which audience to file the gap under, and a role-blind entry is the defect
         * CLAUDE.md names, where a practising consultant was told to "ask your consultant".
         *
         * ⚠ AFTER `raw` BECAUSE A CONSTRUCTOR IS A CONTRACT. Three products already build this
         * object; inserting parameters in the middle would move everything after them.
         */
        public readonly ?string $conversationId = null,
        public readonly ?string $audience = null,
        public readonly ?string $ownerRef = null,

        /*
         * ⚠ THE THREAD'S SUMMARY AFTER THIS TURN WAS WRITTEN, AND IT CLOSES A SECOND
         * DERIVATION. The service already answers an append with it; the client dropped it, so
         * a product wanting to know when its thread was last active either asked again or
         * worked it out from `now()` - and the second of those is a product deciding a fact the
         * service owns. Portify indexes `last_message_at` for an `ORDER BY` it cannot do on a
         * hydrated attribute, and this is what it indexes: the service's answer, not its own.
         *
         * Null on a corpus read and on a page read, where the caller has the conversation
         * already.
         */
        public readonly ?Conversation $conversation = null,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $structured = $a['structured'] ?? null;

        return new self(
            id: (string) ($a['id'] ?? ''),
            role: (string) ($a['role'] ?? self::ROLE_USER),
            body: ($a['body'] ?? null) === null || $a['body'] === '' ? null : (string) $a['body'],
            structured: is_array($structured) && $structured !== [] ? $structured : null,
            refused: (bool) ($a['refused'] ?? false),
            grounded: array_key_exists('grounded', $a) && $a['grounded'] !== null ? (bool) $a['grounded'] : null,
            provenanceClass: ($a['provenance_class'] ?? null) === null ? null : (string) $a['provenance_class'],
            personaKey: ($a['persona_key'] ?? null) === null ? null : (string) $a['persona_key'],
            personaVersion: ($a['persona_version'] ?? null) === null ? null : (int) $a['persona_version'],
            usageId: ($a['usage_id'] ?? null) === null ? null : (int) $a['usage_id'],
            clientRef: ($a['client_ref'] ?? null) === null ? null : (string) $a['client_ref'],
            createdAt: self::time($a['created_at'] ?? null),
            raw: $a,
            conversationId: ($a['conversation_id'] ?? null) === null ? null : (string) $a['conversation_id'],
            audience: ($a['audience'] ?? null) === null ? null : (string) $a['audience'],
            ownerRef: ($a['owner_ref'] ?? null) === null ? null : (string) $a['owner_ref'],
        );
    }

    /**
     * The whole of an append's answer: the turn, plus the thread's summary after it.
     *
     * ⚠ A SEPARATE FACTORY RATHER THAN A `with` METHOD, because every property here is readonly
     * and PHP cannot modify one on a clone - so "set the conversation afterwards" would mean
     * re-listing all sixteen arguments at the one call site that needs it, which is how one of
     * them eventually gets dropped.
     *
     * @param array<string, mixed> $payload the append response: `turn`, `conversation`, `created`
     */
    public static function fromAppend(array $payload): self
    {
        $turn = self::fromArray((array) ($payload['turn'] ?? []));
        $conversation = $payload['conversation'] ?? null;

        if (! is_array($conversation) || $conversation === []) {
            return $turn;
        }

        $threadId = (string) ($conversation['id'] ?? '');

        return new self(
            id: $turn->id,
            role: $turn->role,
            body: $turn->body,
            structured: $turn->structured,
            refused: $turn->refused,
            grounded: $turn->grounded,
            provenanceClass: $turn->provenanceClass,
            personaKey: $turn->personaKey,
            personaVersion: $turn->personaVersion,
            usageId: $turn->usageId,
            clientRef: $turn->clientRef,
            createdAt: $turn->createdAt,
            raw: $turn->raw,
            conversationId: $turn->conversationId ?? ($threadId === '' ? null : $threadId),
            audience: $turn->audience,
            ownerRef: $turn->ownerRef,
            conversation: Conversation::fromArray($conversation),
        );
    }

    public function isFromTheAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function isFromThePerson(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /** A tool call and its result, kept as its own turn so "why did it say that" is answerable. */
    public function isTool(): bool
    {
        return $this->role === self::ROLE_TOOL;
    }

    /** One of the two is always present; the service refuses a turn with neither. */
    public function hasStructure(): bool
    {
        return $this->structured !== null;
    }

    /**
     * Whether this reply said where it came from at all.
     *
     * ⚠ A REPLY THAT SAID NOTHING IS NOT AN UNGROUNDED REPLY, and treating the two alike is
     * how a rate becomes meaningless: an assistant that reports nothing would read as one that
     * grounds nothing.
     */
    public function statedItsProvenance(): bool
    {
        return $this->grounded !== null;
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
