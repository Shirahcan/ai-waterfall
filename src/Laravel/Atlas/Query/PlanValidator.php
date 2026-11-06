<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\AtlasDimension;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryFilter;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryPlan;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QuerySort;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryTarget;

/**
 * Validates a structural {@see QueryPlan} against the Atlas + the user's
 * permissions and returns a safe-to-execute plan or a clarification.
 *
 * Drop policy (spec §5.3):
 *  - a disallowed/unknown SELECT dimension is DROPPED (+ note) — it only narrows
 *    the columns shown, not the population;
 *  - a disallowed/unknown FILTER, METRIC, GROUP_BY, or relation REFUSES the
 *    target (it would change what the answer means). When no target survives,
 *    the whole result is a clarification — never a silently-broadened answer.
 */
class PlanValidator
{
    /**
     * Shown when Porter cannot reach the data behind a request.
     *
     * ⚠ THE OLD WORDING BLAMED THE USER, AND WAS USUALLY WRONG ABOUT WHY. It
     * read "You do not have access to that information here." - a flat denial
     * that tells someone their permissions are lacking. But the commonest cause
     * is not permissions at all: it is that no Atlas entity exists for what they
     * asked, so the planner had nothing to plan against and this was the only
     * branch left. A consultant who asked to "list all service packages
     * currently in my marketplace catalog" - their OWN commercial catalogue -
     * was told they lacked access to it. They did not; Porter had simply never
     * been taught about packages.
     *
     * So the wording says what is actually true from Porter's side, and invites
     * the correction rather than closing the conversation. Porter cannot tell
     * the two causes apart at this point, which is exactly why it must not
     * assert either one.
     *
     * A constant so ChatService can detect THIS specific refusal and, for a
     * client/employer, redirect them to their own records instead of
     * dead-ending (L1).
     */
    public const ACCESS_DENIED_MESSAGE = "I can't look that up from here. If you think I should be able to, tell me and I'll pass it on.";

    /** ops permitted per dimension type */
    private const OPS_BY_TYPE = [
        'number' => ['=', '!=', '>', '>=', '<', '<=', 'between', 'in', 'not_in', 'is_null', 'not_null'],
        'money' => ['=', '!=', '>', '>=', '<', '<=', 'between', 'in', 'not_in', 'is_null', 'not_null'],
        'date' => ['=', '!=', '>', '>=', '<', '<=', 'between', 'in', 'not_in', 'is_null', 'not_null'],
        'datetime' => ['=', '!=', '>', '>=', '<', '<=', 'between', 'in', 'not_in', 'is_null', 'not_null'],
        'string' => ['=', '!=', 'contains', 'starts_with', 'in', 'not_in', 'is_null', 'not_null'],
        'enum' => ['=', '!=', 'in', 'not_in', 'is_null', 'not_null'],
        'bool' => ['=', '!=', 'is_null', 'not_null'],
    ];

    public function __construct(private AtlasRegistry $registry) {}

    public function validate(QueryPlan $plan, string $audience, Authenticatable $user): ValidationResult
    {
        /** @var array<string, QueryTarget> $byId */
        $byId = [];
        foreach ($plan->targets as $target) {
            $byId[$target->id] = $target;
        }

        $survivors = [];
        $notes = [];
        $refusals = [];
        $outOfScopeCount = 0;   // targets refused because the entity simply does not exist
        $realRefusalCount = 0;  // targets refused for access / permission / shape reasons

        foreach ($plan->targets as $target) {
            $refusal = null;
            $outOfScope = false;
            $sanitised = $this->validateTarget($target, $audience, $user, $byId, $notes, $refusal, $outOfScope);

            if ($sanitised === null) {
                $refusals[] = $refusal ?? "I couldn't include '{$target->entity}'.";
                $notes[] = $refusal ?? "Omitted '{$target->entity}'.";
                $outOfScope ? $outOfScopeCount++ : $realRefusalCount++;

                continue;
            }

            $survivors[] = $sanitised;
        }

        if ($survivors === []) {
            // Out-of-scope ONLY when every failure was a non-existent entity (and no
            // real access/permission/shape refusal): the query did not map to any
            // platform data, so the caller may defer to research / general knowledge.
            $outOfScopeResult = $realRefusalCount === 0 && $outOfScopeCount > 0;

            return ValidationResult::clarify(
                implode(' ', $refusals) !== ''
                    ? implode(' ', $refusals)
                    : 'I could not build a valid query from that. Could you rephrase?',
                outOfScope: $outOfScopeResult,
            );
        }

        return ValidationResult::ok(
            new QueryPlan($survivors, $plan->presentation),
            $notes,
        );
    }

