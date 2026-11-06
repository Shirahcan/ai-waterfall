<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryPlan;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\TargetResult;
use Shirahcan\AiClient\Time\TimeWindowResolver;
use Shirahcan\AiClient\Time\ViewerTime;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What Porter can DO while working out a data question: look the schema up,
 * sample real values, and run a query.
 *
 * ⚠ THE SECURITY BOUNDARY IS UNCHANGED, AND THAT IS THE WHOLE POINT. Every tool
 * here goes through the SAME {@see AtlasRegistry} audience filter and the SAME
 * {@see PlanValidator} + {@see QueryExecutor} row-scoping that the single-shot
 * planner used. This changes how a plan is ARRIVED AT, never what a role may
 * see. A consultant cannot reach another consultant's rows through any of it.
 *
 * ⚠ WHY TOOLS RATHER THAN A BIGGER PROMPT. The planner was told, in fourteen
 * hard rules, how to use a catalog it could not inspect: "prefer a dimension
 * that means active", "case_type values are X". Every unanticipated question
 * bought another rule. A model that can LOOK - search the schema, sample the
 * values - needs none of them, because the answer to "which key means active?"
 * is a lookup, not a lesson.
 *
 * It is also cheaper. The old planner shipped the ENTIRE catalog on every turn
 * (10 entities, 81 dimensions, ~7,800 tokens) whatever the question was. These
 * tools return only what was asked for.
 */
class QueryTools
{
    /** Distinct values returned by list_values. Enough to choose from, not a dump. */
    private const MAX_VALUES = 40;

    /** Rows handed back to the model. It needs the shape, not the whole page. */
    private const MAX_PREVIEW_ROWS = 20;

    /**
     * Rows one LIST read fetches from the database (owner, 2026-11-02: "Don't make everything to be
     * fetched at once"). The result says `has_more` and `next_offset`, and the model asks for the
     * next page only when it needs one. Counts and grouped metrics are not paged: they are one row
     * per group, and a total is counted separately before the limit.
     */
    public const PAGE_SIZE = self::MAX_PREVIEW_ROWS;

    public function __construct(
        private AtlasRegistry $registry,
        private PlanValidator $validator,
        private QueryExecutor $executor,
        private ?QueryAudit $audit = null,
    ) {}

