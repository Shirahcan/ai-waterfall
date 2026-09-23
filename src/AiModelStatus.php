<?php

namespace Shirahcan\AiWaterfall;

/**
 * Which AI models are usable right now, and why the others are not.
 *
 * ⚠ A FAITHFUL CARRIER, LIKE {@see AiUsageReport}. Every field is read from the
 * service's reply; nothing here re-derives a state. Three separate mechanisms
 * can take a rung out of rotation - a rate-limit bench, an open circuit after
 * repeated failures, and a learned prompt-size ceiling - and only the service
 * knows all three. Four products each working out "is this usable?" would drift
 * into four different answers about one credential, which is the
 * duplicate-derivation defect this estate keeps paying for.
 *
 * ⚠ A SKIPPED RUNG MUST BE VISIBLE. Before this, a model that the waterfall was
 * silently passing over read as perfectly healthy on every dashboard, and the
 * only symptom anybody could see was latency. That is how one rung sat at 0
 * successes in 81 calls without anyone noticing.
 */
final class AiModelStatus
{
    /** @param list<array<string,mixed>> $models */
    private function __construct(
        public readonly array $models,
        public readonly int $total,
        public readonly int $usable,
        public readonly int $benched,
        public readonly int $circuitOpen,
        public readonly int $sizeLimited,
        public readonly int $disabled,
        public readonly array $raw,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $models = array_values((array) ($payload['credentials'] ?? []));
        $summary = (array) ($payload['summary'] ?? []);

        return new self(
            models: $models,
            // Fall back to counting only when an older service sent no summary,
            // so this keeps working against a box that has not deployed yet.
            total: (int) ($summary['total'] ?? count($models)),
            usable: (int) ($summary['usable'] ?? self::countState($models, 'available')),
            benched: (int) ($summary['benched'] ?? self::countState($models, 'benched')),
            circuitOpen: (int) ($summary['circuit_open'] ?? self::countState($models, 'circuit_open')),
            sizeLimited: (int) ($summary['size_limited'] ?? self::countState($models, 'size_limited')),
            disabled: (int) ($summary['disabled'] ?? self::countState($models, 'disabled')),
            raw: $payload,
        );
    }

    /**
     * ⚠ "NO USABLE RUNG" IS THE ALARM, NOT "SOME ARE BENCHED". Benching is the
     * system working: a quota-exhausted key SHOULD be out of rotation. The state
     * worth waking someone for is having nothing left to fall through to.
     */
    public function isHealthy(): bool
    {
        return $this->usable > 0;
    }

    /**
     * Rungs that are out of rotation entirely.
     *
     * `size_limited` is deliberately NOT here: that model still answers shorter
     * prompts, so listing it as unavailable would overstate the problem.
     *
     * @return list<array<string,mixed>>
     */
    public function unavailable(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (array $m) => in_array($m['state'] ?? null, ['benched', 'circuit_open', 'disabled'], true),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function available(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (array $m) => in_array($m['state'] ?? null, ['available', 'size_limited'], true),
        ));
    }

    /** One line for a dashboard tile or a CLI. */
    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No AI models are configured.';
        }

        $line = "{$this->usable} of {$this->total} models ready";
        $notes = [];

        foreach ([
            'rate limited' => $this->benched,
            'failing' => $this->circuitOpen,
            'off' => $this->disabled,
        ] as $label => $count) {
            if ($count > 0) {
                $notes[] = "{$count} {$label}";
            }
        }

        return $notes === [] ? $line : $line.' ('.implode(', ', $notes).')';
    }

    /** @param list<array<string,mixed>> $models */
    private static function countState(array $models, string $state): int
    {
        return count(array_filter($models, static fn (array $m) => ($m['state'] ?? null) === $state));
    }
}
