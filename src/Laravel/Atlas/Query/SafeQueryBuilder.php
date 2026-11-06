<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\AtlasDimension;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasMetric;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRelation;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\ColumnMeta;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryFilter;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryTarget;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\TargetResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compiles ONE validated {@see QueryTarget} into Eloquent (never string SQL) and
 * executes it deterministically: base query + audience row-scope + whitelisted
 * SELECT (aliased to Atlas keys) + correlated, row-scoped metric subqueries +
 * bound/escaped filters + sort + hard row cap.
 *
 * Identifier safety (spec §5.5): every column comes from the Atlas dimension
 * `path` / metric resolver — NEVER from a raw plan string. Values use Eloquent
 * binding; LIKE wildcards are escaped with an explicit, driver-correct ESCAPE
 * clause (so SQLite tests and MySQL prod behave identically).
 */
class SafeQueryBuilder
{
    /** Absolute upper bound on returned rows, independent of the requested limit. */
    public const HARD_ROW_CAP = 500;

    public function __construct(private AtlasRegistry $registry) {}

    /**
     * Execute a target. Wrapped so a single failing target degrades to an
     * `unavailable` cell rather than failing the whole plan (spec §5.4).
     */
    /**
     * @param  AtlasRelation|null  $linkRelation  when set, batch this target as the
     *         child of a parent: constrain to `whereIn(foreignKey, $parentKeys)` and
     *         carry each row's foreign key as `__parent_key` so the executor can
     *         group children under their parent (P1h). Metrics stay scoped subqueries.
     * @param  list<string|int>  $parentKeys
     */
    public function run(AtlasEntity $entity, QueryTarget $target, string $audience, Authenticatable $user, ?AtlasRelation $linkRelation = null, array $parentKeys = []): TargetResult
    {
        $interpretation = $this->buildInterpretation($entity, $target);

        try {
            return $this->build($entity, $target, $audience, $user, $interpretation, $linkRelation, $parentKeys);
        } catch (Throwable $e) {
            report($e);

            return TargetResult::unavailable(
                $target->id,
                $entity->key,
                $interpretation,
                ['This result was temporarily unavailable.'],
            );
        }
    }

