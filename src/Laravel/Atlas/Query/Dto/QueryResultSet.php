<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * The deterministic output of {@see \Shirahcan\AiClient\Laravel\Atlas\Query\QueryExecutor}:
 * one {@see TargetResult} per executed target plus a presentation hint
 * (`auto` | `separate` | `nested`). This is the canonical shape consumed by the
 * fact validator (P1d), the chat `data_table` renderer (P1g/P1h) and Porter
 * Insights (P5).
 */
final class QueryResultSet
{
    public const PRESENTATIONS = ['auto', 'separate', 'nested'];

    /** @param list<TargetResult> $targets */
    public function __construct(
        public readonly array $targets,
        public readonly string $presentation = 'auto',
    ) {}

    public function toArray(): array
    {
        return [
            'targets' => array_map(static fn (TargetResult $t) => $t->toArray(), $this->targets),
            'presentation' => $this->presentation,
        ];
    }
}
