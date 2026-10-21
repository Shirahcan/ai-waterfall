<?php

namespace Shirahcan\AiClient;

/**
 * A product's conversations, held in ai-service.
 *
 * ⚠ THE SERVICE HOLDS THE TRANSCRIPT AND CANNOT IDENTIFY THE PERSON. `ownerRef` is YOUR id for
 * them - a user id, never a name or an email - and nothing in the service can map it back. That
 * is the bargain that makes a shared store acceptable: what is centralised is the text, not the
 * identity, and your product stays the only thing that can authorise a read.
 *
 * ⚠ SO AUTHORISATION IS YOURS. The trust key proves which product is calling, not which person.
 * Decide who may read a thread exactly as you decide who may read a case, BEFORE you call this.
 *
 * ⚠ PASS A `clientRef` ON EVERY WRITE. It is your own id for the thread or the message, and it
 * makes a retry safe: a call can still time out after the service has committed, and without it
 * the person sees their own question twice in their own history, or a blank thread nobody
 * started appears in their list. Nothing raises an error, because both writes succeeded.
 *
 * The shape of an exchange:
 *
 *     $thread = $ai->conversations()->start(
 *         ownerRef: (string) $user->id,
 *         audience: 'consultant',
 *         subjectRef: (string) $case->id,
 *         clientRef: (string) $row->id,
 *     );
 *
 *     $ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, $question, clientRef: $messageId);
 *     $ai->conversations()->append($thread->id, ConversationTurn::ROLE_ASSISTANT, $answer->text, structured: $answer->fields);
 */
class AiConversations
{
    /** The service's own cap on one batch hydrate. */
    public const MAX_BATCH = 200;

    /** The service's own cap on one page of turns. */
    public const MAX_PAGE = 200;

    public function __construct(private AiClient $client) {}

    /**
     * Start a thread, or return the one this `clientRef` already named.
     *
     * ⚠ IT CREATES THE THREAD AND NOTHING ELSE; the first turn is a separate `append()`. Two
     * loopback calls are a few milliseconds, and the alternative is one call that can
     * half-succeed.
     *
     * ⚠ THERE IS NO PER-THREAD RETENTION TO SET. The window is your product's POLICY, held in
     * the service and changeable from its admin surface without a deploy, and it is measured
     * from the last turn. "Remove this when the case closes" is not retention - it is
     * {@see erase()}.
     */
    public function start(
        string $ownerRef,
        ?string $audience = null,
        ?string $subjectRef = null,
        ?string $title = null,
        ?string $clientRef = null,
    ): Conversation {
        $payload = $this->client->postJson('api/v1/conversations', array_filter([
            'owner_ref'   => $ownerRef,
            'audience'    => $audience,
            'subject_ref' => $subjectRef,
            'title'       => $title,
            'client_ref'  => $clientRef,
        ], fn ($value) => $value !== null));

        return Conversation::fromArray((array) ($payload['conversation'] ?? []));
    }

    /**
     * Append a turn, or return the turn this `clientRef` already wrote.
     *
     * ⚠ ONE OF `body` OR `structured` IS REQUIRED and the service refuses a turn with neither:
     * an empty turn still bumps the count your own row hydrates, so it would promise a
     * transcript entry that says nothing.
     *
     * ⚠ A REFUSAL IS A TURN. Pass `refused: true` when the assistant declined, and store it as
     * what was said rather than as an error. In clear, it is the single most useful aggregate
     * the service holds, and "how often does this assistant decline, and to whom" is the
     * cheapest quality work available.
     *
     * @param array<string, mixed>|null $structured
     */
    public function append(
        string $conversationId,
        string $role,
        ?string $body = null,
        ?array $structured = null,
        bool $refused = false,
        ?string $personaKey = null,
        ?int $personaVersion = null,
        ?int $usageId = null,
        ?string $clientRef = null,
    ): ConversationTurn {
        $payload = $this->client->postJson("api/v1/conversations/{$conversationId}/turns", array_filter([
            'role' => $role,
            'body' => $body,

            /*
             * ⚠ AN EMPTY ARRAY IS NOT STRUCTURE. Sent as `[]` it would satisfy the service's
             * "one of the two is required" rule while carrying nothing, which is the empty
             * turn that rule exists to refuse - and the refusal would arrive as a validation
             * error from the wrong field.
             */
            'structured'      => $structured === [] ? null : $structured,
            'refused'         => $refused,
            'persona_key'     => $personaKey,
            'persona_version' => $personaVersion,
            'usage_id'        => $usageId,
            'client_ref'      => $clientRef,
        ], fn ($value) => $value !== null && $value !== false));

        return ConversationTurn::fromArray((array) ($payload['turn'] ?? []));
    }

    /**
     * One thread with a page of its turns, newest page first.
     *
     * ⚠ `before` IS A TURN ID FROM A PREVIOUS PAGE'S `nextBefore`, never one you construct.
     * A cursor the service does not recognise is refused rather than silently restarting.
     */
    public function read(string $conversationId, ?string $before = null, int $limit = 50): ConversationPage
    {
        $query = ['limit' => min($limit, self::MAX_PAGE)];

        if ($before !== null && $before !== '') {
            $query['before'] = $before;
        }

        return ConversationPage::fromArray($this->client->getJson("api/v1/conversations/{$conversationId}", $query));
    }

