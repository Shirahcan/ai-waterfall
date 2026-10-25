<?php

namespace Shirahcan\AiClient;

use DateTimeImmutable;
use RuntimeException;

/**
 * An in-memory conversation store, so a product can test its chat with no service.
 *
 * ⚠ IT MODELS THE RULES, NOT JUST THE SHAPE, because the rules are what a consumer gets wrong:
 *
 *   - a repeated `clientRef` returns the FIRST thread or turn, and writes nothing;
 *   - `turnCount` is COUNTED from the turns, so it falls when one is erased;
 *   - `lastTurnAt` moves with the last turn, and a thread with no turns still sorts by when it
 *     was created, not last;
 *   - a page of turns is oldest-first within itself, pages walk BACKWARDS, and an unrecognised
 *     cursor is refused rather than silently restarting;
 *   - a turn with neither body nor structure is refused;
 *   - an erasure takes the turns with it, and `byIds()` then OMITS that id.
 *
 * ⚠ THE LAST ONE IS THE RULE PRODUCTS WILL FORGET. A thread can be gone - retention erases on a
 * schedule - and a product that assumes `byIds()` returns everything it asked for shows a person
 * a conversation with no messages in it. This fake makes that branch reachable in a test:
 *
 *     $fake->conversations()->erase($id);     // or ->pretendRetentionErased($id)
 *
 * ⚠ IT DOES NOT MODEL THE ENCRYPTION, and no test should assert on it. What is stored here is
 * plain PHP; whether the real service wrote ciphertext is the service's own test, and a fake
 * pretending otherwise would invite assertions the real thing never reproduces.
 */
class FakeAiConversations extends AiConversations
{
    /** @var array<string, array<string, mixed>> conversation id => summary row */
    private array $threads = [];

    /** @var array<string, list<array<string, mixed>>> conversation id => turn rows */
    private array $turns = [];

    /** @var list<array<string, mixed>> every write, in order, for assertions */
    public array $writes = [];

    /** @var array<string, array<string, mixed>> "turn|voter" => vote, as the service holds them */
    public array $votes = [];

    /**
     * How many BATCH READS have happened.
     *
     * ⚠ THE NUMBER A PRODUCT'S TEST SHOULD ASSERT ON. The held pattern's whole claim is that a
     * collection of rows costs ONE call; an N+1 over a network is invisible in a passing test
     * and obvious only in production. Assert this is 1 after loading a list.
     */
    public int $batchReads = 0;

    /** Linked by FakeAiClient so erasing a person also forgets them, as the service does. */
    public ?FakeAiMemoryStore $memory = null;

    private int $sequence = 0;

    private string $product;

    public function __construct(string $product = 'fake-product')
    {
        // Deliberately no parent::__construct: there is no client and no network.
        $this->product = $product;
    }

    public function start(
        string $ownerRef,
        ?string $audience = null,
        ?string $subjectRef = null,
        ?string $title = null,
        ?string $clientRef = null,
    ): Conversation {
        if ($clientRef !== null) {
            foreach ($this->threads as $row) {
                if (($row['client_ref'] ?? null) === $clientRef) {
                    return Conversation::fromArray($row);
                }
            }
        }

        $id = $this->nextId('conversation');

        $this->threads[$id] = [
            'id'              => $id,
            'product'         => $this->product,
            'owner_ref'       => $ownerRef,
            'audience'        => $audience,
            'subject_ref'     => $subjectRef,
            'client_ref'      => $clientRef,
            'title'           => $title,
            'turn_count'      => 0,
            'last_turn_at'    => null,
            'retention_until' => (new DateTimeImmutable('+365 days'))->format(DATE_ATOM),
            'created_at'      => $this->now(),
        ];

        $this->turns[$id] = [];
        $this->writes[] = ['start' => $id, 'owner_ref' => $ownerRef, 'client_ref' => $clientRef];

        return Conversation::fromArray($this->threads[$id]);
    }

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
        $this->mustHold($conversationId);

        if (($body === null || $body === '') && ($structured === null || $structured === [])) {
            throw new RuntimeException(
                'ai-service refuses a turn with neither body nor structured: it would still bump the '
                .'turn count your own row hydrates.'
            );
        }

