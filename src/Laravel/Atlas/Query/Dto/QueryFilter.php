<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * One filter clause of a {@see QueryTarget}. `$field` and `$op` are validated
 * against the Atlas in PlanValidator; `$op` must be in the allow-list.
 */
final class QueryFilter
{
    public const OPS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between', 'contains', 'starts_with', 'is_null', 'not_null'];

    public function __construct(
        public readonly string $field,
        public readonly string $op,
        public readonly mixed $value = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            field: (string) ($data['field'] ?? ''),
            op: self::normaliseOp((string) ($data['op'] ?? '=')),
            value: $data['value'] ?? null,
        );
    }

    /**
     * Map the common operator aliases an AI planner emits (eq, gte, ne, …) onto
     * the canonical symbol ops in {@see self::OPS}. Without this a plan with
     * op:"eq" is refused with a raw "the condition 'eq' doesn't apply" message
     * (seen for "transactions completed this month"). An unrecognised op is
     * returned unchanged so PlanValidator still rejects it explicitly.
     */
    private static function normaliseOp(string $op): string
    {
        return match (strtolower(trim($op))) {
            'eq', '==', 'equals' => '=',
            'ne', 'neq', '<>', 'not_equals' => '!=',
            'gt' => '>',
            'gte', 'ge' => '>=',
            'lt' => '<',
            'lte', 'le' => '<=',
            'notin', 'not in' => 'not_in',
            'isnull', 'is null' => 'is_null',
            'notnull', 'is not null', 'is_not_null' => 'not_null',
            'startswith', 'starts with' => 'starts_with',
            'like' => 'contains',
            default => trim($op),
        };
    }
}
