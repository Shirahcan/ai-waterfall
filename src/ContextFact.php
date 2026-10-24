<?php

namespace Shirahcan\AiClient;

/**
 * One thing an assistant may cite about the person it is talking to (P19g).
 *
 * ⚠ THE SHAPE IS SHARED; THE DATA AND THE PERMISSIONS ARE NOT. What a consultant may read about a
 * case is Portify's data model and Portify's access rules, and neither belongs in a shared service.
 * What belongs here is the SHAPE a fact arrives in, so "grounded" and the ungrounded caveat mean the
 * same thing in every product - before this, each product invented its own, and MployNow's and
 * Portify's disagreed about what counted as grounding at all.
 *
 * ⚠ `label` IS THE SCREEN'S OWN WORDS, NOT A FIELD NAME. Measured twice on Reeve: given the figure
 * alone, a model answers the person's question with the nearest paraphrase of what the screen
 * calls it. The fact must carry the label the page shows, or the answer and the page disagree.
 */
class ContextFact
{
    public function __construct(
        /** Stable and machine-readable: `roles.open_count`. What `matched_keys` records. */
        public readonly string $key,
        /** The words the screen uses for this value. */
        public readonly string $label,
        public readonly string $value,
        /** Where a person can see it themselves, when there is such a place. */
        public readonly ?string $source = null,
        /** When the value was true, for anything that changes. */
        public readonly ?\DateTimeInterface $asOf = null,
    ) {}

    /** @return array{key: string, label: string, value: string, source: ?string, as_of: ?string} */
    public function toArray(): array
    {
        return [
            'key'    => $this->key,
            'label'  => $this->label,
            'value'  => $this->value,
            'source' => $this->source,
            'as_of'  => $this->asOf?->format(\DateTimeInterface::ATOM),
        ];
    }
}
