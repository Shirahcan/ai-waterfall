<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;

/**
 * Specializations in memory, for product tests (1.64). It refuses what the service refuses (no vocabulary,
 * a word the vocabulary does not hold, a second default guide for one kind of page and place), so a product's
 * test that passes here does not fail against the real service.
 */
class FakeAiSpecializations extends AiSpecializations
{
    /** @var array<string, array<string, mixed>> key => guide in the service's wire shape */
    public array $guides = [];

    /** @var array<string, mixed>|null */
    public ?array $vocabularyRow = null;

    /** What `draft()` answers; a test sets it. @var array{draft: array<string, mixed>, dropped: list<string>} */
    public array $draftAnswer = ['draft' => [], 'dropped' => []];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public function __construct()
    {
        // Deliberately no parent::__construct: there is no client and no network.
    }

    public function list(?string $surface = null, bool $activeOnly = false): array
    {
        $this->calls[] = ['list', [$surface, $activeOnly]];
        $rows = array_values(array_filter($this->guides, static fn (array $g) => ($surface === null || in_array($surface, $g['surfaces'], true))
            && (! $activeOnly || $g['status'] === 'active')));
        usort($rows, static fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['specializations' => $rows, 'vocabulary' => $this->vocabularyRow];
    }

    public function get(string $key): ?array
    {
        $this->calls[] = ['get', [$key]];

        return $this->guides[$key] ?? null;
    }

    public function put(string $key, array $fields): array
    {
        $this->calls[] = ['put', [$key, $fields]];

        return $this->write($key, $fields);
    }

    public function seed(string $key, array $fields): array
    {
        $this->calls[] = ['seed', [$key, $fields]];

        $existing = $this->guides[$key] ?? null;
        if ($existing !== null) {
            $seeder = $fields['updated_by'] ?? null;
            if ($seeder === null || $seeder === '' || ($existing['updated_by'] ?? null) !== $seeder) {
                return ['specialization' => $existing, 'kept' => true, 'refreshed' => false, 'created' => false];
            }
            $before = $existing;
            $after = $this->write($key, $fields);
            $words = ['name', 'use_when', 'surfaces', 'defaults_for', 'instructions', 'sections', 'data', 'checks', 'host_fields'];
            $changed = array_intersect_key($before, array_flip($words)) != array_intersect_key($after, array_flip($words));
            if (! $changed) {
                $this->guides[$key] = $before;
            }

            return ['specialization' => $this->guides[$key], 'kept' => false, 'refreshed' => $changed, 'created' => false];
        }

        return ['specialization' => $this->write($key, $fields), 'kept' => false, 'refreshed' => false, 'created' => true];
    }

    public function delete(string $key): bool
    {
        $this->calls[] = ['delete', [$key]];
        $had = isset($this->guides[$key]);
        unset($this->guides[$key]);

        return $had;
    }

    public function vocabulary(): ?array
    {
        return $this->vocabularyRow;
    }

    public function publishVocabulary(array $vocabulary, ?string $by = null): array
    {
        $this->calls[] = ['publishVocabulary', [$vocabulary, $by]];

        return $this->vocabularyRow = ['surfaces' => array_values($vocabulary['surfaces'] ?? []), 'hosts' => array_values($vocabulary['hosts'] ?? []), 'data' => array_values($vocabulary['data'] ?? [])];
    }

    public function draft(string $description, ?array $current = null): array
    {
        $this->calls[] = ['draft', [$description, $current]];

        return $this->draftAnswer;
    }

    /** @param  array<string, mixed>  $fields */
    private function write(string $key, array $fields): array
    {
        if ($this->vocabularyRow === null) {
            throw new AiRequestRefusedException('This product has not published what its specializations may use.', 422, 'no_vocabulary');
        }

        $before = $this->guides[$key] ?? null;
        if ($before === null && (! isset($fields['name']) || ! isset($fields['surfaces']))) {
            throw new AiRequestRefusedException('A new specialization needs its name and where it is used (surfaces).', 422, 'incomplete_specialization');
        }

        $keys = fn (string $kind) => array_column((array) ($this->vocabularyRow[$kind] ?? []), 'key');
        foreach ([['surfaces', 'surfaces'], ['defaults_for', 'hosts'], ['data', 'data']] as [$field, $kind]) {
            foreach ((array) ($fields[$field] ?? []) as $word) {
                if (! in_array($word, $keys($kind), true)) {
                    throw new AiRequestRefusedException("[{$word}] is not offered by this product.", 422, 'unknown_vocabulary');
                }
            }
        }

        $guide = array_replace($before ?? [
            'key' => $key, 'name' => '', 'use_when' => '', 'surfaces' => [], 'defaults_for' => [], 'instructions' => '',
            'sections' => [], 'data' => [], 'checks' => ['min_words' => null, 'max_words' => null, 'require_cta' => false, 'require_sections' => false],
            'host_fields' => [], 'status' => 'active', 'version' => 0, 'updated_by' => null, 'updated_at' => null,
        ], array_intersect_key($fields, array_flip(['name', 'use_when', 'surfaces', 'defaults_for', 'instructions', 'sections', 'data', 'checks', 'host_fields', 'status', 'updated_by'])));

        foreach ($this->guides as $other) {
            if ($other['key'] !== $key && $other['status'] === 'active' && $guide['status'] === 'active'
                && array_intersect($other['defaults_for'], $guide['defaults_for']) !== []
                && array_intersect($other['surfaces'], $guide['surfaces']) !== []) {
                throw new AiRequestRefusedException("\"{$other['name']}\" is already the default for that kind of page.", 422, 'default_taken');
            }
        }

        $guide['version'] = (int) $guide['version'] + 1;

        return $this->guides[$key] = $guide;
    }
}
