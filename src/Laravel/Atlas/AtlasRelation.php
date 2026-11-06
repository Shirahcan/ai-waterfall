<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * A typed, explicitly-keyed link from one {@see AtlasEntity} to another.
 *
 * Keys are unique and explicit (e.g. `consultant_cases_assigned` vs a future
 * `consultant_cases_originated`) so the query planner picks a relation by key
 * and the validator can reject a bare/ambiguous reference. Used both for
 * `relation.column` dimensions (belongsTo) and for multi-entity nesting
 * (hasMany / hasManyThrough).
 */
final class AtlasRelation
{
    public const TYPES = ['hasMany', 'belongsTo', 'hasManyThrough'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $targetEntity,
        public readonly string $type,
        public readonly string $localKey,
        public readonly string $foreignKey,
        public readonly ?string $through = null,
        // Physical table of the related entity, so SafeQueryBuilder can join a
        // belongsTo `relation.column` dimension even before the target entity is
        // registered (e.g. consultant_user → users while `user` ships in P4).
        // When null, the builder resolves the table from the registry.
        public readonly ?string $relatedTable = null,
    ) {}
}
