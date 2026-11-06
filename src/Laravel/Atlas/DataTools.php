<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryResultSet;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryTools;
use Throwable;

/**
 * The data engine's primitives as chat tools, so ai-service's ONE loop plans, runs and writes a data
 * answer, for every product (was Portify's `PorterDataTools`; moved into the kit, plan
 * mploynow-mira-parity P0d).
 *
 * A product's tool catalog offers these beside its own: `handles()` / `definitions()` / `run()`, with the
 * user resolved from the per-turn token by the product (never from the model's arguments).
 *
 * ⚠ IT REPLACES A LOOP INSIDE A LOOP. Portify's `ask_data_question` ran its own Sonnet tool loop (~40s)
 * inside the engine's loop, then a separate narrate call. Here the engine calls the same four primitives
 * directly: no second model, no second prompt in the product.
 *
 * ⚠ THE SECURITY BOUNDARY IS UNCHANGED. Every tool goes through {@see QueryTools}, so the same Atlas
 * audience filter, `PlanValidator` and `QueryExecutor` row scoping apply.
 */
final class DataTools
{
    public const SEARCH = 'search_data_schema';

    public const DESCRIBE = 'describe_data_entity';

    public const VALUES = 'list_data_values';

    public const QUERY = 'run_data_query';

    public const NAMES = [self::SEARCH, self::DESCRIBE, self::VALUES, self::QUERY];

    public function __construct(
        private QueryTools $tools,
        private AtlasRegistry $registry,
        private DataToolWords $words,
    ) {}

