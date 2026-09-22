<?php

namespace Shirahcan\AiWaterfall;

/**
 * What the estate spent, what it avoided spending, and who did the spending.
 *
 * ⚠ THE SERVICE COMPUTES, THIS CARRIES. Every figure here is read from the
 * response, never recalculated. That restraint is the entire point: three
 * products each deriving "spend" from raw rows is exactly how one estate ended
 * up with ledgers that disagreed, and how Portify's own ledger came to report
 * $114.71 of cost from free providers that never charged a penny. Micros in,
 * micros out. The only arithmetic below is dividing by 1,000,000 to show
 * dollars, which is a unit conversion, not a second opinion.
 *
 * ⚠ SAVINGS IS A COUNTERFACTUAL AND MUST NEVER BE SHOWN BARE. Free-tier work
 * has no price, so "saved" only means anything against a stated comparison. The
 * service measures it against the paid rung that would otherwise have served
 * each call, captured per attempt at call time - not against whatever expensive
 * model would flatter the number. {@see $savingsBasis} travels with the figure
 * and {@see $savingsIsEstimate} is always true, so a surface cannot present it
 * as banked money without going out of its way.
 *
 * One shape answers both questions: no product filter gives the estate view,
 * `product: 'portify'` narrows it. Two shapes would be two places for the same
 * figure to drift.
 */
class AiUsageReport
{
    /**
     * @param  list<array<string,mixed>>  $byProduct
     * @param  list<array<string,mixed>>  $byTier
     * @param  list<array<string,mixed>>  $byProvider
     * @param  array<string,int>  $byOutcome
     * @param  array<string,mixed>  $raw  the untouched response, so a caller
     *         needing something this DTO does not surface is never blocked by it
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $calls,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $spendMicros,
        public readonly int $savingsMicros,
        public readonly bool $savingsIsEstimate,
        public readonly string $savingsBasis,
        public readonly array $byProduct = [],
        public readonly array $byTier = [],
        public readonly array $byProvider = [],
        public readonly array $byOutcome = [],
        public readonly array $raw = [],
    ) {}

    /** @param  array<string,mixed>  $r */
    public static function fromArray(array $r): self
    {
        $totals = (array) ($r['totals'] ?? []);

        return new self(
            from: (string) ($r['from'] ?? ''),
            to: (string) ($r['to'] ?? ''),
            calls: (int) ($totals['calls'] ?? 0),
            tokensIn: (int) ($totals['tokens_in'] ?? 0),
            tokensOut: (int) ($totals['tokens_out'] ?? 0),
            spendMicros: (int) ($totals['spend_micros'] ?? 0),
            savingsMicros: (int) ($totals['savings_micros'] ?? 0),
            // ⚠ Defaults to TRUE when absent. An older service that does not send
            // the flag must not have its savings read as an exact figure.
            savingsIsEstimate: (bool) ($totals['savings_is_estimate'] ?? true),
            savingsBasis: (string) ($r['savings_basis'] ?? 'Basis not reported by the service.'),
            byProduct: (array) ($r['by_product'] ?? []),
            byTier: (array) ($r['by_tier'] ?? []),
            byProvider: (array) ($r['by_provider'] ?? []),
            byOutcome: (array) ($r['by_outcome'] ?? []),
            raw: $r,
        );
    }

    /** Spend in dollars. A unit conversion of {@see $spendMicros}, nothing more. */
    public function spend(): float
    {
        return round($this->spendMicros / 1_000_000, 6);
    }

    /** Estimated saving in dollars. Never present without {@see savingsBasis}. */
    public function savings(): float
    {
        return round($this->savingsMicros / 1_000_000, 6);
    }

    public function tokensTotal(): int
    {
        return $this->tokensIn + $this->tokensOut;
    }

    /**
     * One product's slice of an estate report, or null when it did not appear.
     *
     * ⚠ NULL AND ZERO ARE DIFFERENT ANSWERS. Null means this product made no
     * calls in the window; a row of zeros would claim it ran and spent nothing.
     * A product whose AI silently stopped working looks exactly like a quiet one
     * if you flatten that distinction, and the estate has already had a product
     * sit in outage unnoticed.
     *
     * @return array<string,mixed>|null
     */
    public function forProduct(string $product): ?array
    {
        foreach ($this->byProduct as $row) {
            if (($row['product'] ?? null) === $product) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Calls served by each tier, e.g. ['free' => 412, 'paid' => 7].
     *
     * @return array<string,int>
     */
    public function callsByTier(): array
    {
        $out = [];

        foreach ($this->byTier as $row) {
            $tier = (string) ($row['tier'] ?? 'unknown');
            $out[$tier] = (int) ($row['calls'] ?? 0);
        }

        return $out;
    }

    /**
     * How many calls did NOT succeed.
     *
     * ⚠ FAILURES BELONG BESIDE SPEND, NOT IN A SEPARATE REPORT. A month of near
     * zero cost is excellent news if the work succeeded on free rungs and an
     * emergency if it was failing; the spend figure alone cannot tell those
     * apart, and one of them looks like a saving.
     */
    public function failedCalls(): int
    {
        $failed = 0;

        foreach ($this->byOutcome as $outcome => $calls) {
            if ($outcome !== 'ok') {
                $failed += (int) $calls;
            }
        }

        return $failed;
    }

    /** A one-line summary for a log, a digest or a CLI footer. */
    public function summary(): string
    {
        return sprintf(
            '%s calls, %s tokens, $%s spent, ~$%s saved (estimated), %s not ok',
            number_format($this->calls),
            number_format($this->tokensTotal()),
            number_format($this->spend(), 4),
            number_format($this->savings(), 4),
            number_format($this->failedCalls()),
        );
    }
}
