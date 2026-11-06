<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryPlan;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryResultSet;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryTarget;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\TargetResult;

/**
 * Runs a *validated* {@see QueryPlan} into a {@see QueryResultSet}.
 *
 * Independent targets execute standalone (separate tables). A target with a
 * `link` is resolved as the **batched** child of its parent: one query
 * constrained by `whereIn(foreignKey, parentKeys)` (no N+1), grouped by the
 * foreign key and nested under each parent row as `children[childTargetId]`
 * (spec §6). Entity-grain metrics stay scoped subqueries, so combining a
 * parent metric with a nested child count never double-counts.
 *
 * Each target resolves in isolation so one unknown/failed entity degrades to an
 * `unavailable` cell while the rest of the plan still answers (spec §5.4 / §6.5).
 */
class QueryExecutor
{
    public function __construct(
        private AtlasRegistry $registry,
        private SafeQueryBuilder $builder,
    ) {}

    public function execute(QueryPlan $plan, string $audience, Authenticatable $user): QueryResultSet
    {
        /** @var array<string, QueryTarget> $targetsById */
        $targetsById = [];
        foreach ($plan->targets as $target) {
            $targetsById[$target->id] = $target;
        }

        // Parents (no link) first, then linked children (one level of nesting in v1).
        $parents = array_filter($plan->targets, static fn (QueryTarget $t) => $t->link === null);
        $children = array_filter($plan->targets, static fn (QueryTarget $t) => $t->link !== null);

        /** @var array<string, TargetResult> $resultsById */
        $resultsById = [];
        $topLevelOrder = [];

        foreach ($parents as $target) {
            $resultsById[$target->id] = $this->runTarget($target, $audience, $user);
            $topLevelOrder[] = $target->id;
        }

        foreach ($children as $target) {
            $parentResult = $resultsById[$target->link->from] ?? null;
            $parentTarget = $targetsById[$target->link->from] ?? null;
            $parentEntity = $parentTarget !== null ? $this->registry->get($parentTarget->entity) : null;
            $relation = $parentEntity?->relation($target->link->relation);
            $childEntity = $this->registry->get($target->entity);

            // Can't link (missing parent / relation / entity) → run standalone.
            if ($parentResult === null || $parentResult->status !== TargetResult::STATUS_OK
                || $relation === null || $childEntity === null) {
                $resultsById[$target->id] = $this->runTarget($target, $audience, $user);
                $topLevelOrder[] = $target->id;

                continue;
            }

            // Parent keys = each parent row's relation localKey value. v1 relations
            // link on the parent's primary key, which rows expose as `id`.
            $parentKeys = [];
            foreach ($parentResult->rows as $row) {
                if (($row['id'] ?? null) !== null) {
                    $parentKeys[] = $row['id'];
                }
            }

            if ($parentKeys === []) {
                continue; // no parents to nest under
            }

            // One batched child query, then group its rows by parent foreign key.
            $childFlat = $this->builder->run($childEntity, $target, $audience, $user, $relation, $parentKeys);

            $grouped = [];
            if ($childFlat->status === TargetResult::STATUS_OK) {
                foreach ($childFlat->rows as $row) {
                    $parentKey = $row['__parent_key'] ?? null;
                    unset($row['__parent_key']);
                    if ($parentKey !== null) {
                        $grouped[(string) $parentKey][] = $row;
                    }
                }
            }

            $resultsById[$parentTarget->id] = $this->withNestedChild(
                $parentResult,
                $target,
                $childEntity->key,
                $childFlat,
                $grouped,
            );
        }

        $targets = [];
        foreach ($topLevelOrder as $id) {
            $targets[] = $resultsById[$id];
        }

        return new QueryResultSet(array_values($targets), $plan->presentation);
    }

    private function runTarget(QueryTarget $target, string $audience, Authenticatable $user): TargetResult
    {
        $entity = $this->registry->get($target->entity);

        if ($entity === null) {
            return TargetResult::unavailable(
                $target->id,
                $target->entity,
                '',
                ["'{$target->entity}' is not an available data source."],
            );
        }

        return $this->builder->run($entity, $target, $audience, $user);
    }

    /**
     * Attach a per-parent child {@see TargetResult} (array form) under each
     * parent row at `children[childTargetId]`, applying the per-parent cap.
     *
     * @param  array<string, list<array<string, mixed>>>  $grouped  childRows keyed by parent id
     */
    private function withNestedChild(TargetResult $parentResult, QueryTarget $childTarget, string $childEntityKey, TargetResult $childFlat, array $grouped): TargetResult
    {
        $perParentCap = max(1, $childTarget->limit);

        $augmentedRows = [];
        foreach ($parentResult->rows as $row) {
            $parentKey = (string) ($row['id'] ?? '');
            $childRows = $grouped[$parentKey] ?? [];

            $childResult = new TargetResult(
                id: $childTarget->id,
                entity: $childEntityKey,
                columns: $childFlat->columns,
                rows: array_slice($childRows, 0, $perParentCap),
                total: count($childRows),
                interpretation: $childFlat->interpretation,
                deepLinkTemplate: $childFlat->deepLinkTemplate,
                status: $childFlat->status,
            );

            $rowChildren = $row['children'] ?? [];
            $rowChildren[$childTarget->id] = $childResult->toArray();
            $row['children'] = $rowChildren;
            $augmentedRows[] = $row;
        }

        return new TargetResult(
            id: $parentResult->id,
            entity: $parentResult->entity,
            columns: $parentResult->columns,
            rows: $augmentedRows,
            total: $parentResult->total,
            interpretation: $parentResult->interpretation,
            deepLinkTemplate: $parentResult->deepLinkTemplate,
            status: $parentResult->status,
            notes: $parentResult->notes,
        );
    }
}
