<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/** One sort clause: `$key` is a selected dimension key or a present metric key. */
final class QuerySort
{
    public function __construct(
        public readonly string $key,
        public readonly string $dir = 'asc',
    ) {}

    public static function fromArray(array $data): self
    {
        $dir = strtolower((string) ($data['dir'] ?? 'asc'));

        return new self(
            key: (string) ($data['key'] ?? ''),
            dir: $dir === 'desc' ? 'desc' : 'asc',
        );
    }
}
