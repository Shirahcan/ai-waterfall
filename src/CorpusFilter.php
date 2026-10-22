<?php

namespace Shirahcan\AiClient;

use DateTimeInterface;

/**
 * Which of a product's turns a corpus read is about.
 *
 * ⚠ IT IS AN OBJECT RATHER THAN TWELVE ARGUMENTS BECAUSE IT IS USED TWICE. `turns()` takes one
 * page and `eachTurn()` pages through all of them, and a twelve-parameter signature written out
 * in both places is twelve chances for the two to drift - the duplicate-derivation shape, in a
 * method signature. One filter, two readers.
 *
 *     $ungrounded = new CorpusFilter(
 *         audience: 'consultant',
 *         role: ConversationTurn::ROLE_ASSISTANT,
 *         grounded: false,
 *         since: now()->subWeek(),
 *     );
 *
 * ⚠ A NULL IS "DO NOT FILTER" AND A FALSE IS A VALUE. `grounded: false` is the backlog itself -
 * the replies that were not grounded - so it must reach the wire. `ai-client` shipped the other
 * behaviour for one commit, where an `array_filter` dropping falses made the rate look HEALTHIER
 * the worse the assistant got.
 */
final class CorpusFilter
{
    /**
     * @param list<string> $conversationIds
     */
    public function __construct(
        public readonly ?string $ownerRef = null,
        public readonly ?string $subjectRef = null,
        public readonly ?string $audience = null,
        public readonly array $conversationIds = [],
        public readonly ?string $role = null,
        public readonly ?bool $refused = null,
        public readonly ?bool $grounded = null,
        public readonly ?string $provenanceClass = null,
        public readonly ?DateTimeInterface $since = null,
        public readonly ?DateTimeInterface $until = null,
    ) {}

    /** @return array<string, mixed> */
    public function toQuery(): array
    {
        $query = [];

        foreach ([
            'owner_ref'        => $this->ownerRef,
            'subject_ref'      => $this->subjectRef,
            'audience'         => $this->audience,
            'role'             => $this->role,
            'provenance_class' => $this->provenanceClass,
        ] as $field => $value) {
            if ($value !== null && $value !== '') {
                $query[$field] = $value;
            }
        }

        if ($this->conversationIds !== []) {
            $query['conversation_ids'] = array_values($this->conversationIds);
        }

        // ⚠ `!== null`, so a deliberate false is sent as 0 rather than dropped.
        foreach (['refused' => $this->refused, 'grounded' => $this->grounded] as $field => $value) {
            if ($value !== null) {
                $query[$field] = $value ? 1 : 0;
            }
        }

        foreach (['since' => $this->since, 'until' => $this->until] as $field => $value) {
            if ($value !== null) {
                $query[$field] = $value->format(DATE_ATOM);
            }
        }

        return $query;
    }
}