    /**
     * Find the entities, dimensions and metrics that relate to a word.
     *
     * This is what replaces "PREFER A DIMENSION THAT ALREADY EXPRESSES THE
     * USER'S CONCEPT": the model searches for "active" and is handed
     * `case.is_active` with its description, instead of being told about it in
     * advance and hoping the rule fires.
     *
     * @return array<string,mixed>
     */
    public function searchSchema(string $term, string $audience, Authenticatable $user): array
    {
        $needle = mb_strtolower(trim($term));

        if ($needle === '') {
            return ['matches' => [], 'note' => 'Give a word to search for.'];
        }

        $matches = [];

        foreach ($this->registry->describeForAudience($audience, $user) as $entity) {
            $hit = ['entity' => $entity['key'], 'label' => $entity['label']];
            $found = false;
            $aliases = (array) ($entity['also_called'] ?? []);

            // The sample questions count: they are written in the words people actually use.
            if ($this->mentions($needle, [$entity['key'], $entity['label'], $entity['plural'] ?? '',
                implode(' ', $entity['sample_questions'] ?? []), implode(' ', $aliases)])) {
                $hit['entity_matched'] = true;
                $found = true;
            }

            /*
             * ⚠ "ROLE OPENED" IS A TYPE WORD PLUS A FIELD WORD. Every word must sit in ONE haystack, so a
             * phrase naming the type in the asker's words ("role") plus a field ("opened") matched nothing.
             * When the phrase uses one of THIS audience's names for the type, the rest of the phrase is
             * looked for in its fields (1.48.0). A type with no audience names searches exactly as before.
             */
            $fieldNeedle = $aliases === [] ? $needle : $this->withoutWords($needle, $aliases);
            if ($fieldNeedle !== $needle) {
                $hit['entity_matched'] = true;
                $found = true;
            }

            $dimensions = [];
            foreach ($fieldNeedle === '' ? [] : $entity['dimensions'] as $dimension) {
                if (! $this->mentions($fieldNeedle, [
                    $dimension['key'],
                    $dimension['label'] ?? '',
                    $dimension['description'] ?? '',
                ])) {
                    continue;
                }

                $dimensions[] = array_filter([
                    'key'         => $dimension['key'],
                    'label'       => $dimension['label'] ?? null,
                    'type'        => $dimension['type'] ?? null,
                    'description' => $dimension['description'] ?? null,
                    'filterable'  => $dimension['filterable'] ?? null,
                    'groupable'   => $dimension['groupable'] ?? null,
                ], static fn ($v) => $v !== null);

                $found = true;
            }

            $metrics = [];
            foreach ($fieldNeedle === '' ? [] : $entity['metrics'] as $metric) {
                if ($this->mentions($fieldNeedle, [$metric['key'], $metric['label'] ?? ''])) {
                    $metrics[] = ['key' => $metric['key'], 'label' => $metric['label'] ?? null];
                    $found = true;
                }
            }

            if (! $found) {
                continue;
            }

            if ($dimensions !== []) {
                $hit['dimensions'] = $dimensions;
            }

            if ($metrics !== []) {
                $hit['metrics'] = $metrics;
            }

            /*
             * ⚠ A TYPE FOUND BY NAME MUST SHOW WHAT CAN BE ASKED OF IT. Searching "roles" matched the type and
             * returned no field, so the model never saw `opened_at`, went back to the summary and told an
             * employer the platform does not hold the date (Portify local, 2026-11-06).
             */
            if (($hit['entity_matched'] ?? false) && $dimensions === [] && $metrics === []) {
                $hit += $this->fieldsToTry($entity);
            }

            $matches[] = $hit;
        }

        return $matches === []
            ? ['matches' => [], 'note' => "Nothing matches \"{$term}\". Try describe_entity on a likely record type."]
            : ['matches' => $matches];
    }

    /**
     * The full shape of one entity: every dimension and metric the asker may use.
     *
     * @return array<string,mixed>
     */
    public function describeEntity(string $entityKey, string $audience, Authenticatable $user): array
    {
        foreach ($this->registry->describeForAudience($audience, $user) as $entity) {
            if ($entity['key'] === $entityKey) {
                return $entity;
            }
        }

        $available = array_map(
            static fn ($e) => $e['key'],
            $this->registry->describeForAudience($audience, $user),
        );

        return [
            'error'     => "No record type \"{$entityKey}\" is available to you.",
            'available' => $available,
        ];
    }

