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

    /**
     * The service's own cap on one CORPUS page, which is higher than a thread page and should
     * be: a screenful of chat is 50 turns, while a weekly sweep over a corpus wants as many per
     * request as the service will decrypt at once.
     */
    public const MAX_CORPUS_PAGE = 500;

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
        ?bool $grounded = null,
        ?string $provenanceClass = null,
        ?\DateTimeInterface $occurredAt = null,
    ): ConversationTurn {
        /*
         * ⚠ BUILT EXPLICITLY, NOT FILTERED BY TRUTHINESS, and the reason is a bug this method
         * had for one commit. An `array_filter` that dropped nulls AND falses - written to keep
         * `refused: false` off the wire - also dropped `grounded: false`, which is the single
         * most useful value either of those fields carries: "this reply was NOT grounded" is
         * exactly what a self-reflect backlog is built from. The service would have recorded
         * null, the aggregate would have left the reply out of its denominator, and the
         * resulting rate would have looked HEALTHIER the worse the assistant got.
         *
         * A false is a value. Only a null means "nothing to say".
         */
        $body = ($body ?? '') === '' ? null : $body;
        $structured = $structured === [] ? null : $structured;

        $turn = ['role' => $role];

        if ($body !== null) {
            $turn['body'] = $body;
        }

        /*
         * ⚠ AN EMPTY ARRAY IS NOT STRUCTURE. Sent as `[]` it would satisfy the service's "one
         * of the two is required" rule while carrying nothing, which is the empty turn that
         * rule exists to refuse - and the refusal would arrive as a validation error about the
         * wrong field.
         */
        if ($structured !== null) {
            $turn['structured'] = $structured;
        }

        if ($refused) {
            $turn['refused'] = true;
        }

        /*
         * ⚠ PASS THESE ON EVERY ASSISTANT REPLY. They are in clear, so they are the only part
         * of a reply that can still be GROUPED once the bodies are ciphertext, and
         * `$ai->conversations()->stats()` reports them per audience - which is where a
         * self-reflect backlog comes from. Leave them null on a person's question and on a
         * reply that genuinely says nothing about where it came from, because a reply that said
         * nothing is not an ungrounded reply.
         */
        if ($grounded !== null) {
            $turn['grounded'] = $grounded;
        }

        if ($provenanceClass !== null) {
            $turn['provenance_class'] = $provenanceClass;
        }

        /*
         * ⚠ ONLY FOR A TURN BEING MIRRORED, never for a live one. A live turn omits it and the
         * service timestamps it, which is the only version a product cannot get wrong. Pass it
         * when moving an existing transcript, or every message of a two-year conversation will
         * carry the moment of the backfill and read as a single afternoon - and it is also what
         * makes mirroring safe to do on a QUEUE, since two jobs running out of order still
         * produce the right order.
         */
        if ($occurredAt !== null) {
            $turn['occurred_at'] = $occurredAt->format(DATE_ATOM);
        }

        foreach (['persona_key' => $personaKey, 'persona_version' => $personaVersion,
            'usage_id' => $usageId, 'client_ref' => $clientRef] as $field => $value) {
            if ($value !== null) {
                $turn[$field] = $value;
            }
        }

        $payload = $this->client->postJson("api/v1/conversations/{$conversationId}/turns", $turn);

        /*
         * ⚠ `fromAppend`, NOT `fromArray`, so the thread's summary AFTER this turn comes back
         * with it. The service has always answered with it and this client dropped it, which
         * left a product that needs to know when its thread was last active either asking again
         * or writing `now()` - and the second is a product deciding a fact the service owns.
         */
        return ConversationTurn::fromAppend($payload);
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
     * One page of this product's turns from ACROSS its threads, newest first.
     *
     * ⚠ THIS IS THE CORPUS READ, AND IT IS NOT A SUBSTITUTE FOR `read()`. Use `read()` for the
     * thread somebody is looking at. Use this when the question is about the corpus and the
     * WORDS are the answer: the composer's recall ring (this person's last 300 questions across
     * threads), `porter:mine-knowledge-gaps`, `porter:reflect`. Each of those is one SQL query
     * while the transcript is local and N requests of M turns without this.
     *
     * ⚠ AND ASK `stats()` INSTEAD WHEN THE ANSWER IS A COUNT. This read decrypts every body it
     * returns; `stats()` is built from the clear-text columns and reads no words at all. "How
     * often did it refuse, per audience" is a stats question, not a corpus question.
     */
    public function turns(CorpusFilter $filter = new CorpusFilter(), int $limit = 100, ?string $before = null): CorpusPage
    {
        $query = $filter->toQuery();
        $query['limit'] = min($limit, self::MAX_CORPUS_PAGE);

        if ($before !== null && $before !== '') {
            $query['before'] = $before;
        }

        return CorpusPage::fromArray($this->client->getJson('api/v1/conversations/turns', $query));
    }

    /**
     * Every matching turn, paging as it goes.
     *
     * ⚠ THE PAGING LOOP LIVES HERE SO FOUR PRODUCTS DO NOT EACH WRITE IT. A loop that forgets
     * to stop on a null cursor runs for ever and a loop that forgets to pass `before` fetches
     * page one repeatedly - both of which look like a working command that is simply slow.
     *
     * ⚠ `$maxTurns` IS A STOP, NOT A PAGE SIZE. A weekly report over a year of transcripts
     * should be able to say "the most recent 2,000 and no more" without the caller counting.
     *
     *     foreach ($ai->conversations()->eachTurn($filter) as $turn) { ... }
     *
     * @return \Generator<int, ConversationTurn>
     */
    public function eachTurn(CorpusFilter $filter = new CorpusFilter(), int $perPage = 200, int $maxTurns = 0): \Generator
    {
        $before = null;
        $yielded = 0;

        do {
            $page = $this->turns($filter, $perPage, $before);

            foreach ($page->turns as $turn) {
                yield $turn;

                $yielded++;

                if ($maxTurns > 0 && $yielded >= $maxTurns) {
                    return;
                }
            }

            $before = $page->nextBefore;

            /*
             * ⚠ BOTH CONDITIONS, because either one alone has failed in this estate. A page
             * that says `has_more` with no cursor would loop on page one for ever; a cursor
             * present on the last page would make one wasted request per sweep.
             */
        } while ($page->hasMore && $before !== null);
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
     * Record what became of a turn: a confirm card's state (P21a).
     *
     * ⚠ ONLY THE STATE. The body is the record of what was said and the service will not
     * rewrite it. Null clears the state.
     *
     * @param array<string, mixed>|null $state
     */
    public function updateTurnState(string $conversationId, string $turnId, ?array $state): ConversationTurn
    {
        $payload = $this->client->patchJson("api/v1/conversations/{$conversationId}/turns/{$turnId}", ['state' => $state]);

        return ConversationTurn::fromArray((array) ($payload['turn'] ?? []));
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
     * ⚠ READ `refused_by_audience` AND `grounding_by_audience`, NOT ONLY `refusal_rate`. A
     * blended rate hides the failure that actually happened in production: an assistant
     * acceptable to clients while failing the professionals who pay for it. A practising
     * consultant was told to "ask your consultant".
     *
     * @return array<string, mixed>
     */
    public function stats(int $days = 30): array
    {
        return $this->client->getJson('api/v1/conversations/stats', ['days' => $days]);
    }

    /**
     * The reason codes the shared rating control offers (`ASSISTANT_FEEDBACK_REASONS` in
     * `@shirahcan/ai-ui`). The service refuses anything else, and so does the fake.
     */
    public const FEEDBACK_REASONS = [
        'wrong_topic',
        'outdated_info',
        'didnt_understand',
        'missing_detail',
        'unhelpful_other',
    ];

    /**
     * Record one person's verdict on one assistant answer (P19a).
     *
     * ⚠ KEYED ON THE SERVICE'S TURN ID, NOT THE PRODUCT'S MESSAGE ID. Before P19a each product
     * kept its own feedback table keyed to its own ids, and Portify's `AssistantMessage`
     * records what happened when the two were confused: every vote a silent 404. A product
     * that only has its own id must resolve the service turn first (the turn's `client_ref`).
     *
     * ⚠ CALLING IT AGAIN FOR THE SAME (turn, voter) CHANGES THE VOTE. A person who clicks
     * thumbs-down and then thumbs-up has changed their mind, not voted twice.
     *
     * ⚠ A REASON IS ONLY VALID ON A THUMBS-DOWN, and only one of `FEEDBACK_REASONS`.
     *
     * @return array<string, mixed> the stored vote
     */
    public function rate(
        string $conversationId,
        string $turnId,
        string $voterRef,
        string $sentiment,
        ?string $reasonCode = null,
        ?string $reasonText = null,
        ?string $clientRef = null,
        ?\DateTimeInterface $occurredAt = null,
    ): array {
        $body = ['voter_ref' => $voterRef, 'sentiment' => $sentiment];

        if ($reasonCode !== null && $reasonCode !== '') {
            $body['reason_code'] = $reasonCode;
        }

        if ($reasonText !== null && trim($reasonText) !== '') {
            $body['reason_text'] = $reasonText;
        }

        if ($clientRef !== null && $clientRef !== '') {
            $body['client_ref'] = $clientRef;
        }

        if ($occurredAt !== null) {
            $body['occurred_at'] = $occurredAt->format(\DateTimeInterface::ATOM);
        }

        $payload = $this->client->postJson(
            "api/v1/conversations/{$conversationId}/turns/{$turnId}/feedback",
            $body,
        );

        return (array) ($payload['feedback'] ?? []);
    }

    /**
     * Votes grouped by audience, sentiment and reason, without reading a note.
     *
     * @return list<array{audience: ?string, sentiment: string, reason_code: ?string, votes: int}>
     */
    public function feedbackStats(?\DateTimeInterface $since = null): array
    {
        $query = $since === null ? [] : ['since' => $since->format(\DateTimeInterface::ATOM)];

        return array_values((array) ($this->client->getJson('api/v1/conversations/feedback/stats', $query)['rows'] ?? []));
    }

    /**
     * Recent votes with the answer each one judged. Decrypts, so it pages; ask
     * `feedbackStats()` when the answer is a count.
     *
     * @return list<array<string, mixed>>
     */
    public function feedback(?string $sentiment = null, ?\DateTimeInterface $since = null, int $limit = 50): array
    {
        $query = ['limit' => $limit];

        if ($sentiment !== null) {
            $query['sentiment'] = $sentiment;
        }

        if ($since !== null) {
            $query['since'] = $since->format(\DateTimeInterface::ATOM);
        }

        return array_values((array) ($this->client->getJson('api/v1/conversations/feedback', $query)['feedback'] ?? []));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{erased: bool, conversations: int, turns: int, memories: int}
     */
    private function receipt(array $payload): array
    {
        return [
            'erased'        => (bool) ($payload['erased'] ?? false),
            'conversations' => (int) ($payload['conversations'] ?? 0),
            'turns'         => (int) ($payload['turns'] ?? 0),
            // P19e: erasing a person also erases what was remembered about them.
            'memories'      => (int) ($payload['memories'] ?? 0),
        ];
    }
}
