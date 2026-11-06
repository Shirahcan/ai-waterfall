<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * Describes one column of a {@see TargetResult} for rendering: its key (the row
 * map key), human label, coarse type, optional display format/currency, and
 * whether it is a `sensitive` (PII) field. Mirrors the `ColumnMeta` TS contract
 * consumed by the chat `data_table` renderer and Porter Insights.
 */
final class ColumnMeta
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $format = null,
        public readonly ?string $currency = null,
        public readonly bool $sensitive = false,
    ) {}

    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
        ];

        if ($this->format !== null) {
            $out['format'] = $this->format;
        }
        if ($this->currency !== null) {
            $out['currency'] = $this->currency;
        }
        if ($this->sensitive) {
            $out['sensitive'] = true;
        }

        return $out;
    }
}
