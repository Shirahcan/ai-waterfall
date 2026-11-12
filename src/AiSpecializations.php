<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;

/**
 * ai-service's specializations (1.64, owner 2026-11-11): named guides for one kind of output ("Blog post",
 * "Landing page") that tell the assistant the format, the sections and the data to use. The product's admins
 * own them as data; the product publishes the words they may use (its vocabulary) and seeds its starting
 * guides with {@see Laravel\Assistant\SpecializationsSyncCommand}.
 *
 * Every guide is read in one shape: `{key, name, use_when, surfaces, defaults_for, instructions,
 * sections: [{key, name, purpose, required, look_for}], data, checks: {min_words, max_words, require_cta,
 * require_sections}, host_fields: [{host, field}], status, version, updated_by, updated_at}`.
 *
 * Refusals throw `AiRequestRefusedException` with the service's sentence (a guide naming a word the
 * vocabulary does not hold says which word), so a console can hand the admin the real reason.
 */
class AiSpecializations
{
    public function __construct(private AiClient $client) {}

    /**
     * The product's guides and its vocabulary. `$activeOnly` for what a surface may use now.
     *
     * @return array{specializations: list<array<string, mixed>>, vocabulary: ?array<string, mixed>}
     */
    public function list(?string $surface = null, bool $activeOnly = false, bool $withTemplates = false): array
    {
        // 1.65: `$withTemplates` puts each guide's ACTIVE templates on it (`templates`), so one read serves both.
        $query = array_filter(['surface' => $surface, 'status' => $activeOnly ? 'active' : null, 'with' => $withTemplates ? 'templates' : null], static fn ($v) => $v !== null && $v !== '');
        $out = $this->client->getJson('api/v1/specializations', $query);

        return [
            'specializations' => array_values((array) ($out['specializations'] ?? [])),
            'vocabulary'      => is_array($out['vocabulary'] ?? null) ? $out['vocabulary'] : null,
        ];
    }

    /** @return array<string, mixed>|null null when the product has no guide by that key */
    public function get(string $key): ?array
    {
        try {
            return (array) ($this->client->getJson('api/v1/specializations/'.rawurlencode($key))['specialization'] ?? []);
        } catch (AiRequestRefusedException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Create the guide or change the fields given.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function put(string $key, array $fields): array
    {
        return (array) ($this->client->putJson('api/v1/specializations/'.rawurlencode($key), $fields)['specialization'] ?? []);
    }

    /**
     * The product's starting guide: created when absent, refreshed while it is still the one this seeder wrote
     * (same `updated_by`, nobody changed it since: 1.64.1), and an admin's edit never overwritten.
     *
     * @param  array<string, mixed>  $fields
     * @return array{specialization: array<string, mixed>, kept: bool, refreshed: bool, created: bool}
     */
    public function seed(string $key, array $fields): array
    {
        $out = $this->client->putJson('api/v1/specializations/'.rawurlencode($key), ['seed' => true] + $fields);

        return ['specialization' => (array) ($out['specialization'] ?? []), 'kept' => (bool) ($out['kept'] ?? false), 'refreshed' => (bool) ($out['refreshed'] ?? false), 'created' => (bool) ($out['created'] ?? false)];
    }

    public function delete(string $key): bool
    {
        try {
            return (bool) ($this->client->deleteJson('api/v1/specializations/'.rawurlencode($key))['deleted'] ?? false);
        } catch (AiRequestRefusedException $e) {
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * A guide's templates (1.65, owner 2026-11-12): the formats inside it ("Story", "How-to guide" under "Blog
     * post"), in the picker's order. Each: `{key, guide, name, group, use_when, example_titles, instructions,
     * sections: [{key, name, purpose, required, look_for, look}], design: {layout, signature, imagery}, checks
     * (only what it sets), data (null = the guide's), asks: [{question, required, options}], starter, reminder,
     * field_rules: {field: rule}, position, status, version, updated_by, updated_at}`.
     *
     * @return list<array<string, mixed>>
     */
    public function templates(string $guide, bool $activeOnly = false): array
    {
        $out = $this->client->getJson('api/v1/specializations/'.rawurlencode($guide).'/templates', $activeOnly ? ['status' => 'active'] : []);

        return array_values((array) ($out['templates'] ?? []));
    }

    /**
     * Create the template under its guide, or change the fields given.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function putTemplate(string $guide, string $key, array $fields): array
    {
        return (array) ($this->client->putJson($this->templatePath($guide, $key), $fields)['template'] ?? []);
    }

    /**
     * The product's starting template: the same rule as {@see seed()}, per template.
     *
     * @param  array<string, mixed>  $fields
     * @return array{template: array<string, mixed>, kept: bool, refreshed: bool, created: bool}
     */
    public function seedTemplate(string $guide, string $key, array $fields): array
    {
        $out = $this->client->putJson($this->templatePath($guide, $key), ['seed' => true] + $fields);

        return ['template' => (array) ($out['template'] ?? []), 'kept' => (bool) ($out['kept'] ?? false), 'refreshed' => (bool) ($out['refreshed'] ?? false), 'created' => (bool) ($out['created'] ?? false)];
    }

    public function deleteTemplate(string $guide, string $key): bool
    {
        try {
            return (bool) ($this->client->deleteJson($this->templatePath($guide, $key))['deleted'] ?? false);
        } catch (AiRequestRefusedException $e) {
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }
    }

    private function templatePath(string $guide, string $key): string
    {
        return 'api/v1/specializations/'.rawurlencode($guide).'/templates/'.rawurlencode($key);
    }

    /** @return array<string, mixed>|null */
    public function vocabulary(): ?array
    {
        $out = $this->client->getJson('api/v1/specializations-vocabulary');

        return is_array($out['vocabulary'] ?? null) ? $out['vocabulary'] : null;
    }

    /**
     * @param  array{surfaces: list<array{key: string, label: string}>, hosts: list<array<string, mixed>>, data: list<array<string, mixed>>}  $vocabulary
     * @return array<string, mixed>
     */
    public function publishVocabulary(array $vocabulary, ?string $by = null): array
    {
        return (array) ($this->client->putJson('api/v1/specializations-vocabulary', $vocabulary + ['updated_by' => $by])['vocabulary'] ?? []);
    }

    /**
     * A whole guide drafted from a description: a suggestion, never a save.
     *
     * @param  array<string, mixed>|null  $current  the guide being changed, if any
     * @return array{draft: array<string, mixed>, dropped: list<string>}
     */
    public function draft(string $description, ?array $current = null): array
    {
        $out = $this->client->postJson('api/v1/specializations/draft', array_filter(['description' => $description, 'current' => $current], static fn ($v) => $v !== null));

        return ['draft' => (array) ($out['draft'] ?? []), 'dropped' => array_values((array) ($out['dropped'] ?? []))];
    }
}
