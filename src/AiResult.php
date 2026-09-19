<?php

namespace Shirahcan\AiWaterfall;

/**
 * One answer, plus the trail of what it took to get it.
 *
 * ⚠ THE ATTEMPT TRAIL TRAVELS WITH THE ANSWER, not only with failures. A caller
 * that wants to say "answered by groq after gemini was rate limited" can; one
 * that does not care ignores it. Without it, a degraded-but-working waterfall
 * looks identical to a healthy one, which is how the estate ran for months with
 * four of five providers dead and nothing reporting it.
 */
class AiResult
{
    public function __construct(
        public readonly mixed $data,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly int $latencyMs = 0,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null,
        public readonly array $attempts = [],
    ) {}

    public static function fromArray(array $r): self
    {
        return new self(
            data: $r['result'] ?? null,
            provider: $r['provider'] ?? null,
            model: $r['model'] ?? null,
            latencyMs: (int) ($r['latency_ms'] ?? 0),
            tokensIn: $r['tokens']['in'] ?? null,
            tokensOut: $r['tokens']['out'] ?? null,
            attempts: $r['attempts'] ?? [],
        );
    }

    /** The decoded payload as an array, for the json/raw modes. */
    public function array(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    /** The payload as a string, for the text mode. */
    public function text(): string
    {
        return is_string($this->data) ? $this->data : '';
    }

    /** Did the waterfall fall past anything to answer this? */
    public function fellThrough(): bool
    {
        return count($this->attempts) > 1;
    }
}
