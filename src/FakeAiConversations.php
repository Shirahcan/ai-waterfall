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

    /**
     * How many BATCH READS have happened.
     *
     * ⚠ THE NUMBER A PRODUCT'S TEST SHOULD ASSERT ON. The held pattern's whole claim is that a
     * collection of rows costs ONE call; an N+1 over a network is invisible in a passing test
     * and obvious only in production. Assert this is 1 after loading a list.
     */
    public int $batchReads = 0;

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
            'created_at'      => $this->now(),
        ];

        $this->turns[$conversationId][] = $turn;
        $this->resummarise($conversationId);

        $this->writes[] = ['append' => $conversationId, 'role' => $role, 'client_ref' => $clientRef];

        return ConversationTurn::fromArray($turn);
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

        foreach (array_intersect_key($changes, array_flip(['title', 'audience', 'subject_ref'])) as $field => $value) {
            $this->threads[$conversationId][$field] = $value;
        }

        $this->writes[] = ['update' => $conversationId, 'changes' => $changes];

        return Conversation::fromArray($this->threads[$conversationId]);
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function erase(string $conversationId): array
    {
        if (! isset($this->threads[$conversationId])) {
            return ['erased' => false, 'conversations' => 0, 'turns' => 0];
        }

        $turns = count($this->turns[$conversationId] ?? []);

        unset($this->threads[$conversationId], $this->turns[$conversationId]);

        $this->writes[] = ['erase' => $conversationId];

        return ['erased' => true, 'conversations' => 1, 'turns' => $turns];
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function eraseForOwner(string $ownerRef): array
    {
        return $this->eraseWhere(fn (array $row) => $row['owner_ref'] === $ownerRef);
    }

    /** @return array{erased: bool, conversations: int, turns: int} */
    public function eraseForSubject(string $subjectRef): array
    {
        return $this->eraseWhere(fn (array $row) => ($row['subject_ref'] ?? null) === $subjectRef);
    }

    /** @return array<string, mixed> */
    public function stats(int $days = 30): array
    {
        $turns = 0;
        $refused = 0;
        $assistant = 0;
        $refusedByAudience = [];
        $byAudience = [];
        $grounding = [];
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
        }

        return ['erased' => true, 'conversations' => $threads, 'turns' => $turns];
    }

    private function resummarise(string $conversationId): void
    {
        $turns = $this->turns[$conversationId] ?? [];

        // ⚠ COUNTED, NOT INCREMENTED - the rule the real store is built on.
        $this->threads[$conversationId]['turn_count'] = count($turns);
        $this->threads[$conversationId]['last_turn_at'] = $turns === []
            ? null
            : ($turns[count($turns) - 1]['created_at'] ?? null);
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
        return (new DateTimeImmutable)->format('Y-m-d\TH:i:s.uP');
    }
}