    /**
     * The real values a column holds, row-scoped to the asker.
     *
     * ⚠ THIS IS THE TOOL THAT WOULD HAVE PREVENTED THE WORST ANSWER OF THE DAY.
     * `case_type` is free text with no constants behind it, so the planner could
     * not express "LMIA", reached for an entity it was not entitled to, and told
     * a consultant "You do not have access to that information here" about their
     * OWN cases. A model that can sample the column just sees "LMIA".
     *
     * @return array<string,mixed>
     */
    public function listValues(string $entityKey, string $dimensionKey, string $audience, Authenticatable $user): array
    {
        // Run it as a real grouped query so the SAME row scoping applies: a
        // consultant samples only values present in their own rows.
        $plan = QueryPlan::fromArray($this->withCountAlias(['targets' => [[
            'id' => 't1', 'entity' => $entityKey,
            'select' => [], 'metrics' => ['count'], 'filters' => [],
            'group_by' => [$dimensionKey], 'sort' => [], 'limit' => self::MAX_VALUES,
        ]], 'presentation' => 'auto']));

        $validated = $this->validator->validate($plan, $audience, $user);

        if ($validated->plan === null) {
            // The count metric key differs per entity; fall back to a bare grouping.
            $plan = QueryPlan::fromArray(['targets' => [[
                'id' => 't1', 'entity' => $entityKey,
                'select' => [], 'metrics' => [], 'filters' => [],
                'group_by' => [$dimensionKey], 'sort' => [], 'limit' => self::MAX_VALUES,
            ]], 'presentation' => 'auto']);

            $validated = $this->validator->validate($plan, $audience, $user);
        }

        if ($validated->plan === null) {
            return ['error' => $validated->clarification ?? "Cannot group {$entityKey} by {$dimensionKey}."];
        }

        try {
            $rows = $this->executor->execute($validated->plan, $audience, $user)->targets[0]->rows;
        } catch (Throwable $e) {
            return ['error' => 'Could not read those values: '.$e->getMessage()];
        }

        $values = [];

        foreach ($rows as $row) {
            $value = $row[$dimensionKey] ?? null;

            if ($value !== null && $value !== '') {
                $values[] = $value;
            }
        }

        return [
            'entity'    => $entityKey,
            'dimension' => $dimensionKey,
            'values'    => array_values(array_unique($values)),
        ];
    }

