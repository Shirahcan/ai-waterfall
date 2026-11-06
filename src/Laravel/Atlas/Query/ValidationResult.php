<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryPlan;

/**
 * Outcome of {@see PlanValidator::validate()}.
 *  - `$plan` present  → safe to execute (possibly with dropped projections; see `$notes`).
 *  - `$clarification` → no executable target survived (e.g. a population-changing
 *                       filter was disallowed); put the question back to the user.
 *  - `$outOfScope`    → the plan referenced ONLY non-existent record types (the AI
 *                       mapped an external / world-knowledge ask onto entities the
 *                       Atlas does not hold). The caller should defer to research /
 *                       general knowledge rather than dead-end with a denial. This is
 *                       NOT a permission denial (those keep `$outOfScope = false`).
 */
final class ValidationResult
{
    /** @param list<string> $notes */
    public function __construct(
        public readonly ?QueryPlan $plan,
        public readonly array $notes = [],
        public readonly ?string $clarification = null,
        public readonly bool $outOfScope = false,
    ) {}

    public static function ok(QueryPlan $plan, array $notes = []): self
    {
        return new self($plan, $notes);
    }

    public static function clarify(string $message, bool $outOfScope = false): self
    {
        return new self(null, [], $message, $outOfScope);
    }
}
