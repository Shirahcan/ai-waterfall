<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * One entity query within a {@see QueryPlan}. Parsed structurally here
 * (defaults applied, limit clamped); existence/permission/op validity is
 * enforced later by PlanValidator.
 */
final class QueryTarget
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;

    public const MAX_OFFSET = 10000;

    /**
     * @param  list<string>  $select
     * @param  list<string>  $metrics
     * @param  list<QueryFilter>  $filters
     * @param  list<string>  $groupBy
     * @param  list<QuerySort>  $sort
     */
    public function __construct(
        public readonly string $id,
        public readonly string $entity,
        public readonly array $select,
        public readonly array $metrics,
        public readonly array $filters,
        public readonly array $groupBy,
        public readonly array $sort,
        public readonly int $limit,
        public readonly ?TimeRange $timeRange,
        public readonly ?QueryLink $link,
        public readonly ?string $renderGroup,
        // Rows to skip, so a long list can be read page by page (2026-11-02: Porter saw the first
        // 10 of 30 invitations and could not go past them).
        public readonly int $offset = 0,
    ) {}

    public static function fromArray(array $data, int $index = 0): self
    {
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $stringList = static fn ($v): array => array_values(array_filter(
            array_map('strval', is_array($v) ? $v : []),
            static fn ($s) => $s !== '',
        ));

        return new self(
            id: (string) ($data['id'] ?? 't'.($index + 1)),
            entity: (string) ($data['entity'] ?? ''),
            select: $stringList($data['select'] ?? []),
            metrics: $stringList($data['metrics'] ?? []),
            filters: array_map(
                static fn ($f) => QueryFilter::fromArray(is_array($f) ? $f : []),
                is_array($data['filters'] ?? null) ? $data['filters'] : [],
            ),
            groupBy: $stringList($data['group_by'] ?? []),
            sort: array_map(
                static fn ($s) => QuerySort::fromArray(is_array($s) ? $s : []),
                is_array($data['sort'] ?? null) ? $data['sort'] : [],
            ),
            limit: $limit,
            timeRange: TimeRange::fromArray($data['time_range'] ?? null),
            link: QueryLink::fromArray($data['link'] ?? null),
            renderGroup: isset($data['render_group']) ? (string) $data['render_group'] : null,
            offset: max(0, min((int) ($data['offset'] ?? 0), self::MAX_OFFSET)),
        );
    }
}
