<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * One selectable / filterable / groupable field of an {@see AtlasEntity}.
 *
 * `$path` is the Atlas-resolved access path — either a plain column on the
 * entity's base table (`status`) or a `relation.column` (`user.email`) that the
 * SafeQueryBuilder resolves through a declared {@see AtlasRelation} (P1c). The
 * model NEVER supplies a raw column; it only ever names a dimension key.
 */
final class AtlasDimension
{
    public const TYPES = ['string', 'number', 'money', 'date', 'datetime', 'enum', 'bool'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $path,
        public readonly string $type,
        public readonly bool $selectable = true,
        public readonly bool $filterable = true,
        public readonly bool $groupable = false,
        public readonly bool $sensitive = false,
        public readonly ?string $permission = null,
        // For `enum` dimensions: the allowed values, surfaced to the planner so it
        // filters with real values and never invents one (e.g. status='active').
        public readonly ?array $values = null,
        // `$joins`: multi-hop projection (P3b) — a list of [table, first, second]
        // leftJoins applied before the value is read; select + group_by only (a
        // filter through joins is not supported). Never model input.
        public readonly ?array $joins = null,
        // `$expression`: raw, Atlas-authored SQL (e.g. a JSON $.current_stage
        // extract) used in place of a plain column. When a dimension is a
        // single-table expression (no `$joins`) it may also be FILTERED (P06a) —
        // SafeQueryBuilder passes it via DB::raw so the grammar doesn't mis-wrap
        // it; identifier safety is preserved because the expression is never
        // model/plan input.
        public readonly ?string $expression = null,
        // `$description`: guidance for the PLANNER only — it rides the catalog
        // so the model knows what a dimension means, and is NEVER shown to a
        // user.
        //
        // ⚠ THE LABEL HAS TWO AUDIENCES AND THEY WANT OPPOSITE THINGS. The
        // label is read aloud in the results line ("…cases, where active is 1"),
        // so it must be SHORT; the planner needs the definition, so it wants it
        // LONG. Cramming both into the label shipped exactly that: a consultant
        // saw "Here are the cases, where active — the open caseload (excludes
        // closed, rejected, declined, cancelled and not-yet-started files) is 1."
        // Keep labels human, put the teaching here.
        public readonly ?string $description = null,
        // `$valueExpander`: given ONE value the planner chose, return every
        // stored value that should satisfy it. Applied by SafeQueryBuilder to
        // `=`, `!=`, `in` and `not_in` only. Atlas-authored, never plan input.
        //
        // ⚠ THIS EXISTS BECAUSE ONE CASE CAN BE TWO TYPES. A combined
        // `lmia_work_permit` file involves a real ESDC filing with a
        // $1,000-per-position non-refundable fee, so a consultant filtering for
        // "LMIA" must get it. A plain `case_type_key = 'lmia'` silently drops 28
        // such files - the exact undercount CaseTypeVocabulary was built to end.
        // Without an expander the canonical column would trade one wrong number
        // for another.
        public readonly ?\Closure $valueExpander = null,
        // `$valueLabels`: stored value => the words a human reads, used when a
        // filter is narrated back ("where type is LMIA + work permit").
        //
        // ⚠ A CANONICAL KEY IS A MACHINE'S WORD. Without this the results line
        // says "Cases, where type is lmia_work_permit" to a consultant - the
        // same two-audience mistake that once read "where active — the open
        // caseload (excludes closed…) is 1". The key is for the query; this is
        // for the person.
        public readonly ?array $valueLabels = null,
    ) {}
}
