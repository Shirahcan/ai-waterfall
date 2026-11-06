<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * The deterministic result of executing one {@see QueryTarget}: ordered column
 * meta + the capped, row-scoped rows (each an assoc map keyed by column key plus
 * a stable `id` for deep-linking/nesting), the total population size, the
 * human-facing `interpretation` line (surfaces intent — spec §5.6), the
 * per-audience deep-link template, and a status.
 *
 * `status: 'unavailable'` is the degraded path: a single target failed to
 * execute, so the rest of the plan still answers (spec §5.4).
 */
final class TargetResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  list<ColumnMeta>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $entity,
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $total,
        public readonly string $interpretation,
        public readonly ?string $deepLinkTemplate = null,
        public readonly string $status = self::STATUS_OK,
        public readonly array $notes = [],
    ) {}

    /**
     * Degraded cell: the target could not be executed. Carries the intended
     * interpretation so the surface can still explain what was asked.
     *
     * @param  list<string>  $notes
     */
    public static function unavailable(string $id, string $entity, string $interpretation = '', array $notes = []): self
    {
        return new self($id, $entity, [], [], 0, $interpretation, null, self::STATUS_UNAVAILABLE, $notes);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'columns' => array_map(static fn (ColumnMeta $c) => $c->toArray(), $this->columns),
            'rows' => $this->rows,
            'total' => $this->total,
            'interpretation' => $this->interpretation,
            'deepLinkTemplate' => $this->deepLinkTemplate,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