    /**
     * @param  array<string, QueryTarget>  $byId
     * @param  list<string>  $notes  (by-ref: drop notes appended)
     * @param  string|null  $refusal  (by-ref: set to the reason when the target is refused)
     * @param  bool  $outOfScope  (by-ref: set true when the entity simply does not exist —
     *                             an external / world-knowledge ask, not a permission denial)
     */
    private function validateTarget(QueryTarget $target, string $audience, Authenticatable $user, array $byId, array &$notes, ?string &$refusal, bool &$outOfScope): ?QueryTarget
    {
        $entity = $this->registry->get($target->entity);

        // The plan referenced a record type the Atlas does not hold at all — the AI
        // most likely mapped an external / world-knowledge question onto a
        // non-existent entity. This is NOT an access denial: flag it out-of-scope so
        // the caller can defer to research / general knowledge instead of dead-ending.
        if ($entity === null) {
            $refusal = "I couldn't find that in the platform's own data.";
            $outOfScope = true;

            return null;
        }

        // Entity-level access uses the SAME rule the catalog is built with
        // (AtlasRegistry::mayAccess): a rowScoped audience bypasses the broad
        // entity permission (it is self-limited to its own rows). Per-dimension
        // /per-metric `permission` gates below still apply, so sensitive columns
        // (PII, commission) stay protected even for the owning audience. A KNOWN
        // entity the audience cannot reach is a genuine denial (never out-of-scope).
        if (! $entity->appliesToAudience($audience)
            || ! $this->registry->mayAccess($entity, $audience, $user)) {
            // A clean, user-facing denial — never leak the internal entity key or
            // hint at data the user is not entitled to (out-of-scope hard-block).
            $refusal = self::ACCESS_DENIED_MESSAGE;

            return null;
        }

        // --- select: drop disallowed (note), never refuse ---
        $select = [];
        foreach ($target->select as $key) {
            $dim = $entity->dimension($key);
            if ($dim === null || ! $dim->selectable || ! $this->permitted($dim->permission, $user)) {
                // ⚠ Two different facts. "Not permitted for your role" about a key that simply does not
                // exist was repeated to an admin as "due dates aren't available to my role" (2026-10-31).
                $notes[] = $dim === null
                    ? $this->noSuchField($entity, $key, $user)
                    : "Omitted column '{$key}' on {$entity->key} - not available or not permitted for your role.";

                continue;
            }
            $select[] = $key;
        }

        // --- metrics: refuse on unknown/unpermitted (changes the answer) ---
        foreach ($target->metrics as $key) {
            $metric = $entity->metric($key);
            if ($metric === null) {
                $refusal = "I don't have that figure for {$entity->plural}.";

                return null;
            }
            if (! $this->permitted($metric->permission, $user)) {
                $refusal = "You don't have permission to see '{$metric->label}' for {$entity->plural}.";

                return null;
            }
        }

        // --- filters: refuse on any invalid (population-changing) ---
        foreach ($target->filters as $filter) {
            if (($problem = $this->filterProblem($entity, $filter, $user)) !== null) {
                $refusal = $problem;

                return null;
            }
        }

        // --- group_by: refuse on invalid ---
        foreach ($target->groupBy as $key) {
            $dim = $entity->dimension($key);
            if ($dim === null || ! $dim->groupable || ! $this->permitted($dim->permission, $user)) {
                $label = $dim !== null ? mb_strtolower($dim->label) : null;
                $refusal = $label !== null
                    ? "I can't break {$entity->plural} down by {$label}."
                    : "I can't break {$entity->plural} down that way.";

                return null;
            }
        }

        /*
         * --- sort ---
         *
         * ⚠ DROPPING A SORT SILENTLY CHANGES THE ANSWER, IT DOES NOT NARROW IT.
         * "Which case has been waiting on me the longest?" is planned as
         * sort(started_at asc) + limit 1. When `started_at` was not also in
         * `select`, the sort was discarded and the LIMIT survived - so the query
         * returned an ARBITRARY case, and Porter then reported it as the
         * longest-waiting one, in a confident sentence, to a consultant deciding
         * what to work on. A superlative computed from an unordered set is not a
         * degraded answer; it is a wrong one.
         *
         * So a sort key that IS a real, selectable, permitted dimension is
         * PROMOTED into the select rather than thrown away. That keeps the
         * ordering honest and, as a bonus, shows the reader the very column that
         * justifies the ranking. Only a key that is genuinely not orderable is
         * dropped, and that still carries its note.
         */
        $sort = [];
        foreach ($target->sort as $clause) {
            if (in_array($clause->key, $select, true) || in_array($clause->key, $target->metrics, true)) {
                $sort[] = $clause;

                continue;
            }

            $dim = $entity->dimension($clause->key);

            if ($dim !== null && $dim->selectable && $this->permitted($dim->permission, $user)) {
                $select[] = $clause->key;
                $sort[] = $clause;

                continue;
            }

            $notes[] = "Ignored sort by '{$clause->key}' on {$entity->key} - not a selected column or metric.";
        }

        // --- link: refuse on unknown/mismatched relation ---
        if ($target->link !== null) {
            $parent = $byId[$target->link->from] ?? null;
            $parentEntity = $parent !== null ? $this->registry->get($parent->entity) : null;
            $relation = $parentEntity?->relation($target->link->relation);

            if ($relation === null || $relation->targetEntity !== $entity->key) {
                $refusal = "I can't connect those two together.";

                return null;
            }
        }

        return new QueryTarget(
            id: $target->id,
            entity: $target->entity,
            select: $select,
            metrics: $target->metrics,
            filters: $target->filters,
            groupBy: $target->groupBy,
            sort: $sort,
            limit: max(1, min($target->limit, QueryTarget::MAX_LIMIT)),
            timeRange: $target->timeRange,
            link: $target->link,
            renderGroup: $target->renderGroup,
            offset: $target->offset,
        );
    }

