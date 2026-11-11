<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * What a product offers its specializations (1.64): the words a guide may use, and the guides it starts with.
 * Named in `ai-client.specializations.catalogue`; `php artisan ai:specializations-sync` publishes both.
 *
 * Only the product knows what it can fetch and fill, so the vocabulary is CODE (a data source exists when the
 * product's reader for it does) while the guides are DATA its admins own (seeded once, never overwritten).
 */
interface SpecializationCatalogue
{
    /**
     * @return array{
     *     surfaces: list<array{key: string, label: string}>,
     *     hosts: list<array{key: string, label: string, fields: list<array{key: string, label: string, max?: int}>}>,
     *     data: list<array{key: string, label: string, description?: string}>
     * }
     */
    public function vocabulary(): array;

    /**
     * The starting guides by key, each in the service's upsert shape (name, use_when, surfaces, defaults_for,
     * instructions, sections, data, checks, host_fields).
     *
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array;
}
