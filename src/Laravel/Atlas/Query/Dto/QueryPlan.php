<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * A full multi-entity query: an ordered list of {@see QueryTarget}s plus a
 * presentation hint. Built structurally from the planner's JSON; validated by
 * PlanValidator before execution.
 */
final class QueryPlan
{
    public const PRESENTATIONS = ['auto', 'separate', 'nested'];

    /** @param list<QueryTarget> $targets */
    public function __construct(
        public readonly array $targets,
        public readonly string $presentation = 'auto',
    ) {}

    public static function fromArray(array $data): self
    {
        $rawTargets = is_array($data['targets'] ?? null) ? $data['targets'] : [];

        $targets = [];
        foreach (array_values($rawTargets) as $index => $target) {
            if (is_array($target)) {
                $targets[] = QueryTarget::fromArray($target, $index);
            }
        }

        $presentation = (string) ($data['presentation'] ?? 'auto');
        if (! in_array($presentation, self::PRESENTATIONS, true)) {
            $presentation = 'auto';
        }

        return new self($targets, $presentation);
    }

    public function toArray(): array
    {
        return [
            'targets' => array_map(static fn (QueryTarget $t) => [
                'id' => $t->id,
                'entity' => $t->entity,
                'select' => $t->select,
                'metrics' => $t->metrics,
                'filters' => array_map(static fn (QueryFilter $f) => [
                    'field' => $f->field, 'op' => $f->op, 'value' => $f->value,
                ], $t->filters),
                'group_by' => $t->groupBy,
                'sort' => array_map(static fn (QuerySort $s) => ['key' => $s->key, 'dir' => $s->dir], $t->sort),
                'limit' => $t->limit,
                'time_range' => $t->timeRange ? [
                    'metric' => $t->timeRange->metric,
                    'dimension' => $t->timeRange->dimension,
                    'from' => $t->timeRange->from,
                    'to' => $t->timeRange->to,
                ] : null,
                'link' => $t->link ? ['from' => $t->link->from, 'relation' => $t->link->relation] : null,
                'render_group' => $t->renderGroup,
            ], $this->targets),
            'presentation' => $this->presentation,
        ];
    }
}