    /**
     * Execute a plan and hand back a preview plus the real result set.
     *
     * The QueryResultSet is returned alongside the preview because the CALLER
     * keeps it: the final answer is narrated from the real rows, and the
     * "Show the data" table renders from them. The preview exists only so the
     * model can see whether its plan did what it meant.
     *
     * @return array{preview:array<string,mixed>,result:?\Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryResultSet}
     */
    public function runQuery(array $rawPlan, string $audience, Authenticatable $user): array
    {
        // Periods said in words become exact windows on the right date field (porter-stats-reach 02b-02d).
        // Built from THIS registry, never the container's: a period must be read against the same
        // catalog the validator checks, or a product's registry and the kit's default disagree.
        $windowed = (new WindowedPlan($this->registry, new TimeWindowResolver()))->prepare($rawPlan, ViewerTime::viewerZone(), now());
        if ($windowed['errors'] !== []) {
            return ['preview' => [
                'error' => implode(' ', $windowed['errors']),
                'hint'  => 'Fix the period and run the plan again: pass `window` the way the user said it, never computed dates.',
            ], 'result' => null];
        }
        // The compare / bucket re-runs reuse these targets, so they carry the resolved metric keys too.
        $windowed['plan'] = $this->withCountAlias($windowed['plan']);
        $rawPlan = $windowed['plan'];

        try {
            $plan = QueryPlan::fromArray($this->asPages($this->withCountAlias($rawPlan)));
        } catch (Throwable $e) {
            return ['preview' => ['error' => 'That plan is not valid: '.$e->getMessage()], 'result' => null];
        }

        if ($plan->targets === []) {
            return ['preview' => ['error' => 'The plan had no targets.'], 'result' => null];
        }

        $started = microtime(true);
        $validated = $this->validator->validate($plan, $audience, $user);

        if ($validated->plan === null) {
            $this->audit($user, $audience, $rawPlan, 'rejected', 0, $started, $validated->clarification ?? 'not allowed');
            /*
             * ⚠ THE REFUSAL GOES BACK TO THE MODEL, NOT TO THE USER. In the
             * single-shot design a validator refusal WAS the answer, which is
             * how "You do not have access to that information here" reached a
             * consultant asking about their own cases. Here it is feedback: the
             * model can search the schema and try a plan it is actually
             * entitled to run.
             */
            return [
                'preview' => [
                    'error' => $validated->clarification ?? 'That plan is not allowed for you.',
                    'hint'  => 'Use search_schema or describe_entity to find something you can query.',
                    // A guessed key ("due_date", "exception") reads like a refusal; the real keys let the
                    // model correct the plan instead of telling the user the data is off limits.
                    'valid_fields' => $this->validFields($plan, $audience, $user),
                    // An unknown metric came back with only FIELD keys, so Porter concluded "the count
                    // function isn't available" and summed rows instead (2026-11-01).
                    'valid_metrics' => $this->validMetrics($plan, $audience, $user),
                ],
                'result' => null,
            ];
        }

        try {
            $resultSet = $this->executor->execute($validated->plan, $audience, $user);
        } catch (Throwable $e) {
            report($e);
            $this->audit($user, $audience, $rawPlan, 'failed', 0, $started, class_basename($e));

            return ['preview' => ['error' => 'That query failed: '.$e->getMessage()], 'result' => null];
        }
        $unread = array_filter($resultSet->targets, static fn ($t) => $t->status === TargetResult::STATUS_UNAVAILABLE);
        $this->audit($user, $audience, $rawPlan, $unread === [] ? 'ok' : 'partial', array_sum(array_map(static fn ($t) => count($t->rows), $resultSet->targets)), $started);

        $preview = ['targets' => []];
        $asked = [];
        $plannedOffset = [];
        foreach ($validated->plan->targets as $planned) {
            $asked[$planned->id] = $planned->metrics;
            $plannedOffset[$planned->id] = $planned->offset;
        }

        foreach ($resultSet->targets as $target) {
            /*
             * ⚠ A TARGET THAT FAILED IS NOT AN EMPTY ONE. The builder turns any exception into an
             * `unavailable` target with no rows, and this loop used to read it like any other: no rows,
             * total 0, so "None exist that match. Say so plainly." Asked which client had the most
             * cases, Porter told the owner "No client on the platform has more than one case" while
             * the grouping had in fact been refused by the database (prod, 2026-11-06 00:01:49).
             */
            if ($target->status === TargetResult::STATUS_UNAVAILABLE) {
                $preview['targets'][] = array_filter([
                    'id'             => $target->id,
                    'entity'         => $target->entity,
                    'asked'          => $target->interpretation !== '' ? $target->interpretation : null,
                    'could_not_read' => 'This part could not be read just now. Tell the user you could not read it. '
                        .'Never say none exist and never give a number for it; try another plan if one would answer the question.',
                    'notes'          => $target->notes ?: null,
                ], static fn ($v) => $v !== null);

                continue;
            }

            $shown = min(count($target->rows), self::MAX_PREVIEW_ROWS);
            $from = $plannedOffset[$target->id] ?? 0;
            $hasMore = $target->total !== null && $target->total > $from + $shown;
            $preview['targets'][] = array_filter([
                'id'             => $target->id,
                'entity'         => $target->entity,
                'total_matching' => $target->total,
                'rows_returned'  => count($target->rows),
                /*
                 * ⚠ NOTHING MATCHING IS AN ANSWER. Asked for expired task links on a box that had none,
                 * Porter said "I can't confirm whether any exist" and sent the admin to "the task links
                 * list on the Cases screen", which does not exist (test box, 2026-11-04).
                 */
                'empty_means'    => $target->rows === [] && ($target->total ?? 0) === 0
                    ? 'None exist that match, among the records this person can see. Say so plainly. Do not suggest a screen to check unless you know one.'
                    : null,
                // The exact period counted, in the asker's zone: the answer says it.
                'window'         => $windowed['windows'][$target->id] ?? null,
                'rows'           => $this->readableRows($target->rows, $target->entity),
                'notes'          => $validated->notes ?: null,
                /*
                 * ⚠ A PARTIAL LIST IS NOT A TOTAL. Asked how much gov-fee money was held, Porter
                 * listed the fees, added up the 10 preview rows of 11 and said $1,905 against a real
                 * $2,500 (test box, 2026-11-01). Say so on the rows themselves.
                 */
                /*
                 * ⚠ A PARTIAL LIST CAN BE READ ON (2026-11-02). "There are 30 matching invitations, and
                 * none of the first 10 shown are for that name": Porter could not look past the preview.
                 * The warning now says where this page starts and how to ask for the next one.
                 */
                'rows_from'      => $from + 1,
                'has_more'       => $hasMore,
                'next_offset'    => $hasMore ? $from + $shown : null,
                'warning'        => $hasMore
                    ? sprintf('Rows %d to %d of %d. To read on (finding one record among many), run the same target again '
                        .'with "offset": %d. Never add up or count these rows yourself: run a metric for any total.',
                        $from + 1, $from + $shown, $target->total, $from + $shown)
                    : null,
                'money_totals'   => $this->moneyTotalsNotAsked($target->entity, $asked[$target->id] ?? []),
            ], static fn ($v) => $v !== null);
        }

        // The period before and the per-bucket series, counted by the engine, never by the model.
        if ($windowed['previous'] !== [] || $windowed['buckets'] !== []) {
            $resultSet = (new WindowedResults($this->validator, $this->executor))
                ->enrich($preview, $resultSet, $windowed, $audience, $user);
        }

        return ['preview' => $preview, 'result' => $resultSet];
    }