    /**
     * What to tell the model about a field key it guessed, naming the real ones (1.49.0).
     *
     * ⚠ "DESCRIBE THE ENTITY" WAS NOT ENOUGH. Asked when each case was opened, the model guessed
     * `created_at`, the column was dropped, and it told a client "case records don't include a creation
     * date" while `opened_at` sat on the same record (Portify test box, 2026-11-06). The real keys go
     * in the note itself, so the next step is a corrected query, not another lookup it can skip.
     */
    private function noSuchField(AtlasEntity $entity, string $key, Authenticatable $user): string
    {
        $near = $this->nearestFields($entity, $key, $user);

        return "Omitted column '{$key}' on {$entity->key} - there is no such field"
            .($near === []
                ? '; describe the entity for its field keys.'
                : '. Use the real key: '.implode(', ', $near).'. Run the query again with it before you answer, and never tell the person the field does not exist.');
    }

    /**
     * The fields a guessed key probably meant: every date for a date-looking key, the record's name for
     * a name-looking one, else fields sharing a word with it. Only what this person may select.
     *
     * @return list<string> `key (Label)`
     */
    private function nearestFields(AtlasEntity $entity, string $key, Authenticatable $user): array
    {
        $usable = array_filter($entity->dimensions, fn (AtlasDimension $d) => $d->selectable && $this->permitted($d->permission, $user));
        $words = array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($key)) ?: [], static fn (string $w) => strlen($w) >= 3);

        if (preg_match('/(_at|_on|date|time|when|created|opened|started|updated)$/i', $key) === 1) {
            $near = array_filter($usable, static fn (AtlasDimension $d) => in_array($d->type, ['date', 'datetime'], true));
        } elseif (array_intersect($words, ['name', 'title', 'label']) !== []) {
            $primary = array_filter($usable, static fn (AtlasDimension $d) => $d->key === $entity->primaryLabelDimension()?->key);
            $named = array_filter($usable, static function (AtlasDimension $d) {
                return preg_match('/name|title/i', $d->key.' '.$d->label) === 1;
            });
            $near = $primary + $named;
        } else {
            $near = array_filter($usable, static function (AtlasDimension $d) use ($words) {
                $hay = mb_strtolower($d->key.' '.$d->label);
                foreach ($words as $word) {
                    if (str_contains($hay, $word)) {
                        return true;
                    }
                }

                return false;
            });
        }

        return array_values(array_map(
            static fn (AtlasDimension $d) => "{$d->key} ({$d->label})",
            array_slice($near, 0, 6),
        ));
    }

    private function permitted(?string $permission, Authenticatable $user): bool
    {
        return $this->registry->mayHold($user, $permission);
    }

    /** Returns a refusal reason if the filter is invalid, else null. */
    private function filterProblem(AtlasEntity $entity, QueryFilter $filter, Authenticatable $user): ?string
    {
        $dim = $entity->dimension($filter->field);

        if ($dim === null || ! $dim->filterable || ! $this->permitted($dim->permission, $user)) {
            return $dim !== null
                ? "I can't filter {$entity->plural} by ".mb_strtolower($dim->label).'.'
                : "I can't filter {$entity->plural} that way.";
        }

        $allowed = self::OPS_BY_TYPE[$dim->type] ?? [];
        if (! in_array($filter->op, $allowed, true)) {
            return "The condition '{$filter->op}' doesn't apply to '{$dim->label}'.";
        }

        if (in_array($filter->op, ['in', 'not_in'], true) && ! is_array($filter->value)) {
            return "The '{$dim->label}' filter needs a list of values.";
        }

        if ($filter->op === 'between' && (! is_array($filter->value) || count($filter->value) !== 2)) {
            return "The '{$dim->label}' range needs exactly two values.";
        }

        return null;
    }
}
