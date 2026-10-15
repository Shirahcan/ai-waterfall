<?php

namespace Shirahcan\AiClient;

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
 * ⚠ AND IT CARRIES TWO DIFFERENT KINDS OF TRUTH, WHICH MUST NOT BE MERGED.
 * `state` (and therefore {@see isHealthy}) is a RIGHT-NOW reading from the
 * service's rotation cache. `health` is a 24-HOUR verdict from the usage
 * ledger. A credential can be `available` and `exhausted` at the same instant,
 * and that is not a contradiction - it is the F13 finding. Production ran for
 * 30 days with two of three tiers quota-dead, each reading `available` between
 * cooldowns, so every dashboard in the estate showed green ticks while one
 * provider carried everything. {@see hasRedundancy} is the question none of
 * the right-now fields can answer.
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
        /**
         * ⚠ NULLABLE, AND NULL MEANS "THIS SERVICE DOES NOT REPORT IT YET",
         * never "none". A zero would read as "nothing is contributing" - the
         * alarm state - on every box that has not deployed the detector, and a
         * false alarm trains people to ignore the real one. The console renders
         * "not reported" instead.
         */
        public readonly ?int $contributing,
        public readonly ?int $exhausted,
        public readonly ?int $broken,
        public readonly ?int $unjudged,
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

            /*
             * Counted from the rows when an older summary omits them, and left
             * NULL only when the rows carry no `health` at all - which is how an
             * un-deployed service is told apart from a healthy one.
             */
            contributing: self::countHealth($models, $summary, 'contributing', ['healthy', 'degraded']),
            exhausted: self::countHealth($models, $summary, 'exhausted', ['exhausted']),
            broken: self::countHealth($models, $summary, 'broken', ['broken']),
            unjudged: self::countHealth($models, $summary, 'unjudged', ['unjudged']),

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

    /** True once the service reports a ledger verdict for its credentials. */
    public function reportsTierHealth(): bool
    {
        return $this->contributing !== null;
    }

    /**
     * Is there more than one rung actually answering?
     *
     * ⚠ THIS IS THE QUESTION {@see isHealthy} CANNOT ANSWER, and the two are
     * both correct. `isHealthy` asks whether anything is in rotation, which is
     * the right alarm for an outage. This asks whether anything is left to fall
     * through TO, which is the right alarm for the week before one. For 30 days
     * production answered yes to the first and no to the second, and only the
     * first was being asked.
     *
     * Null when the service does not report tier health - unknown, not fine.
     */
    public function hasRedundancy(): ?bool
    {
        return $this->contributing === null ? null : $this->contributing > 1;
    }

    /**
     * Rungs the ledger says have stopped contributing a tier.
     *
     * Distinct from {@see unavailable}: those are out of rotation this minute,
     * these have not usefully answered all day - and the overlap between the two
     * sets is frequently empty, which is the whole problem.
     *
     * @return list<array<string,mixed>>
     */
    public function notContributing(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (array $m) => in_array(
                $m['health']['verdict'] ?? null,
                ['exhausted', 'broken'],
                true,
            ),
        ));
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

        $line = $notes === [] ? $line : $line.' ('.implode(', ', $notes).')';

        /*
         * ⚠ THE REDUNDANCY SENTENCE GOES IN THE ONE-LINER, because the
         * one-liner is what a dashboard tile shows and what a CLI prints, and
         * "3 of 3 models ready" was literally true throughout the month two of
         * those three answered nothing.
         */
        if ($this->hasRedundancy() === false) {
            return $this->contributing === 0
                ? $line.'. No rung has answered in the last day.'
                : $line.'. Only one rung is still answering: there is no redundancy left.';
        }

        return $line;
    }

    /**
     * @param list<array<string,mixed>> $models
     * @param array<string,mixed> $summary
     * @param list<string> $verdicts
     */
    private static function countHealth(array $models, array $summary, string $key, array $verdicts): ?int
    {
        if (isset($summary[$key])) {
            return (int) $summary[$key];
        }

        $withHealth = array_filter($models, static fn (array $m) => isset($m['health']['verdict']));

        // No row carries a verdict, so this service does not report one.
        if ($withHealth === []) {
            return null;
        }

        return count(array_filter(
            $withHealth,
            static fn (array $m) => in_array($m['health']['verdict'], $verdicts, true),
        ));
    }

    /** @param list<array<string,mixed>> $models */
    private static function countState(array $models, string $state): int
    {
        return count(array_filter($models, static fn (array $m) => ($m['state'] ?? null) === $state));
    }
}