    public function handles(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /** @return list<array<string, mixed>> */
    public function definitions(?string $audience = null): array
    {
        $w = $this->words;

        return [
            [
                'name'        => self::SEARCH,
                'description' => 'Find the record types, fields and metrics that relate to a word in the user\'s question about '
                    ."THEIR OWN records ({$w->records}...). Search the SPECIFIC words ({$w->searchTerms}), not the record type. "
                    .'Start every data question here; never guess a key. '
                    ."It finds FIELD NAMES, never people: for one named {$w->namedThings} "
                    ."({$w->names}) use find_record, and never say someone does not exist because this found nothing. "
                    .'Results are already limited to what this user may see.'.$this->recordTypes($audience),
                'input_schema' => ['type' => 'object', 'properties' => [
                    'term' => ['type' => 'string', 'description' => 'One word or short phrase from the question.'],
                ], 'required' => ['term']],
            ],
            [
                'name'        => self::DESCRIBE,
                'description' => 'Every field and metric of one record type this user may query. Call it BEFORE telling the '
                    .'user something cannot be answered: "which has been waiting longest" is an ordinary date field.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'entity' => ['type' => 'string', 'description' => 'A record type key from search_data_schema.'],
                ], 'required' => ['entity']],
            ],
            [
                'name'        => self::VALUES,
                'description' => 'The real values a field holds in this user\'s rows. Use it before filtering on a real-world '
                    ."word ({$w->valueTerms}): fields are often free text with several spellings, so filter with \"in\" on "
                    .'every spelling that means the thing.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'entity'    => ['type' => 'string'],
                    'dimension' => ['type' => 'string', 'description' => 'A field key from describe_data_entity.'],
                ], 'required' => ['entity', 'dimension']],
            ],
            [
                'name'        => self::QUERY,
                'description' => 'Run a query over this user\'s records and see the rows. A table of the full result is shown '
                    .'UNDER your answer (say "below" if you mention it, never "above"). A plan is {"targets": [{"id", "entity", "select": [field keys], "metrics": [metric keys], '
                    .'"filters": [{"field", "op", "value"}], "group_by": [], "sort": [{"key", "dir"}], "limit", "offset": rows to skip}]}. '
                    .'A list reads ONE page of up to 20 rows. When the result says "has_more" and you are looking for one record, '
                    .'or the user wants more, run the same target again with its "next_offset" until you find it or "has_more" '
                    .'is false; never say a record is missing because it was not on the first page. '
                    .'op is one of = != > >= < <= in not_in between is_null not_null contains starts_with. '
                    .'PERIODS: for ANY period ("the last 25 minutes", "13 hours", "4 days", "today", "this week", "since Monday", two dates) '
                    .'add "window" to the target EXACTLY as the user said it: {"last": "25m"|"13h"|"4d"|"2w"|"3mo"}, '
                    .'{"calendar": "today"|"yesterday"|"this_week"|"last_week"|"this_month"|"last_month"|"this_quarter"|"this_year"|"next_week"}, '
                    .'{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"} or {"since": "YYYY-MM-DD"}. NEVER compute dates or times yourself and never '
                    .'put a period in "filters". The server picks the right date field (or pass "window_field") and returns the exact window as '
                    .'"window.label": say it in your answer. Add "compare": "previous" for the change against the period before (already computed: '
                    .'report it as given), and "every": "hour"|"day"|"week"|"month" with a window for a per-period series. '
                    .'LIST or COUNT, never both in one target: a list uses select (+ sort, limit), a count uses a metric '
                    .'(optionally group_by). "count" works on every record type; an amount uses the type\'s sum metric '
                    .'(describe_data_entity lists them). Never add up or count listed rows yourself: the rows you see are a '
                    .'sample. Sort only by a field you selected. A question with several parts is one target per part in '
                    .'ONE call, and every part gets answered. An error comes back to you, never to the user: read it and try another plan. '
                    .'Then answer from the rows ONLY: lead with the direct answer, name records the way a person recognises '
                    ."them ({$w->naming}), up to 8 one-line items then the total, copy dates exactly as given, and "
                    .'never add a cause, risk, deadline or prediction the rows do not show.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'plan' => ['type' => 'object', 'description' => 'The query plan.', 'properties' => [
                        'targets' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ], 'required' => ['targets']],
                ], 'required' => ['plan']],
            ],
        ];
    }

    /**
     * The record types this audience can query, named in the tool description itself.
     *
     * ⚠ THE MODEL ONLY LOOKS FOR WHAT IT KNOWS EXISTS. Asked "which background jobs failed and why?",
     * Porter answered from a summary fact and said it could not see the errors, three times running,
     * while a `failed_job` entity with every error sat one search away (2026-10-31). Listing the types
     * up front lets it reach for them. Access is still enforced per query.
     */
    private function recordTypes(?string $audience): string
    {
        if ($audience === null) {
            return '';
        }

        try {
            // In the asker's own words where the type has them ("case (roles)" for an employer, 1.48.0).
            $labels = array_map(
                static fn ($e) => "{$e->key} ({$e->pluralFor($audience)})",
                array_values(array_filter($this->registry->all(), static fn ($e) => $e->appliesToAudience($audience))),
            );
        } catch (Throwable $e) {
            report($e);

            return '';
        }

        return $labels === [] ? '' : ' Record types you can query: '.implode(', ', $labels).'. '
            .$this->words->noteFor($labels)
            .'For any question about these, query the data rather than answering from a summary fact, and call '
            .'describe_data_entity first for the exact field keys: never guess one.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, result?: mixed, error?: string, card?: array<string, mixed>}
     */
    public function run(string $name, array $input, Authenticatable $user, string $audience): array
    {
        try {
            return match ($name) {
                self::SEARCH   => $this->observed($this->tools->searchSchema((string) ($input['term'] ?? ''), $audience, $user)),
                self::DESCRIBE => $this->observed($this->tools->describeEntity((string) ($input['entity'] ?? ''), $audience, $user)),
                self::VALUES   => $this->observed($this->tools->listValues((string) ($input['entity'] ?? ''),
                    (string) ($input['dimension'] ?? ''), $audience, $user)),
                self::QUERY    => $this->query(is_array($input['plan'] ?? null) ? $input['plan'] : [], $audience, $user),
                default        => ['ok' => false, 'error' => 'No such tool.'],
            };
        } catch (Throwable $e) {
            report($e);

            // A tool fault is an observation: the model can try another route.
            return ['ok' => false, 'error' => 'That step failed. Try a simpler plan, or say you could not read it.'];
        }
    }

    /** @param  array<string, mixed>  $observation */
    private function observed(array $observation): array
    {
        return isset($observation['error'])
            ? ['ok' => false, 'error' => (string) $observation['error'], 'result' => $observation]
            : ['ok' => true, 'result' => $observation];
    }

    /** @param  array<string, mixed>  $plan */
    private function query(array $plan, string $audience, Authenticatable $user): array
    {
        $run = $this->tools->runQuery($plan, $audience, $user);
        $result = $run['result'];

        if (! $result instanceof QueryResultSet) {
            return ['ok' => false, 'error' => (string) ($run['preview']['error'] ?? 'That plan did not run.'), 'result' => $run['preview']];
        }

        return [
            'ok'     => true,
            'result' => $run['preview'],
            // The rows are carried in the card; the prose is the model's.
            'card'   => ['type' => count($result->targets) > 1 ? 'data_tables' : 'data_table', 'payload' => [
                'content' => '',
                'meta'    => ['result' => $result->toArray(), 'plan' => $plan, 'notes' => [], 'narrated' => true, 'engine' => true],
            ]],
        ];
    }
}