    /**
     * This person's threads, most recently active first. SUMMARY ONLY - no turn is read.
     *
     * ⚠ THAT IS THE WHOLE POINT OF THE SUMMARY SCALARS. A list of twenty threads must not cost
     * twenty transcripts, and a thread the person JUST started sorts first rather than last,
     * because the service orders by last activity falling back to creation.
     *
     * @return list<Conversation>
     */
    public function forOwner(string $ownerRef, ?string $subjectRef = null, int $limit = 20): array
    {
        $payload = $this->client->getJson('api/v1/conversations', array_filter([
            'owner_ref'   => $ownerRef,
            'subject_ref' => $subjectRef,
            'limit'       => $limit,
        ], fn ($value) => $value !== null));

        return array_map(
            fn (array $row) => Conversation::fromArray($row),
            array_values((array) ($payload['conversations'] ?? [])),
        );
    }

    /**
     * Hydrate many threads by id in ONE call, keyed by id.
     *
     * ⚠ THIS IS WHAT MAKES THE HELD PATTERN AFFORDABLE. Your rows carry these ids, and a list
     * of them must cost one request rather than one per row - an N+1 over a network is how a
     * shared service ends up slower than the thing it replaced.
     *
     * ⚠ AN ID THAT IS ABSENT FROM THE RESULT IS THE ANSWER, NOT AN ERROR: the service no longer
     * holds that thread, because retention erased it or somebody asked for it to go. Your row
     * is then no longer held, and that is a state to render rather than a failure to report.
     *
     * @param list<string> $ids
     * @return array<string, Conversation>
     */
    public function byIds(array $ids): array
    {
        $wanted = array_values(array_unique(array_filter($ids, fn ($id) => (string) $id !== '')));

        if ($wanted === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk($wanted, self::MAX_BATCH) as $chunk) {
            $payload = $this->client->getJson('api/v1/conversations', ['ids' => $chunk]);

            foreach ((array) ($payload['conversations'] ?? []) as $row) {
                $conversation = Conversation::fromArray((array) $row);
                $found[$conversation->id] = $conversation;
            }
        }

        return $found;
    }

    /**
     * Change a thread's own details.
     *
     * ⚠ ONLY WHAT YOU NAME CHANGES, and an explicit null clears a field. `ownerRef` is not
     * editable: moving a thread to another person is handing one person's transcript to
     * another, not editing a detail.
     *
     * @param array<string, mixed> $changes  any of title, audience, subject_ref
     */
    public function update(string $conversationId, array $changes): Conversation
    {
        $allowed = array_intersect_key($changes, array_flip(['title', 'audience', 'subject_ref']));

        $payload = $this->client->patchJson("api/v1/conversations/{$conversationId}", $allowed);

        return Conversation::fromArray((array) ($payload['conversation'] ?? []));
    }

    /**
     * Erase ONE thread and its turns, and get a receipt.
     *
     * ⚠ THIS IS AN OBLIGATION, NOT A CONVENIENCE. Moving the transcript into the service did
     * not move the promise you made your own client; `MeetingErasure` exists in Portify because
     * the calendar migration created exactly this problem first and nothing discharged it. The
     * receipt carries the counts because that is the part you cannot work out once the rows are
     * gone.
     *
     * @return array{erased: bool, conversations: int, turns: int}
     */
    public function erase(string $conversationId): array
    {
        return $this->receipt($this->client->deleteJson("api/v1/conversations/{$conversationId}"));
    }

    /**
     * Erase every thread this product holds for one person. "Forget me", discharged.
     *
     * @return array{erased: bool, conversations: int, turns: int}
     */
    public function eraseForOwner(string $ownerRef): array
    {
        return $this->receipt($this->client->deleteJson('api/v1/conversations', ['owner_ref' => $ownerRef]));
    }

    /**
     * Erase every thread about one subject, across people.
     *
     * ⚠ ACROSS PEOPLE DELIBERATELY. A case has more than one participant, and erasing it must
     * not depend on enumerating them - that is the shape that leaves one person's copy behind.
     *
     * @return array{erased: bool, conversations: int, turns: int}
     */
    public function eraseForSubject(string $subjectRef): array
    {
        return $this->receipt($this->client->deleteJson('api/v1/conversations', ['subject_ref' => $subjectRef]));
    }

    /**
     * What this product's assistant was asked and how often it declined - WITHOUT READING A WORD.
     *
     * ⚠ THIS IS THE COMPENSATION FOR ENCRYPTION. The bodies are ciphertext, so "what did people
     * ask this week" is no longer a query over text; the service keeps a narrow set of columns
     * in clear for exactly this, and nothing here decrypts anything.
     *
     * ⚠ READ `refused_by_audience`, NOT ONLY `refusal_rate`. A blended rate hides the failure
     * that actually happened in production: an assistant acceptable to clients while failing the
     * professionals who pay for it. A practising consultant was told to "ask your consultant".
     *
     * @return array<string, mixed>
     */
    public function stats(int $days = 30): array
    {
        return $this->client->getJson('api/v1/conversations/stats', ['days' => $days]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{erased: bool, conversations: int, turns: int}
     */
    private function receipt(array $payload): array
    {
        return [
            'erased'        => (bool) ($payload['erased'] ?? false),
            'conversations' => (int) ($payload['conversations'] ?? 0),
            'turns'         => (int) ($payload['turns'] ?? 0),
        ];
    }
}