    /**
     * @param  list<string|int>  $parentKeys
     */
    private function build(AtlasEntity $entity, QueryTarget $target, string $audience, Authenticatable $user, string $interpretation, ?AtlasRelation $linkRelation = null, array $parentKeys = []): TargetResult
    {
        /** @var Builder $q */
        $q = $entity->baseQueryFactory
            ? ($entity->baseQueryFactory)()
            : $entity->modelClass::query();

        $model = $q->getModel();
        $baseTable = $model->getTable();
        $qualifiedKey = $model->getQualifiedKeyName();

        // Per-audience row-scope (admin → none). Applies to the entity query.
        if ($scope = $entity->rowScope($audience)) {
            $scope($q, $user);
        }

        // Facts another service owns, joined for the rows in scope (before any filter uses them).
        if ($entity->scopedSource !== null) {
            ($entity->scopedSource)($q);
        }

        $joined = [];

        // --- population-defining clauses (applied before the count clone) ---
        foreach ($target->filters as $filter) {
            $this->applyFilter($q, $entity, $filter, $baseTable, $joined);
        }
        $this->applyDimensionTimeRange($q, $entity, $target, $baseTable, $joined);

        // Linked child (P1h): constrain to the parent rows by their foreign key.
        if ($linkRelation !== null) {
            $q->whereIn(
                "{$baseTable}.{$linkRelation->foreignKey}",
                $parentKeys === [] ? ['__atlas_no_parent__'] : $parentKeys,
            );
        }

        // Total population BEFORE projection / cap (Eloquent global scopes apply).
        // The clone is taken before group_by, so this is the underlying row count
        // (e.g. total cases) even when the projection groups them.
        $total = (clone $q)->count();

        // Aggregate mode: any selected metric that self-aggregates the entity's own
        // rows. Then there is no per-row id, and only group_by dimensions project.
        $aggregateMode = false;
        foreach ($target->metrics as $key) {
            if ($entity->metric($key)?->aggregate) {
                $aggregateMode = true;
                break;
            }
        }

        // --- projection ---
        $hasId = $target->groupBy === [] && ! $aggregateMode;
        if ($hasId) {
            $q->addSelect("{$qualifiedKey} as id");
        }

        // Carry the foreign key so the executor can group children by parent.
        if ($linkRelation !== null) {
            $q->addSelect("{$baseTable}.{$linkRelation->foreignKey} as __parent_key");
        }

        $columns = [];
        $selectKeys = [];
        /** @var array<string, int> 1-based place of each projected dimension in the SELECT list (see group by) */
        $selectPositions = [];
        // A select list someone else started with `*` makes those places unknowable.
        $positionsKnown = ! collect($q->getQuery()->columns ?? [])
            ->contains(static fn ($c) => is_string($c) && str_ends_with($c, '*'));
        // Always project group-by dimensions FIRST — the group label belongs in
        // a grouped result, even when the planner left it out of "select" (else
        // the table shows bare counts with no labels, and a sort on the group
        // dimension is dropped as "not a selected column").
        $projectionKeys = array_values(array_unique(array_merge($target->groupBy, $target->select)));
        // A non-aggregate LIST that projects metrics but no displayable dimension
        // (e.g. "top consultants by earnings" → select:[], metrics:[earnings])
        // would render a bare metric column with no way to tell the rows apart.
        // Inject the entity's primary label dimension so each row is identifiable.
        if ($projectionKeys === [] && ! $aggregateMode && $target->groupBy === [] && $target->metrics !== []) {
            if ($labelDim = $entity->primaryLabelDimension()) {
                $projectionKeys = [$labelDim->key];
            }
        }
        foreach ($projectionKeys as $key) {
            $dim = $entity->dimension($key);
            if ($dim === null) {
                continue; // PlanValidator drops these; defensive
            }
            // When the query GROUPS (aggregate metric OR a group_by), only the
            // grouped dimensions may be projected. Projecting a non-grouped column
            // alongside a group_by is a MySQL ONLY_FULL_GROUP_BY error (1055) that
            // SQLite silently allows — so this guard, not just the aggregate case,
            // keeps grouped queries valid on both drivers.
            if (($aggregateMode || $target->groupBy !== []) && ! in_array($key, $target->groupBy, true)) {
                continue;
            }
            $column = $this->resolveColumn($entity, $dim, $baseTable, $joined, $q);
            $selectPositions[$dim->key] = count($q->getQuery()->columns ?? []) + 1;
            $q->addSelect($dim->expression !== null
                // The alias is quoted by the grammar: a key that is a SQL word (`from`, `by`, `case`)
                // broke the whole query unquoted (2026-11-01), and only on the driver that cares.
                ? DB::raw("{$column} as ".$q->getQuery()->getGrammar()->wrap($dim->key))
                : "{$column} as {$dim->key}");
            $columns[] = new ColumnMeta(
                key: $dim->key,
                label: $dim->label,
                type: $dim->type,
                format: $this->dimensionFormat($dim),
                sensitive: $dim->sensitive,
            );
            $selectKeys[] = $dim->key;
        }

        // --- metrics: correlated, row-scoped subqueries (never the nesting join) ---
        $metricMap = [];
        $metricKeys = [];
        $tr = $target->timeRange;
        foreach ($target->metrics as $key) {
            $metric = $entity->metric($key);
            if ($metric === null) {
                continue;
            }
            $from = ($tr && $tr->metric === $key) ? $tr->from : null;
            $to = ($tr && $tr->metric === $key) ? $tr->to : null;
            $ctx = new MetricContext($audience, $user, $from, $to, $metric->currency);
            ($metric->resolver)($q, $ctx);

            $columns[] = new ColumnMeta(
                key: $metric->key,
                label: $metric->label,
                type: $metric->format === 'money' ? 'money' : 'number',
                format: $metric->format,
                currency: $metric->currency,
            );
            $metricMap[$metric->key] = $metric;
            $metricKeys[] = $metric->key;
        }

        // --- group by (Atlas-resolved paths only) ---
        foreach ($target->groupBy as $key) {
            $dim = $entity->dimension($key);
            if ($dim !== null) {
                $column = $this->resolveColumn($entity, $dim, $baseTable, $joined, $q);
                /*
                 * ⚠ A COMPUTED DIMENSION IS GROUPED BY ITS PLACE IN THE SELECT LIST, NOT BY REPEATING IT.
                 * MySQL cannot tell that two copies of a correlated subquery are one expression, and
                 * MariaDB (production) cannot tell that for ANY expression - a CASE, a JSON read, a
                 * COALESCE - so `SELECT <expr> AS client ... GROUP BY <expr>` fails ONLY_FULL_GROUP_BY
                 * (1055). 75 subquery breakdowns failed on production, and once those were fixed the
                 * production sweep found 16 more plain-expression ones that local MySQL 8 accepts
                 * (2026-11-06). SQLite, which the tests run on, enforces neither. The position names the
                 * very same select item, so every engine sees it as grouped. Not the alias: for GROUP
                 * BY, MySQL resolves a bare name against the tables' columns BEFORE the select aliases,
                 * so a key like `status` would silently group by a different column.
                 */
                if ($dim->expression !== null && $positionsKnown && isset($selectPositions[$key])) {
                    $q->groupBy(DB::raw((string) $selectPositions[$key]));
                } else {
                    $q->groupBy($dim->expression !== null ? DB::raw($column) : $column);
                }
            }
        }

        // A GROUPED query carrying only CORRELATED (non-aggregate) metrics — e.g.
        // "each consultant + their active case count" (group_by name + the
        // correlated active_cases subquery) — references the base row's PK inside
        // the metric subquery (…WHERE consultant_id = consultants.id). Grouping by
        // a label alone leaves that PK non-grouped, which MySQL ONLY_FULL_GROUP_BY
        // rejects (SQLite silently allows it). Adding the base primary key to the
        // GROUP BY makes the correlated subqueries valid; because such a label is
        // 1:1 with the PK this still yields one row per entity (and correctly
        // separates two entities that share a name). Aggregate groupings
        // (case_count by status) are SELF-aggregates and must group purely by the
        // dimension, so they are deliberately excluded.
        if ($target->groupBy !== [] && ! $aggregateMode && $metricKeys !== []) {
            $q->groupBy($qualifiedKey);
        }

        // --- sort (alias for selected dims/metrics; resolved path otherwise) ---
        // Treat any grouped query (aggregate OR group_by) as "grouped" for sort
        // safety too: ordering by a non-grouped column under group_by is the same
        // ONLY_FULL_GROUP_BY error, so such sorts are dropped (not resolved).
        $this->applySort($q, $entity, $target, $selectKeys, $metricKeys, $baseTable, $joined, $aggregateMode || $target->groupBy !== []);

        // --- hard row cap ---
        // A linked child is fetched up to the hard cap so the per-parent slice
        // (applied in QueryExecutor) has enough rows across all parents.
        $q->limit($linkRelation !== null
            ? self::HARD_ROW_CAP
            : min(max(1, $target->limit), self::HARD_ROW_CAP));
        if ($linkRelation === null && $target->offset > 0) {
            $q->offset($target->offset);
        }

        // --- execute + map to whitelisted rows ---
        $rows = [];
        foreach ($q->get() as $record) {
            $attributes = $record->getAttributes();
            $row = [];
            if ($hasId) {
                $row['id'] = $attributes['id'] ?? $record->getKey();
            }
            foreach ($selectKeys as $key) {
                $row[$key] = $attributes[$key] ?? null;
            }
            foreach ($metricKeys as $key) {
                $row[$key] = $this->castMetricValue($attributes[$key] ?? null, $metricMap[$key]);
            }
            if ($linkRelation !== null) {
                $row['__parent_key'] = $attributes['__parent_key'] ?? null;
            }
            $rows[] = $row;
        }

        /*
         * A model-resolved column (e.g. a case's human display title) is filled
         * in here, once, for the whole page of rows. Never row-by-row: that is
         * an N+1 on a list a consultant is waiting for.
         *
         * Fail-soft by design - a presenter that throws must cost the nicer
         * label, never the answer, so the raw rows still render.
         */
        if ($rows !== [] && is_callable($entity->rowPresenter)) {
            try {
                $rows = ($entity->rowPresenter)($rows, $audience, $user);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // For aggregate / grouped results each returned row IS a group (e.g. one
        // row per status), not an underlying record — so the population total is
        // the number of grouped rows, NOT the base record count (which misleads,
        // e.g. "Showing 1 of 4" for a single status group covering 4 ads). NB: if
        // the groups exceed the row cap this reflects the capped page (rare for
        // groupings; non-grouped lists keep the true base total for "X of N").
        if ($aggregateMode || $target->groupBy !== []) {
            $total = count($rows);
        }

        return new TargetResult(
            id: $target->id,
            entity: $entity->key,
            columns: $columns,
            rows: $rows,
            total: $total,
            interpretation: $interpretation,
            deepLinkTemplate: $entity->deepLinkTemplate($audience),
            status: TargetResult::STATUS_OK,
        );
    }

    /**
     * Resolve a dimension to a qualified, Atlas-safe column. A `relation.column`
     * path joins the related table via a declared belongsTo relation once
     * (joins deduped by relation key across select/filter/sort/group).
     */
    private function resolveColumn(AtlasEntity $entity, AtlasDimension $dim, string $baseTable, array &$joined, Builder $q): string
    {
        // Multi-hop projection (P3b): apply each declared leftJoin once, then use
        // the raw (Atlas-authored) expression as the resolved column.
        if ($dim->joins !== null) {
            foreach ($dim->joins as [$table, $first, $second]) {
                $marker = "mhjoin:{$table}";
                if (! array_key_exists($marker, $joined)) {
                    $q->leftJoin($table, $first, '=', $second);
                    $joined[$marker] = true;
                }
            }
        }
        if ($dim->expression !== null) {
            return $dim->expression;
        }

        if (! str_contains($dim->path, '.')) {
            return "{$baseTable}.{$dim->path}";
        }

        [$relationKey, $column] = explode('.', $dim->path, 2);
        $relation = $entity->relation($relationKey);

        if ($relation === null || $relation->type !== 'belongsTo') {
            throw new \RuntimeException(
                "Dimension '{$dim->key}' on '{$entity->key}' references an unknown belongsTo relation '{$relationKey}'.",
            );
        }

        $relatedTable = $this->relatedTable($relation);

        if (! array_key_exists($relationKey, $joined)) {
            $q->leftJoin(
                $relatedTable,
                "{$baseTable}.{$relation->localKey}",
                '=',
                "{$relatedTable}.{$relation->foreignKey}",
            );
            $joined[$relationKey] = true;
        }

        return "{$relatedTable}.{$column}";
    }

    private function relatedTable(AtlasRelation $relation): string
    {
        // Prefer the relation's declared table (works before the target entity
        // is registered); otherwise resolve it from the registry's model class.
        if ($relation->relatedTable !== null) {
            return $relation->relatedTable;
        }

        $entity = $this->registry->get($relation->targetEntity);

        if ($entity === null) {
            throw new \RuntimeException(
                "Relation '{$relation->key}' targets unknown entity '{$relation->targetEntity}'.",
            );
        }

        $class = $entity->modelClass;

        return (new $class)->getTable();
    }

    private function applyFilter(Builder $q, AtlasEntity $entity, QueryFilter $filter, string $baseTable, array &$joined): void
    {
        $dim = $entity->dimension($filter->field);
        if ($dim === null) {
            return; // PlanValidator refuses unknown filters; defensive
        }

        $column = $this->resolveColumn($entity, $dim, $baseTable, $joined, $q);
        $value = $dim->type === 'datetime'
            ? $this->storedInstants($filter->value, $filter->op)
            : $filter->value;

        // A derived dimension (e.g. the LMIA current stage, a JSON $.current_stage
        // extract) resolves to raw, Atlas-authored SQL rather than a plain column.
        // It must be passed as a raw expression so the grammar does not mis-wrap it
        // as an identifier (which silently broke filtering and is why such
        // dimensions used to be select/group only). The expression is
        // Atlas-authored — never plan input — so identifier safety is preserved.
        $isExpr = $dim->expression !== null;
        $col = $isExpr ? DB::raw($column) : $column;

        /*
         * Widen the planner's chosen value to every stored value that satisfies
         * it, where the dimension declares an expander (see AtlasDimension).
         *
         * ⚠ EQUALITY BECOMES `in` WHEN ONE CONCEPT HAS SEVERAL STORED FORMS.
         * "LMIA" means both `lmia` and `lmia_work_permit`, because a combined
         * file carries a real ESDC filing. Leaving `=` alone here would hand the
         * canonical column the same undercount the free-text column had.
         * Fails soft: a throwing expander leaves the filter exactly as planned.
         */
        $op = $filter->op;
        if ($dim->valueExpander !== null && in_array($op, ['=', '!=', 'in', 'not_in'], true)) {
            try {
                $expanded = [];
                foreach (is_array($value) ? $value : [$value] as $one) {
                    foreach ((array) ($dim->valueExpander)($one) as $match) {
                        $expanded[] = $match;
                    }
                }
                $expanded = array_values(array_unique($expanded));

                if ($expanded !== [] && $expanded !== [$value]) {
                    $value = $expanded;
                    $op = in_array($op, ['=', 'in'], true) ? 'in' : 'not_in';
                }
            } catch (\Throwable) {
                // Keep the original filter rather than silently matching nothing.
            }
        }

        switch ($op) {
            case '=':
            case '!=':
            case '>':
            case '>=':
            case '<':
            case '<=':
                $q->where($col, $op, $value);
                break;
            case 'in':
                $q->whereIn($col, is_array($value) ? $value : [$value]);
                break;
            case 'not_in':
                $q->whereNotIn($col, is_array($value) ? $value : [$value]);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $q->whereBetween($col, [$value[0], $value[1]]);
                }
                break;
            case 'is_null':
                $q->whereNull($col);
                break;
            case 'not_null':
                $q->whereNotNull($col);
                break;
            case 'contains':
                $this->applyLike($q, $column, (string) $value, true, $isExpr);
                break;
            case 'starts_with':
                $this->applyLike($q, $column, (string) $value, false, $isExpr);
                break;
        }
    }

    /**
     * A datetime filter value, in the form the database stores: UTC `Y-m-d H:i:s`.
     *
     * ⚠ THE PLANNER WRITES THE ASKER'S DAY WITH AN OFFSET ("2026-11-05T00:00:00+01:00"). Bound as
     * text, that compares as a STRING against a stored "2026-11-05 18:30:00", and ' ' sorts before
     * 'T', so a meeting at 18:30 fell outside "today" and Porter told an admin there was no meeting
     * (prod, 2026-11-05). A derived column (calendar-service's start time) is always a string, so
     * the database cannot rescue it. A bare date as the upper bound means the whole of that day.
     * Anything unparseable is left exactly as planned.
     */
    private function storedInstants(mixed $value, string $op): mixed
    {
        $one = function (mixed $v, bool $upper): mixed {
            if (! is_string($v) || trim($v) === '') {
                return $v;
            }
            try {
                $at = \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable) {
                return $v;
            }
            if ($upper && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v))) {
                $at = $at->endOfDay();
            }

            return $at->utc()->format('Y-m-d H:i:s');
        };

