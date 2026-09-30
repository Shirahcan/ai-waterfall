<?php

namespace Shirahcan\AiWaterfall;

/**
 * One vector, and the model that produced it.
 *
 * ⚠ THE MODEL IS NOT DECORATION. Vectors from two embedding models are not
 * comparable, and nothing about the numbers says so: a query from one scored
 * against an index built by another returns confident, meaningless rankings.
 * Store `model` beside every vector you keep, and refuse to compare across it.
 */
class AiEmbedding
{
    /**
     * @param  array<int, float>  $vector
     */
    public function __construct(
        public readonly array $vector,
        public readonly string $model,
        public readonly ?string $provider = null,
        public readonly int $latencyMs = 0,
        public readonly array $attempts = [],
    ) {}

    public static function fromArray(array $r): self
    {
        return new self(
            vector: array_map('floatval', (array) ($r['vector'] ?? [])),
            model: (string) ($r['model'] ?? ''),
            provider: $r['provider'] ?? null,
            latencyMs: (int) ($r['latency_ms'] ?? 0),
            attempts: (array) ($r['attempts'] ?? []),
        );
    }

    public function dimensions(): int
    {
        return count($this->vector);
    }
}