        if ($clientRef !== null) {
            foreach ($this->turns[$conversationId] as $existing) {
                if (($existing['client_ref'] ?? null) === $clientRef) {
                    return ConversationTurn::fromArray($existing);
                }
            }
        }

        $turn = [
            'id'              => $this->nextId('turn'),
            'role'            => $role,
            'body'            => $body,
            'structured'      => $structured === [] ? null : $structured,
            'refused'          => $refused,
            'grounded'         => $grounded,
            'provenance_class' => $provenanceClass,
            'persona_key'     => $personaKey,
            'persona_version' => $personaVersion,
            'usage_id'        => $usageId,
            'client_ref'      => $clientRef,
            /*
             * ⚠ UTC, BOTH SIDES. Turns are ordered by comparing these strings; a product clock in
             * Toronto and a fake clock in UTC sorted a later turn first. The service compares real
             * datetimes, so the fake must not be order-sensitive to the caller's timezone.
             */
            'created_at'      => $occurredAt !== null
                ? DateTimeImmutable::createFromInterface($occurredAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')
                : $this->now(),
        ];

        $this->turns[$conversationId][] = $turn;

        /*
         * ⚠ KEPT IN THE ORDER IT HAPPENED, NOT THE ORDER IT ARRIVED, exactly as the service
         * does it. A fake that appended blindly would let a product's paging test pass on an
         * order production would never produce.
         */
        usort($this->turns[$conversationId], fn (array $a, array $b) => strcmp(
            (string) $a['created_at'],
            (string) $b['created_at'],
        ));
        $this->resummarise($conversationId);

        $this->writes[] = ['append' => $conversationId, 'role' => $role, 'client_ref' => $clientRef];

        /*
         * ⚠ WITH THE THREAD'S SUMMARY, because the real append answers with it and a product
         * now INDEXES that answer. A fake that returned the turn alone would let a product's
         * "the index came from the service" test pass while the product quietly wrote `now()`.
         */
        return ConversationTurn::fromAppend([
            'turn'         => $turn,
            'conversation' => $this->threads[$conversationId],
        ]);
    }