        if (is_array($value)) {
            return array_map(
                fn ($v, $i) => $one($v, $op === 'between' && $i === 1),
                $value,
                array_keys($value),
            );
        }

        return $one($value, in_array($op, ['<', '<='], true));
    }

    /**
     * Parameter-bound LIKE with the `%`/`_`/`\` wildcards escaped, using an
     * explicit ESCAPE clause whose literal is written correctly per driver
     * (MySQL processes backslashes in string literals; SQLite does not).
     */
    private function applyLike(Builder $q, string $column, string $value, bool $bothSides, bool $isExpr = false): void
    {
        $escaped = addcslashes($value, '%_\\');
        $pattern = ($bothSides ? '%' : '').$escaped.'%';

        // An Atlas-authored expression column is already raw SQL — use it as-is;
        // a plain identifier is wrapped by the grammar.
        $wrapped = $isExpr ? $column : $q->getQuery()->getGrammar()->wrap($column);
        $driver = $q->getConnection()->getDriverName();
        $escapeLiteral = in_array($driver, ['mysql', 'mariadb'], true) ? "'\\\\'" : "'\\'";

        $q->whereRaw("{$wrapped} like ? escape {$escapeLiteral}", [$pattern]);
    }

    private function applyDimensionTimeRange(Builder $q, AtlasEntity $entity, QueryTarget $target, string $baseTable, array &$joined): void
    {
        $tr = $target->timeRange;
        if ($tr === null || $tr->dimension === null) {
            return;
        }

        $dim = $entity->dimension($tr->dimension);
        if ($dim === null) {
            return;
        }

        $column = $this->resolveColumn($entity, $dim, $baseTable, $joined, $q);
        $col = $dim->expression !== null ? DB::raw($column) : $column;
        if ($tr->from !== null) {
            $q->where($col, '>=', $tr->from);
        }
        if ($tr->to !== null) {
            $q->where($col, '<=', $tr->to);
        }
    }

    private function applySort(Builder $q, AtlasEntity $entity, QueryTarget $target, array $selectKeys, array $metricKeys, string $baseTable, array &$joined, bool $grouped = false): void
    {
        $sorts = $target->sort;
        if ($sorts === []) {
            if ($grouped) {
                // A grouped/aggregate result orders naturally by its aggregate
                // (e.g. "cases by stage" → count desc). Ordering by the entity's
                // row-oriented defaultSort would reference a non-grouped column,
                // which ONLY_FULL_GROUP_BY (MySQL) rejects.
                if ($metricKeys !== []) {
                    $sorts = [new \Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QuerySort(key: $metricKeys[0], dir: 'desc')];
                }
            } elseif ($entity->defaultSort !== null) {
                $sorts = [new \Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QuerySort(
                    key: (string) ($entity->defaultSort['key'] ?? ''),
                    dir: (string) ($entity->defaultSort['dir'] ?? 'asc'),
                )];
            }
        }

        foreach ($sorts as $clause) {
            // Selected dim / present metric → order by its output alias.
            if (in_array($clause->key, $metricKeys, true) || in_array($clause->key, $selectKeys, true)) {
                $q->orderBy($clause->key, $clause->dir);

                continue;
            }
            // In a grouped query only grouped dims / aggregates (handled above) may
            // be ordered — never a raw non-grouped column.
            if ($grouped) {
                continue;
            }
            // Otherwise (e.g. a default sort on an unselected column) → resolved path.
            $dim = $entity->dimension($clause->key);
            if ($dim !== null) {
                $column = $this->resolveColumn($entity, $dim, $baseTable, $joined, $q);
                // An Atlas-authored expression is raw SQL, never an identifier to quote.
                $q->orderBy($dim->expression !== null ? DB::raw($column) : $column, $clause->dir);
            }
        }
    }

    private function dimensionFormat(AtlasDimension $dim): ?string
    {
        return match ($dim->type) {
            'money' => 'money',
            'date', 'datetime' => 'date',
            'number' => 'number',
            default => null,
        };
    }

    private function castMetricValue(mixed $value, AtlasMetric $metric): int|float
    {
        if ($value === null) {
            return $metric->format === 'money' || $metric->format === 'percent' ? 0.0 : 0;
        }

        if ($metric->format === 'money' || $metric->format === 'percent') {
            return (float) $value;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    /**
     * A human-facing line surfacing the query's INTENT (spec §5.6) so a valid
     * plan with a wrong grouping / time field / metric is visible above the
     * table and correctable.
     */
    private function buildInterpretation(AtlasEntity $entity, QueryTarget $target): string
    {
        $segments = [];

        $firstSort = $target->sort[0] ?? null;
        $leadMetric = $firstSort !== null ? $entity->metric($firstSort->key) : null;

        if ($target->groupBy !== []) {
            // A grouped query: name the grouping ("Meetings grouped by status").
            // The grouping IS the shape of the answer, so it takes precedence
            // over any sort-based "Top N" phrasing (avoids "Top 50 meetings by
            // meetings", whose limit figure also trips the ungrounded-figure
            // stripper). Grouped metrics are columns, so they are not echoed.
            $lead = ucfirst($entity->plural);
            $groupLabels = [];
            foreach ($target->groupBy as $key) {
                if ($dim = $entity->dimension($key)) {
                    $groupLabels[] = mb_strtolower($dim->label);
                }
            }
            if ($groupLabels !== []) {
                $lead .= ' grouped by '.implode(' and ', $groupLabels);
            }
            $segments[] = $lead;
        } elseif ($leadMetric !== null) {
            $direction = $firstSort->dir === 'desc' ? 'Top' : 'Bottom';
            $segments[] = "{$direction} {$target->limit} {$entity->plural} by {$leadMetric->label}";
        } else {
            // A plain (ungrouped) aggregate / list: name the entity, then any
            // metric — skipping a metric whose label merely restates the entity
            // name (e.g. the job_ad "Job ads" count), which still stands alone
            // as a column.
            $segments[] = ucfirst($entity->plural);
            $labels = [];
            foreach ($target->metrics as $key) {
                if ($metric = $entity->metric($key)) {
                    if (mb_strtolower(trim($metric->label)) === mb_strtolower(trim($entity->plural))) {
                        continue;
                    }
                    $labels[] = $metric->label;
                }
            }
            $labels = array_values(array_unique($labels));
            if ($labels !== []) {
                $segments[] = 'with '.implode(', ', $labels);
            }
        }

        $filterText = [];
        foreach ($target->filters as $filter) {
            if ($dim = $entity->dimension($filter->field)) {
                $filterText[] = $this->describeFilter($dim, $filter->op, $filter->value);
            }
        }
        if ($filterText !== []) {
            $segments[] = 'where '.implode(' and ', $filterText);
        }

        $tr = $target->timeRange;
        if ($tr !== null && ($tr->from !== null || $tr->to !== null)) {
            $window = trim(($tr->from ?? '').'–'.($tr->to ?? ''), '–');
            $field = null;
            if ($tr->metric !== null && ($m = $entity->metric($tr->metric))) {
                $field = $m->timeField;
            } elseif ($tr->dimension !== null) {
                $field = $tr->dimension;
            }
            $segments[] = $field !== null ? "{$window} by {$field}" : $window;
        }

        foreach ($target->metrics as $key) {
            $metric = $entity->metric($key);
            if ($metric !== null && $metric->currency !== null) {
                $segments[] = $metric->currency;
                break;
            }
        }

        return implode(', ', $segments);
    }

    /**
     * One filter, in words a consultant would actually use.
     *
     * ⚠ A BOOLEAN NEVER READS AS A NUMBER. `(string) true` is "1" and
     * `(string) false` is the EMPTY STRING, so the generic path rendered
     * "…cases, where active is 1" and, for the negative, the dangling
     * "…where active is " with nothing after it. A bool filter is a plain
     * adjective: "active", or "not active".
     */
    private function describeFilter(AtlasDimension $dim, string $op, mixed $value): string
    {
        $label = mb_strtolower($dim->label);

        if ($dim->type === 'bool' && in_array($op, ['=', '!='], true)) {
            $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;

            if ($op === '!=') {
                $isTrue = ! $isTrue;
            }

            return $isTrue ? $label : "not {$label}";
        }

        /*
         * ⚠ SAY THE HUMAN'S WORD, NOT THE STORED KEY. A canonical enum reads
         * back as "where type is lmia_work_permit" otherwise - correct for the
         * query and meaningless to the consultant it is spoken to.
         */
        if ($dim->valueLabels !== null) {
            $value = is_array($value)
                ? array_map(fn ($one) => $this->labelValue($dim, $one), $value)
                : $this->labelValue($dim, $value);
        }

        return $label.' '.$this->describeOp($op, $value);
    }

    private function labelValue(AtlasDimension $dim, mixed $value): mixed
    {
        return is_string($value)
            ? ($dim->valueLabels[$value] ?? $value)
            : $value;
    }

    private function describeOp(string $op, mixed $value): string
    {
        $rendered = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

        return match ($op) {
            '=' => "is {$rendered}",
            '!=' => "is not {$rendered}",
            '>' => "> {$rendered}",
            '>=' => "≥ {$rendered}",
            '<' => "< {$rendered}",
            '<=' => "≤ {$rendered}",
            'in' => "in ({$rendered})",
            'not_in' => "not in ({$rendered})",
            'between' => "between {$rendered}",
            'contains' => "contains \"{$rendered}\"",
            'starts_with' => "starts with \"{$rendered}\"",
            'is_null' => 'is empty',
            'not_null' => 'is set',
            default => $rendered,
        };
    }
}
