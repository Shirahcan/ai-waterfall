<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * Links a child {@see QueryTarget} to a parent target via an explicit Atlas
 * relation key, so the executor can nest the child under each parent row.
 */
final class QueryLink
{
    public function __construct(
        public readonly string $from,      // parent target id
        public readonly string $relation,  // explicit AtlasRelation key on the parent entity
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || ! isset($data['from'], $data['relation'])) {
            return null;
        }

        return new self(
            from: (string) $data['from'],
            relation: (string) $data['relation'],
        );
    }
}