    /**
     * Preview rows with dates already written the way a person says them.
     *
     * ⚠ THE SAME FORMATTER THE NARRATOR USES, DELIBERATELY. The agent writes
     * its own final answer from these rows, so if they carry a raw timestamp
     * that is what the consultant reads - and the agent's first working run did
     * exactly that, printing "(updated 2026-09-22 16:32:43)" in a reply where
     * the planner path said "Tue, Sep 22, 2026 at 4:32 PM". Two routes to one
     * answer must not disagree about how a date looks.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function readableRows(array $rows, ?string $entityKey = null): array
    {
        $out = [];

        /*
         * ⚠ A CODED VALUE IS THE SYSTEM'S WORD. Porter answered "fees with status \"held\"" because
         * the row carried the stored key; the screen says "Still held". The label the dimension
         * already declares is what the model reads (2026-11-01).
         */
        $labels = [];
        foreach (($entityKey !== null ? $this->registry->get($entityKey)?->dimensions : null) ?? [] as $dim) {
            if (is_array($dim->valueLabels) && $dim->valueLabels !== []) {
                $labels[$dim->key] = $dim->valueLabels;
            }
        }

        foreach (array_slice($rows, 0, self::MAX_PREVIEW_ROWS) as $row) {
            foreach ($row as $key => $value) {
                if (isset($labels[$key]) && (is_string($value) || is_int($value)) && isset($labels[$key][$value])) {
                    $row[$key] = $labels[$key][$value];

                    continue;
                }

                $human = ViewerTime::human($value);

                if ($human !== null) {
                    $row[$key] = $human;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /** @param list<string> $haystacks */
    /**
     * The real field keys of each entity a plan names, for the model to correct a guessed key.
     *
     * @return array<string, list<string>>
     */
    /**
     * A count was run on a type that also has money totals: name them, so "how much" gets a figure.
     *
     * ⚠ Asked "how much government fee money are we still holding", Porter counted the fees (11),
     * then told the admin it "can't give you the total held from this result" while `held_total` sat
     * one call away (test box, 2026-11-02).
     */
    private function moneyTotalsNotAsked(string $entityKey, array $asked): ?string
    {
        $entity = $this->registry->get($entityKey);
        if ($entity === null || $asked === []) {
            return null;
        }
        foreach ($asked as $key) {
            if ($entity->metric($key)?->format === 'money') {
                return null;
            }
        }
        $money = array_values(array_filter($entity->metrics, static fn ($m) => $m->format === 'money'));
        if ($money === []) {
            return null;
        }

        return 'This counted rows. For an amount ("how much"), run the money total: '
            .implode(', ', array_map(static fn ($m) => "{$m->key} ({$m->label})", $money)).'.';
    }

    private function validMetrics(QueryPlan $plan, string $audience, Authenticatable $user): array
    {
        $out = [];
        foreach ($plan->targets as $target) {
            $described = $this->describeEntity($target->entity, $audience, $user);
            if (isset($described['metrics'])) {
                $out[$target->entity] = array_column($described['metrics'], 'key');
            }
        }

        return $out;
    }

    /**
     * A LIST target reads one page ({@see PAGE_SIZE}); the model asks for the next with `offset`.
     *
     * @param  array<string,mixed>  $rawPlan
     * @return array<string,mixed>
     */
    private function asPages(array $rawPlan): array
    {
        foreach (($rawPlan['targets'] ?? []) as $i => $target) {
            if (! is_array($target) || ! empty($target['metrics']) || ! empty($target['link'])) {
                continue;
            }
            $rawPlan['targets'][$i]['limit'] = max(1, min((int) ($target['limit'] ?? self::PAGE_SIZE), self::PAGE_SIZE));

            // A listed row always carries what names it (AtlasEntity::$identityKeys), so the answer can say WHO.
            $entity = $this->registry->get((string) ($target['entity'] ?? ''));
            if ($entity !== null && $entity->identityKeys !== [] && empty($target['group_by'])) {
                $select = array_values(array_filter((array) ($target['select'] ?? []), 'is_string'));
                $rawPlan['targets'][$i]['select'] = array_values(array_unique(array_merge($entity->identityKeys, $select)));
            }
        }

        return $rawPlan;
    }

    /**
     * "count" works on every record type: it is rewritten to that type's own count metric.
     *
     * Every entity names its count differently (`case_count`, `subscriber_count`, `partner_count`),
     * and the model's first guess is always plain `count`. Refusing that guess is how a three-part
     * question lost its third part.
     *
     * @param  array<string,mixed>  $rawPlan
     * @return array<string,mixed>
     */
    private function withCountAlias(array $rawPlan): array
    {
        foreach (($rawPlan['targets'] ?? []) as $i => $target) {
            if (! is_array($target) || ! is_array($target['metrics'] ?? null)) {
                continue;
            }
            $entity = $this->registry->get((string) ($target['entity'] ?? ''));
            if ($entity === null) {
                continue;
            }
            $rawPlan['targets'][$i]['metrics'] = array_values(array_map(function ($key) use ($entity) {
                if (! is_string($key) || $entity->metric($key) !== null || ! in_array($key, ['count', 'row_count', 'total_count'], true)) {
                    return $key;
                }
                foreach ($entity->metrics as $metric) {
                    if ($metric->kind === 'count') {
                        return $metric->key;
                    }
                }

                return $key;
            }, $target['metrics']));
        }

        return $rawPlan;
    }

    private function validFields(QueryPlan $plan, string $audience, Authenticatable $user): array
    {
        $out = [];
        foreach ($plan->targets as $target) {
            $described = $this->describeEntity($target->entity, $audience, $user);
            if (isset($described['dimensions'])) {
                $out[$target->entity] = array_column($described['dimensions'], 'key');
            }
        }

        return $out;
    }

    /**
     * Does any haystack mention the term?
     *
     * ⚠ WORD BY WORD, NOT ONE SUBSTRING. Matching the whole phrase meant "IRCC letters" found nothing
     * beside an entity labelled "Case letter" whose questions say "IRCC letters", and "errors" missed
     * "Error cluster" (2026-10-30): Porter then told an admin the record type did not exist. Every word
     * of the term (3+ letters, a plural 's' ignored) must appear in the same haystack.
     */
    /**
     * The fields most questions about a type need (when, and by what), keys and labels only, so a search
     * that found the type by name is enough to write the query.
     *
     * @param  array<string, mixed>  $entity  {@see AtlasRegistry::describeForAudience()}
     * @return array<string, mixed>
     */
    private function fieldsToTry(array $entity): array
    {
        $named = static fn (array $d) => $d['key'].(isset($d['label']) ? " ({$d['label']})" : '');
        $dimensions = (array) ($entity['dimensions'] ?? []);
        $dates = array_filter($dimensions, static fn (array $d) => in_array($d['type'] ?? '', ['date', 'datetime'], true));
        $groups = array_filter($dimensions, static fn (array $d) => ($d['groupable'] ?? false) === true);

        return array_filter([
            'date_fields'  => array_values(array_map($named, array_slice($dates, 0, 8))),
            'group_fields' => array_values(array_map($named, array_slice($groups, 0, 12))),
            'next'         => $dimensions === [] ? null : 'describe_data_entity lists every field of this type.',
        ], static fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * $needle with every word that is one of $names removed ("role opened" without ["role", "roles"] is
     * "opened"); singular and plural compare equal. Unchanged when no word is a name.
     *
     * @param  list<string>  $names  lower-case
     */
    private function withoutWords(string $needle, array $names): string
    {
        $stem = static fn (string $w) => strlen($w) > 3 ? rtrim($w, 's') : $w;
        $named = array_map($stem, $names);
        $words = preg_split('/[^a-z0-9_]+/', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_filter($words, static fn (string $w) => ! in_array($stem($w), $named, true)));

        return count($kept) === count($words) ? $needle : implode(' ', $kept);
    }

    private function mentions(string $needle, array $haystacks): bool
    {
        $words = array_values(array_filter(
            array_map(static fn (string $w) => strlen($w) > 3 ? rtrim($w, 's') : $w, preg_split('/[^a-z0-9_]+/', $needle) ?: []),
            static fn (string $w) => strlen($w) >= 3,
        ));

        foreach ($haystacks as $haystack) {
            $hay = mb_strtolower((string) $haystack);
            if ($hay === '') {
                continue;
            }
            if (str_contains($hay, $needle)) {
                return true;
            }
            if ($words !== [] && array_reduce($words, static fn (bool $all, string $w) => $all && str_contains($hay, $w), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hand the query to the product's {@see QueryAudit} (Portify: `porter_query_logs`), the one home for
     * "what did the assistant look up". Until 2026-11-04 this path wrote nothing: on Portify's test box
     * Porter told a consultant "the query tool rejected the request twice" and no record said which
     * plan, or why.
     *
     * ⚠ Keys only: entities, fields, filter fields and operators. A filter VALUE can be a person's
     * name, and an audit log is not a place to widen the PII surface. Never fails the request.
     *
     * @param  array<string,mixed>  $rawPlan
     */
    private function audit(Authenticatable $user, string $audience, array $rawPlan, string $status, int $rows, float $started, ?string $reason = null): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $targets = array_map(static fn ($t) => is_array($t) ? array_filter([
                'entity'  => $t['entity'] ?? null,
                'select'  => array_values(array_filter((array) ($t['select'] ?? []), 'is_string')),
                'metrics' => array_values(array_filter(array_map(static fn ($m) => is_array($m) ? ($m['key'] ?? null) : $m, (array) ($t['metrics'] ?? [])), 'is_string')),
                'filters' => array_values(array_map(static fn ($f) => is_array($f) ? ($f['field'] ?? '?').' '.($f['op'] ?? '=') : '?', (array) ($t['filters'] ?? []))),
                'group_by' => array_values(array_filter((array) ($t['group_by'] ?? $t['groupBy'] ?? []), 'is_string')),
            ]) : [], (array) ($rawPlan['targets'] ?? []));

            $this->audit->record($user, $audience, $targets, $status, $rows, (int) ((microtime(true) - $started) * 1000), $reason);
        } catch (Throwable $e) {
            Log::warning('QueryTools: query log write failed', ['error' => $e->getMessage()]);
        }
    }
}
