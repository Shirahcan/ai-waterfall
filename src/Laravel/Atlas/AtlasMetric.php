<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * An aggregation an {@see AtlasEntity} can compute per result row.
 *
 * `$resolver` is a callable `(\Illuminate\Database\Eloquent\Builder $query,
 * \Shirahcan\AiClient\Laravel\Atlas\Query\MetricContext $ctx): void` that adds a correlated
 * `->selectSub(...)` aliased to this metric's `$key`. Cross-table metrics are
 * therefore always computed as scoped subqueries — never via the join used for
 * multi-entity nesting — which prevents fan-out double-counting (spec §6).
 *
 * `$timeField` names the column a `time_range` constrains for this metric
 * (e.g. ledger metrics use `cleared_at`, transaction metrics use `paid_at`).
 */
final class AtlasMetric
{
    public const KINDS = ['count', 'sum', 'avg', 'min', 'max', 'derived'];
    public const FORMATS = ['number', 'money', 'percent', 'duration'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind,
        public readonly mixed $resolver,
        public readonly string $format = 'number',
        public readonly ?string $currency = null,
        public readonly ?string $timeField = null,
        public readonly ?string $permission = null,
        // When true, the resolver emits a SELF-aggregate over the entity's own
        // rows (COUNT(*)/AVG(...)/...) rather than a per-row correlated subquery.
        // SafeQueryBuilder switches to aggregate mode: no per-row id, and only
        // group_by dimensions are projected (so "cases by status" works).
        public readonly bool $aggregate = false,
    ) {}
}
