<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;

/**
 * Which records a NAME belongs to, across every record type the asker can query (`search_records`).
 *
 * ⚠ THE MODEL GUESSED THE TYPE, AND A GUESS THAT MISSES READS AS "IT DOES NOT EXIST". Asked "why did my
 * blog post 'Work Permit After Positive LMIA' lose its sections?", Porter queried one record type, got no
 * rows and said it could not find the post (Portify local, 2026-11-20). `search_data_schema` finds FIELD
 * names and `find_record` knows only a product's people and cases, so nothing could say WHERE a title lives.
 * This searches the fields that NAME a row ({@see AtlasEntity::$identityKeys}, else its primary label) on
 * every type, through the ordinary {@see QueryTools::runQuery()}: the same audience filter, row scopes and
 * query audit as any other read. It never widens what a person may see.
 *
 * Every significant word must appear (in any order), so "Work Permit After LMIA" finds "Canada Work Permit
 * After LMIA". A title worded differently ("positive") finds nothing, and the result says to try fewer words.
 */
final class RecordSearch
{
    /**
     * How long one search may run before it stops and names the types it did not reach. A product calls this
     * through its tool endpoint, which the chat engine gives {@see DataTools::RECORDS_TIMEOUT} seconds.
     *
     * ⚠ PORTIFY HAS 104 SEARCHABLE TYPES, and a few are slow (its conversations ~2 s, meetings ~1.4 s, locally
     * 2026-11-24); the whole sweep took ~7 s on a laptop and more under load, past the engine's 10 s default,
     * so the first live call came back "Could not reach that information just now". A fixed type cap was
     * worse: it silently never searched the rest.
     */
    public const BUDGET_SECONDS = 18.0;

    /** Rows shown per type: enough to tell which one was meant, never a list to read from. */
    public const ROWS = 5;

    /** Words too common to narrow a title. */
    private const STOP_WORDS = ['a', 'an', 'the', 'my', 'our', 'your', 'of', 'for', 'to', 'and', 'in', 'on', 'at', 'with', 'about'];

    public function __construct(
        private AtlasRegistry $registry,
        private QueryTools $tools,
    ) {}

    /** @return array<string, mixed> */
    public function search(string $words, string $audience, Authenticatable $user): array
    {
        $terms = self::terms($words);
        if ($terms === []) {
            return ['error' => 'Give the name or title to look for (at least two letters).'];
        }

        $matches = [];
        $lookedIn = [];
        $unread = [];
        $notReached = [];
        $started = microtime(true);
        foreach ($this->searchable($audience, $user) as [$entity, $keys]) {
            /** @var AtlasEntity $entity */
            $plural = $entity->pluralFor($audience);
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                $notReached[] = $plural;

                continue;
            }
            $lookedIn[] = $plural;
            // "my blog post Spring Launch": the type's own name is not part of the title.
            $own = self::withoutTypeWords($terms, $entity, $audience);
            if ($own === []) {
                continue;
            }

            $targets = [];
            foreach ($keys as $i => $key) {
                $targets[] = [
                    'id' => 'name'.$i,
                    'entity' => $entity->key,
                    'select' => $keys,
                    'filters' => array_map(static fn (string $term) => ['field' => $key, 'op' => 'contains', 'value' => $term], $own),
                    'limit' => self::ROWS,
                ];
            }

            $run = $this->tools->runQuery(['targets' => $targets], $audience, $user);
            $found = self::found((array) ($run['preview']['targets'] ?? []));
            if (isset($run['preview']['error']) || $found['unread']) {
                $unread[] = $plural;
            }
            if ($found['rows'] !== []) {
                $matches[] = [
                    'entity' => $entity->key,
                    'record_type' => $entity->labelFor($audience),
                    'total' => $found['total'],
                    'rows' => array_slice($found['rows'], 0, self::ROWS),
                ];
            }
        }

        usort($matches, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return array_filter([
            'searched_for' => implode(' ', $terms),
            'matches' => $matches,
            'looked_in' => $lookedIn,
            'could_not_read' => $unread ?: null,
            'not_searched' => $notReached !== []
                ? 'Out of time before these: '.implode(', ', $notReached).'. If the thing is one of these, query that type by its name field.'
                : null,
            'next' => $matches !== []
                ? 'Read the match the person means with run_data_query (filter its name field) or describe_data_entity, then answer.'
                : null,
            'none_found' => $matches === []
                ? 'No record you can query has all of these words in its name or title. Before you say it does not exist, '
                    .'search again with fewer or different words from the name (a person may misremember a title).'
                : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Each searchable type with the string fields that name its rows, as this person may read them.
     *
     * @return list<array{0: AtlasEntity, 1: list<string>}>
     */
    private function searchable(string $audience, Authenticatable $user): array
    {
        $visible = [];
        foreach ($this->registry->describeForAudience($audience, $user) as $described) {
            foreach ((array) ($described['dimensions'] ?? []) as $d) {
                if (($d['type'] ?? null) === 'string' && ($d['filterable'] ?? false)) {
                    $visible[$described['key']][] = $d['key'];
                }
            }
        }

        // Types that declare what names a row first: a person names THOSE ("my post", "the Acme role").
        $named = [];
        $labelled = [];
        foreach ($this->registry->forAudience($audience, $user) as $entity) {
            $own = $entity->identityKeys !== [] ? $entity->identityKeys : array_filter([$entity->primaryLabelDimension()?->key]);
            $keys = array_values(array_intersect($own, $visible[$entity->key] ?? []));
            if ($keys !== []) {
                if ($entity->identityKeys !== []) {
                    $named[] = [$entity, $keys];
                } else {
                    $labelled[] = [$entity, $keys];
                }
            }
        }

        return [...$named, ...$labelled];
    }

    /**
     * The rows the targets found, one copy of each, and the largest total (the targets overlap).
     *
     * @param  list<array<string, mixed>>  $targets  `runQuery()` preview targets
     * @return array{rows: list<array<string, mixed>>, total: int, unread: bool}
     */
    private static function found(array $targets): array
    {
        $rows = [];
        $total = 0;
        $unread = false;
        foreach ($targets as $target) {
            if (isset($target['could_not_read'])) {
                $unread = true;

                continue;
            }
            $total = max($total, (int) ($target['total_matching'] ?? 0));
            foreach ((array) ($target['rows'] ?? []) as $row) {
                $rows[(string) json_encode($row)] = $row;
            }
        }

        return ['rows' => array_values($rows), 'total' => max($total, count($rows)), 'unread' => $unread];
    }

    /**
     * The words left once this type's own names (its key, label, plural and the asker's aliases) are taken out.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private static function withoutTypeWords(array $terms, AtlasEntity $entity, string $audience): array
    {
        $names = implode(' ', [$entity->key, $entity->label, $entity->plural, $entity->labelFor($audience),
            $entity->pluralFor($audience), ...$entity->aliasesFor($audience)]);
        $typeWords = self::terms(str_replace('_', ' ', $names));

        return array_values(array_diff($terms, $typeWords));
    }

    /** @return list<string> */
    private static function terms(string $words): array
    {
        $parts = preg_split('/[\s,;:"\'()\[\]]+/u', mb_strtolower(trim($words))) ?: [];
        $terms = array_values(array_unique(array_filter(
            $parts,
            static fn (string $p) => mb_strlen($p) >= 2 && ! in_array($p, self::STOP_WORDS, true),
        )));

        return array_slice($terms, 0, 8);
    }
}
