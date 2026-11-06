<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

/**
 * One figure in a pulse: "Users joined: 7 (+3 on the 4 days before)".
 *
 * `asOfNow` marks a figure that is a current state ("open tickets right now") rather than a count over
 * the window, so the answer never pretends it belongs to the period.
 */
final class PulseLine
{
    /**
     * @param  list<array{label: string, value: int|float}>  $top  a short leaderboard ("most cases: Maria Garcia 4")
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $label,
        public readonly int|float|null $value,
        public readonly string $format = 'number',
        public readonly ?string $currency = null,
        public readonly int|float|null $previous = null,
        public readonly ?string $change = null,
        public readonly ?string $link = null,
        public readonly ?string $unavailable = null,
        public readonly bool $asOfNow = false,
        public readonly array $top = [],
        public readonly ?string $note = null,
    ) {}

    public static function unavailable(string $domain, string $label, string $why = 'could not be read just now'): self
    {
        return new self($domain, $label, null, unavailable: $why);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'value' => $this->unavailable === null ? $this->value : null,
            'format' => $this->format !== 'number' ? $this->format : null,
            'currency' => $this->currency,
            'previous' => $this->previous,
            'change' => $this->change,
            'as_of_now' => $this->asOfNow ? true : null,
            'top' => $this->top !== [] ? $this->top : null,
            'note' => $this->note,
            'link' => $this->link,
            'could_not_read' => $this->unavailable,
        ], static fn ($v) => $v !== null);
    }
}