    /**
     * One page of this product's turns from ACROSS its threads, newest first.
     *
     * ⚠ EVERY FILTER THE SERVICE APPLIES, APPLIED HERE TOO, and `grounded`/`refused` are
     * compared with `!== null` rather than truthiness for the reason the real client learned
     * the hard way: `grounded: false` IS the backlog, and a fake that dropped it would make a
     * product's "find the ungrounded replies" test pass against every reply.
     */
    public function turns(CorpusFilter $filter = new CorpusFilter(), int $limit = 100, ?string $before = null): CorpusPage
    {
        $rows = [];

        foreach ($this->threads as $id => $thread) {
            if (! $this->threadMatches($thread, $filter)) {
                continue;
            }

            foreach ($this->turns[$id] ?? [] as $turn) {
                if ($this->turnMatches($turn, $filter)) {
                    $rows[] = $turn + [
                        'conversation_id' => $id,
                        'audience'        => $thread['audience'] ?? null,
                        'owner_ref'       => $thread['owner_ref'] ?? null,
                    ];
                }
            }
        }

        /*
         * Newest first, on (created_at, id), so a backfilled transcript that shares one second
         * still has a total order - which is the whole reason the service's cursor is a pair.
         */
        usort($rows, fn (array $a, array $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        if ($before !== null && $before !== '') {
            $cut = null;

            foreach ($rows as $index => $row) {
                if ($row['id'] === $before) {
                    $cut = $index;
                    break;
                }
            }

            // Refused, not restarted, exactly as the service does it.
            if ($cut === null) {
                throw new RuntimeException(
                    'unknown_cursor: that `before` turn is not one of this product\'s turns, so no page could be taken from it.'
                );
            }

            $rows = array_slice($rows, $cut + 1);
        }

        $page = array_slice($rows, 0, $limit);
        $hasMore = count($rows) > count($page);

        return new CorpusPage(
            turns: array_map(fn (array $row) => ConversationTurn::fromArray($row), $page),
            hasMore: $hasMore,
            nextBefore: $hasMore && $page !== [] ? (string) $page[count($page) - 1]['id'] : null,
        );
    }

    /**
     * Every matching turn, paging as it goes.
     *
     * ⚠ THE SAME LOOP AS THE REAL CLIENT'S, so a product's sweep is exercised rather than
     * side-stepped: a fake that handed everything back in one go would never reach the second
     * page, which is where a paging bug lives.
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
        } while ($page->hasMore && $before !== null);
    }

    /** @param array<string, mixed> $thread */
    private function threadMatches(array $thread, CorpusFilter $filter): bool
    {
        foreach ([
            'owner_ref'   => $filter->ownerRef,
            'subject_ref' => $filter->subjectRef,
            'audience'    => $filter->audience,
        ] as $field => $wanted) {
            if ($wanted !== null && $wanted !== '' && ($thread[$field] ?? null) !== $wanted) {
                return false;
            }
        }

        return $filter->conversationIds === []
            || in_array((string) ($thread['id'] ?? ''), $filter->conversationIds, true);
    }

    /** @param array<string, mixed> $turn */
    private function turnMatches(array $turn, CorpusFilter $filter): bool
    {
        if ($filter->turnIds !== [] && ! in_array($turn['id'], $filter->turnIds, true)) {
            return false;
        }

        if ($filter->role !== null && $filter->role !== '' && $turn['role'] !== $filter->role) {
            return false;
        }

        if ($filter->provenanceClass !== null && $filter->provenanceClass !== ''
            && ($turn['provenance_class'] ?? null) !== $filter->provenanceClass) {
            return false;
        }

        /*
         * ⚠ `!== null` ON BOTH SIDES, AND THE SECOND HALF IS A BUG THIS FAKE HAD UNTIL A TEST
         * FOUND IT. On the filter side a deliberate false is a value to match on, not an absent
         * filter. On the TURN side a null is "not applicable" and must never satisfy
         * `grounded: false`: the service compares in SQL, where `grounded = 0` is NULL rather
         * than true for a null column, so a person's QUESTION is not in the ungrounded replies.
         * Casting the column to bool made `null` look like `false`, which handed a product
         * mining its backlog every question alongside every failed answer - and the fake would
         * have been MORE PERMISSIVE THAN THE SERVICE, which is the one thing a fake must never
         * be. "Not applicable is not not-grounded" is the whole reason the column is nullable.
         */
        foreach (['refused' => $filter->refused, 'grounded' => $filter->grounded] as $field => $wanted) {
            if ($wanted === null) {
                continue;
            }

            $value = $turn[$field] ?? null;

            if ($value === null || (bool) $value !== $wanted) {
                return false;
            }
        }

        $at = (string) ($turn['created_at'] ?? '');

        if ($filter->since !== null && $at < $filter->since->format('Y-m-d\TH:i:s')) {
            return false;
        }

        if ($filter->until !== null && $at > $filter->until->format('Y-m-d\TH:i:s.uP')) {
            return false;
        }

        return true;
    }

    public function read(string $conversationId, ?string $before = null, int $limit = 50): ConversationPage
    {
        $this->mustHold($conversationId);

        $all = $this->turns[$conversationId];

        if ($before !== null && $before !== '') {
            $cut = null;

            foreach ($all as $index => $turn) {
                if ($turn['id'] === $before) {
                    $cut = $index;
                    break;
                }
            }

            /*
             * ⚠ REFUSED, NOT RESTARTED, exactly as the service does it. Returning page one for
             * a cursor the caller believed in is how a paging loop runs for ever while every
             * response looks valid.
             */
            if ($cut === null) {
                throw new RuntimeException(
                    'unknown_cursor: that `before` turn is not in this conversation, so no page could be taken from it.'
                );
            }

            $all = array_slice($all, 0, $cut);
        }

        $page = array_slice($all, max(0, count($all) - $limit));
        $hasMore = count($all) > count($page);

        return new ConversationPage(
            conversation: Conversation::fromArray($this->threads[$conversationId]),
            turns: array_map(fn (array $turn) => ConversationTurn::fromArray($turn), $page),
            hasMore: $hasMore,
            nextBefore: $hasMore ? ($page[0]['id'] ?? null) : null,
        );
    }

    /** @return list<Conversation> */
    public function forOwner(string $ownerRef, ?string $subjectRef = null, int $limit = 20): array
    {
        $rows = array_filter($this->threads, function (array $row) use ($ownerRef, $subjectRef) {
            if ($row['owner_ref'] !== $ownerRef) {
                return false;
            }

            return $subjectRef === null || ($row['subject_ref'] ?? null) === $subjectRef;
        });

        // The service's ordering: last activity, FALLING BACK to creation, so a thread somebody
        // just started sorts first rather than last.
        usort($rows, fn (array $a, array $b) => strcmp(
            (string) ($b['last_turn_at'] ?? $b['created_at']),
            (string) ($a['last_turn_at'] ?? $a['created_at']),
        ));

        return array_map(
            fn (array $row) => Conversation::fromArray($row),
            array_slice(array_values($rows), 0, $limit),
        );
    }

    /** @return array<string, Conversation> */
    public function byIds(array $ids): array
    {
        $this->batchReads++;

        $found = [];

        foreach (array_unique(array_filter($ids, fn ($id) => (string) $id !== '')) as $id) {
            if (isset($this->threads[(string) $id])) {
                $found[(string) $id] = Conversation::fromArray($this->threads[(string) $id]);
            }
        }

        return $found;
    }

    public function update(string $conversationId, array $changes): Conversation
    {
        $this->mustHold($conversationId);

        foreach (array_intersect_key($changes, array_flip(['title', 'audience', 'subject_ref', 'state'])) as $field => $value) {
            $this->threads[$conversationId][$field] = $value;
        }

        $this->writes[] = ['update' => $conversationId, 'changes' => $changes];

        return Conversation::fromArray($this->threads[$conversationId]);
    }

    /**
     * ⚠ ONLY THE STATE CHANGES, and a turn of ANOTHER thread is not found, exactly as the
     * service scopes it: a product that passed the wrong conversation id must fail here too.
     */
    public function updateTurnState(string $conversationId, string $turnId, ?array $state): ConversationTurn
    {
        $this->mustHold($conversationId);

        foreach ($this->turns[$conversationId] as $i => $turn) {
            if ($turn['id'] === $turnId) {
                $this->turns[$conversationId][$i]['state'] = $state;
                $this->writes[] = ['update_turn_state' => $turnId, 'state' => $state];

                return ConversationTurn::fromArray($this->turns[$conversationId][$i]);
            }
        }

        throw new RuntimeException("no_such_turn: conversation {$conversationId} holds no turn with id {$turnId}.");
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function erase(string $conversationId): array
    {
        if (! isset($this->threads[$conversationId])) {
            return ['erased' => false, 'conversations' => 0, 'turns' => 0, 'memories' => 0];
        }

        $turns = count($this->turns[$conversationId] ?? []);

        unset($this->threads[$conversationId], $this->turns[$conversationId]);

        // The service cascades; a fake that kept the votes would hide an erasure bug.
        $this->votes = array_filter($this->votes, fn ($v) => $v['conversation_id'] !== $conversationId);

        $this->writes[] = ['erase' => $conversationId];

        return ['erased' => true, 'conversations' => 1, 'turns' => $turns, 'memories' => 0];
    }

    /** @return array{erased: bool, conversations: int, turns: int, memories: int} */
    public function eraseForOwner(string $ownerRef): array
    {
        $receipt = $this->eraseWhere(fn (array $row) => $row['owner_ref'] === $ownerRef);
        $receipt['memories'] = $this->memory?->clear($ownerRef) ?? 0;

        return $receipt;
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function eraseForSubject(string $subjectRef): array
    {
        return $this->eraseWhere(fn (array $row) => ($row['subject_ref'] ?? null) === $subjectRef);
    }

    /** @return array<string, mixed> */
    /**
     * ⚠ REFUSES WHAT THE SERVICE REFUSES. A fake that accepted a vote on a person's own
     * question, or a reason on a thumbs-up, would turn a product's test suite into evidence for
     * behaviour production rejects with a 404 or a 422.
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
        $turn = null;

        foreach ($this->turns[$conversationId] ?? [] as $row) {
            if ($row['id'] === $turnId) {
                $turn = $row;
            }
        }

        if ($turn === null || $turn['role'] !== 'assistant') {
            throw new \RuntimeException('ai-service answered 404 no_such_turn: This product holds no assistant answer with that id in that conversation.');
        }

        if (! in_array($sentiment, ['positive', 'negative'], true)) {
            throw new \RuntimeException('ai-service answered 422: sentiment must be positive or negative.');
        }

        if ($reasonCode !== null && $reasonCode !== '') {
            if ($sentiment === 'positive') {
                throw new \RuntimeException('ai-service answered 422: a reason is only valid on a thumbs-down.');
            }

            if (! in_array($reasonCode, self::FEEDBACK_REASONS, true)) {
                throw new \RuntimeException("ai-service answered 422: unknown reason code {$reasonCode}.");
            }
        }

        $key = $turnId . '|' . $voterRef;
        $existing = $this->votes[$key] ?? null;

        $vote = [
            'id'              => $existing['id'] ?? $this->nextId('vote'),
            'conversation_id' => $conversationId,
            'turn_id'         => $turnId,
            'voter_ref'       => $voterRef,
            'sentiment'       => $sentiment,
            'reason_code'     => ($reasonCode ?? '') === '' ? null : $reasonCode,
            'reason_text'     => trim((string) $reasonText) === '' ? null : $reasonText,
            'client_ref'      => $clientRef ?? ($existing['client_ref'] ?? null),
            'created_at'      => $existing['created_at']
                ?? ($occurredAt?->format(\DateTimeInterface::ATOM) ?? $this->now()),
            'updated_at'      => $this->now(),
        ];

        $this->votes[$key] = $vote;
        $this->writes[] = ['rate' => $turnId, 'voter' => $voterRef, 'sentiment' => $sentiment];

        return $vote;
    }

    public function feedbackStats(?\DateTimeInterface $since = null): array
    {
        $groups = [];

        foreach ($this->votes as $vote) {
            $audience = $this->threads[$vote['conversation_id']]['audience'] ?? null;
            $key = ($audience ?? '') . '|' . $vote['sentiment'] . '|' . ($vote['reason_code'] ?? '');
            $groups[$key] ??= [
                'audience'    => $audience,
                'sentiment'   => $vote['sentiment'],
                'reason_code' => $vote['reason_code'],
                'votes'       => 0,
            ];
            $groups[$key]['votes']++;
        }

        return array_values($groups);
    }

    /**
     * ⚠ THE SAME FILTERS AND SHAPE AS THE SERVICE, so a product's triage-screen test cannot pass on
     * a filter production ignores.
     */
    public function feedbackPage(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $rows = [];

        foreach (array_reverse($this->votes) as $vote) {
            $thread = $this->threads[$vote['conversation_id']] ?? [];
            $turn = null;
            foreach ($this->turns[$vote['conversation_id']] ?? [] as $row) {
                if ($row['id'] === $vote['turn_id']) {
                    $turn = $row;
                }
            }

            $row = $vote + [
                'answer'     => $turn['body'] ?? null,
                'structured' => $turn['structured'] ?? null,
                'audience'   => $thread['audience'] ?? null,
                'owner_ref'  => $thread['owner_ref'] ?? null,
            ];

            foreach (['sentiment', 'audience', 'reason_code'] as $f) {
                if (($filters[$f] ?? '') !== '' && $row[$f] !== $filters[$f]) {
                    continue 2;
                }
            }
            if (($filters['since'] ?? '') !== '' && substr((string) $row['created_at'], 0, 10) < substr((string) $filters['since'], 0, 10)) {
                continue;
            }
            if (($filters['until'] ?? '') !== '' && substr((string) $row['created_at'], 0, 10) > substr((string) $filters['until'], 0, 10)) {
                continue;
            }
            if (($filters['search'] ?? '') !== '' && ! str_contains(mb_strtolower((string) $row['answer']), mb_strtolower((string) $filters['search']))) {
                continue;
            }

            $rows[] = $row;
        }

        $meta = [
            'page' => $page, 'per_page' => $perPage, 'total' => count($rows),
            'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
        ];
        if (($filters['search'] ?? '') !== '') {
            $meta['searched'] = 2000;
        }

        return ['feedback' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'meta' => $meta];
    }

    public function feedback(?string $sentiment = null, ?\DateTimeInterface $since = null, int $limit = 50): array
    {
        $rows = [];

        foreach ($this->votes as $vote) {
            if ($sentiment !== null && $vote['sentiment'] !== $sentiment) {
                continue;
            }

            $answer = null;
            foreach ($this->turns[$vote['conversation_id']] ?? [] as $row) {
                if ($row['id'] === $vote['turn_id']) {
                    $answer = $row['body'] ?? null;
                }
            }

            $rows[] = $vote + ['answer' => $answer];
        }

        return array_slice(array_reverse($rows), 0, $limit);
    }

    public function stats(int $days = 30): array
    {
        $turns = 0;
        $refused = 0;
        $assistant = 0;
        $refusedByAudience = [];
        $byAudience = [];
        $grounding = [];
        $replies = [];
        $repliedThreads = [];
        $byProvenance = [];
        $noTurns = 0;

        foreach ($this->threads as $id => $row) {
            $audience = ($row['audience'] ?? null) === null || $row['audience'] === '' ? 'unset' : $row['audience'];
            $byAudience[$audience] = ($byAudience[$audience] ?? 0) + 1;

            if (($row['turn_count'] ?? 0) === 0) {
                $noTurns++;
            }

            foreach ($this->turns[$id] ?? [] as $turn) {
                $turns++;

                if ($turn['role'] === ConversationTurn::ROLE_ASSISTANT) {
                    $assistant++;

                    /*
                     * ⚠ EVERY REPLY, WHICH IS A DIFFERENT DENOMINATOR FROM THE GROUNDING ONE
                     * BELOW. Portify's weekly report divides `unknown` by every assistant turn;
                     * the grounding block divides by replies that STATED a provenance. Giving
                     * self-reflect the wrong one would have moved every figure in the digest
                     * without a single test failing.
                     */
                    $replies[$audience] = ($replies[$audience] ?? 0) + 1;
                    $repliedThreads[$audience][$id] = true;

                    /*
                     * ⚠ A NULL CLASS IS ITS OWN KEY, exactly as the service counts it. Those are
                     * turns written before provenance existed, and folding them into `unknown`
                     * would put a spike in the one figure `porter:reflect` warns on.
                     */
                    $klass = ($turn['provenance_class'] ?? null) === null
                        ? 'pre-provenance'
                        : (string) $turn['provenance_class'];
                    $byProvenance[$klass] = ($byProvenance[$klass] ?? 0) + 1;
                }

                if ($turn['refused']) {
                    $refused++;
                    $refusedByAudience[$audience] = ($refusedByAudience[$audience] ?? 0) + 1;
                }

                /*
                 * ⚠ ONLY REPLIES THAT STATED A PROVENANCE ARE IN THE DENOMINATOR, exactly as
                 * the service counts it. A reply that said nothing is not an ungrounded reply.
                 */
                if ($turn['role'] === ConversationTurn::ROLE_ASSISTANT && ($turn['grounded'] ?? null) !== null) {
                    $grounding[$audience] ??= ['replies_stating_provenance' => 0, 'grounded' => 0, 'unknown' => 0];
                    $grounding[$audience]['replies_stating_provenance']++;

                    if ($turn['grounded']) {
                        $grounding[$audience]['grounded']++;
                    }

                    if (($turn['provenance_class'] ?? null) === 'unknown') {
                        $grounding[$audience]['unknown']++;
                    }
                }
            }
        }

        return [
            'product' => $this->product,
            'window'  => ['days' => $days],
            'threads' => [
                'started'       => count($this->threads),
                'with_no_turns' => $noTurns,
                'by_audience'   => $byAudience,
            ],
            'turns' => [
                'total'               => $turns,
                'refused'             => $refused,
                'refusal_rate'        => $assistant > 0 ? round($refused / $assistant, 4) : null,
                'refused_by_audience' => $refusedByAudience,
                'by_audience' => array_combine(array_keys($replies), array_map(
                    fn (int $count, string $audience) => [
                        'replies'       => $count,
                        'conversations' => count($repliedThreads[$audience] ?? []),
                    ],
                    $replies,
                    array_keys($replies),
                )),
                'by_provenance_class' => $byProvenance,
                'grounding_by_audience' => array_map(fn (array $row) => $row + [
                    'grounded_rate' => $row['replies_stating_provenance'] > 0
                        ? round($row['grounded'] / $row['replies_stating_provenance'], 4)
                        : null,
                    'unknown_rate' => $row['replies_stating_provenance'] > 0
                        ? round($row['unknown'] / $row['replies_stating_provenance'], 4)
                        : null,
                ], $grounding),
            ],
            'basis' => 'Counted from the clear-text columns only. No transcript was read to build this.',
        ];
    }

    /**
     * Pretend retention erased a thread, so a product can reach its "no longer held" branch.
     *
     * ⚠ THIS IS THE BRANCH NOBODY WRITES BY ACCIDENT, and it is the one that shows a person a
     * conversation with no messages in it. It is the same effect as `erase()`; it is named
     * differently because a test that says `pretendRetentionErased()` is a test whose intent a
     * reader can see.
     */
    public function pretendRetentionErased(string $conversationId): static
    {
        $this->erase($conversationId);

        return $this;
    }

    /** How many turns the fake is holding for a thread, for assertions. */
    public function turnCount(string $conversationId): int
    {
        return count($this->turns[$conversationId] ?? []);
    }

    /**
     * Remove one turn, so a test can prove `turnCount` is COUNTED and not incremented.
     *
     * The real service's prune and erasure both remove turns; a product that trusted a
     * monotonic counter would repeat a number describing a transcript that no longer exists.
     */
    public function forgetTurn(string $conversationId, string $turnId): static
    {
        $this->turns[$conversationId] = array_values(array_filter(
            $this->turns[$conversationId] ?? [],
            fn (array $turn) => $turn['id'] !== $turnId,
        ));

        $this->resummarise($conversationId);

        return $this;
    }

    /** @param callable(array<string, mixed>): bool $matches */
    private function eraseWhere(callable $matches): array
    {
        $threads = 0;
        $turns = 0;

        foreach ($this->threads as $id => $row) {
            if (! $matches($row)) {
                continue;
            }

            $turns += count($this->turns[$id] ?? []);
            $threads++;

            unset($this->threads[$id], $this->turns[$id]);
            $this->votes = array_filter($this->votes, fn ($v) => $v['conversation_id'] !== $id);
        }

        return ['erased' => true, 'conversations' => $threads, 'turns' => $turns, 'memories' => 0];
    }

    private function resummarise(string $conversationId): void
    {
        $turns = $this->turns[$conversationId] ?? [];

        // ⚠ COUNTED, NOT INCREMENTED - the rule the real store is built on.
        $this->threads[$conversationId]['turn_count'] = count($turns);

        /*
         * ⚠ THE MAXIMUM, NOT THE LAST WRITE. In the service that distinction was a way to erase
         * a live thread: mirroring one old message moved the last activity backwards, and the
         * retention stamp is derived from it.
         */
        $this->threads[$conversationId]['last_turn_at'] = $turns === []
            ? null
            : max(array_column($turns, 'created_at'));
    }

    private function mustHold(string $conversationId): void
    {
        if (! isset($this->threads[$conversationId])) {
            throw new RuntimeException(
                "no_such_conversation: this product holds no conversation with id {$conversationId}."
            );
        }
    }

    private function nextId(string $kind): string
    {
        return sprintf('%s-%04d', $kind, ++$this->sequence);
    }

    /**
     * ⚠ WITH MICROSECONDS, so two things created in the same second still order. The real
     * service's `created_at` has NO fractional seconds, which is exactly why its cursor is
     * (created_at, id) - but a fake that reordered rows at random would make a product's
     * ordering tests flap, and a flapping test teaches nothing.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }
}
