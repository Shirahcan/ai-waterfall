<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * The starting TEMPLATES a product offers inside its guides (1.65, owner 2026-11-12: "blogs and landing pages might
 * have templates that will guide the content, design, and presentation"). Optional: a product's
 * {@see SpecializationCatalogue} implements it too, and `php artisan ai:specializations-sync` then seeds them.
 *
 * Templates are DATA its admins own, like guides: each is created when absent and refreshed only while it still
 * carries the sync's own `updated_by`, so a template an admin changed is never overwritten.
 */
interface TemplateCatalogue
{
    /**
     * guide key => template key => fields in the service's upsert shape (name, group, use_when, example_titles,
     * instructions, sections with `look`, design, checks, data, asks, starter, reminder, field_rules). Their order
     * is the picker's order.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function templates(): array;
}
