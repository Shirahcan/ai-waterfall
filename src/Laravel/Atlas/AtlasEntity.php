<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * One queryable thing in the platform (consultant, case, transaction, …).
 *
 * Role-agnostic: the only audience-specific parts are `$audiences` (who may
 * query it), `$rowScopes` (per-audience "their own rows" constraint), and
 * `$deepLinks` (per-audience link template). Adding a future role is therefore
 * additive — see docs/porter/extending-to-a-role.md.
 */
final class AtlasEntity
{
    /**
     * @param  list<AtlasDimension>  $dimensions
     * @param  list<AtlasMetric>  $metrics
     * @param  list<AtlasRelation>  $relations
     * @param  array<string, callable>  $rowScopes   audience => fn(\Illuminate\Database\Eloquent\Builder $q, \Illuminate\Contracts\Auth\Authenticatable $u): void
     * @param  array<string, string>  $deepLinks   audience => '/admin/.../{id}'
     * @param  array{key: string, dir: string}|null  $defaultSort
     * @param  list<string>  $sampleQuestions
     * @param  (callable(): \Illuminate\Database\Eloquent\Builder)|null  $baseQueryFactory
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $plural,
        public readonly string $modelClass,
        public readonly array $audiences,
        public readonly ?string $permission,
        public readonly array $dimensions,
        public readonly array $metrics,
        public readonly array $relations,
        public readonly array $rowScopes,
        public readonly array $deepLinks,
        public readonly ?array $defaultSort,
        public readonly array $sampleQuestions,
        public readonly mixed $baseQueryFactory = null,
        /**
         * Optional `fn(array $rows): array` applied AFTER the rows are fetched,
         * for a value only a MODEL can produce.
         *
         * ⚠ THIS EXISTS SO THE ATLAS CAN CALL AN ACCESSOR INSTEAD OF RE-DERIVING
         * IT. `CaseModel::display_title` walks case_name → title → three
         * metadata paths → a MorphTo package → service labels → case_number. It
         * is not a column and it is not expressible in SQL, and this codebase has
         * already had FIVE independent copies of that chain drift apart, so one
         * case read "Sawmill Machine Operator" on screen and "Lmia Only" in the
         * email about it. A COALESCE here would have been the sixth. The
         * presenter batch-loads and asks the model.
         */
        public readonly mixed $rowPresenter = null,
        /**
         * Optional `fn(Builder $q): void` applied right after the audience's row scope, for
         * facts that do not live in Portify's database (a meeting's time and state are
         * calendar-service's, M1d4). It joins the owning service's answer for exactly the rows in
         * scope, as an inline derived table built for this query, so dimensions can filter, group
         * and sort by it. Never a stored copy.
         */
        public readonly mixed $scopedSource = null,
        /**
         * The fields that NAME a row to a person (a client's name, a company, a title). Every LIST
         * read includes them even when the plan forgot to select them (2026-11-02: an admin asked
         * which clients had not replied and Porter listed "62 days", four times, with no name).
         *
         * @var list<string>
         */
        public readonly array $identityKeys = [],
    ) {}

    public function appliesToAudience(string $audience): bool
    {
        return in_array($audience, $this->audiences, true);
    }

    public function dimension(string $key): ?AtlasDimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->key === $key) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * The entity's primary human-facing label column (name / reference): the
     * first selectable, non-sensitive, non-permissioned dimension. Used to keep a
     * metric-only list identifiable (e.g. "top consultants by earnings" must still
     * show the consultant name, not a bare earnings column).
     */
    public function primaryLabelDimension(): ?AtlasDimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->selectable && ! $dimension->sensitive && $dimension->permission === null) {
                return $dimension;
            }
        }

        return null;
    }

    public function metric(string $key): ?AtlasMetric
    {
        foreach ($this->metrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    public function relation(string $key): ?AtlasRelation
    {
        foreach ($this->relations as $relation) {
            if ($relation->key === $key) {
                return $relation;
            }
        }

        return null;
    }

    public function deepLinkTemplate(string $audience): ?string
    {
        return $this->deepLinks[$audience] ?? null;
    }

    /** @return callable|null fn(\Illuminate\Database\Eloquent\Builder $q, \Illuminate\Contracts\Auth\Authenticatable $u): void */
    public function rowScope(string $audience): ?callable
    {
        return $this->rowScopes[$audience] ?? null;
    }
}
