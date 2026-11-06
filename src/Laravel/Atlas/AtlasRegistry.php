<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Aggregates every {@see EntityProvider} and exposes the catalog filtered by audience and the viewing
 * user's permissions. The data engine every product's assistant queries through (moved from Portify's
 * Porter Atlas into the shared kit, plan mploynow-mira-parity P0b).
 *
 * A PRODUCT SUBCLASSES THIS: {@see defaultProviders()} returns its own entity list and
 * {@see defaultAccess()} its own permission rule, and the product binds this class to its subclass so the
 * kit's validator, builder and tools resolve the product's catalog. Tests inject their own list.
 *
 * ⚠ THE KIT'S DEFAULT GRANTS NO PERMISSION. A product that forgets {@see defaultAccess()} still serves
 * entities whose `permission` is null and every row-scoped audience, and refuses every permissioned entity
 * and field: a missing rule hides data, it never widens it.
 */
class AtlasRegistry
{
    /** @var list<EntityProvider> */
    private array $providers;

    private AtlasAccess $access;

    /**
     * @param  list<EntityProvider>|null  $providers  null = the product's {@see defaultProviders()}
     */
    public function __construct(?array $providers = null, ?AtlasAccess $access = null)
    {
        $this->providers = $providers ?? $this->defaultProviders();
        $this->access = $access ?? $this->defaultAccess();
    }

    /**
     * The product's canonical entity list. Empty in the kit: a product overrides it.
     *
     * @return list<EntityProvider>
     */
    protected function defaultProviders(): array
    {
        return [];
    }

    /** The product's permission rule. The kit's grants nothing (fail closed). */
    protected function defaultAccess(): AtlasAccess
    {
        return new NoPermissions();
    }

    /** Whether $user holds $permission, by the product's rule. Null permission = open. */
    public function mayHold(Authenticatable $user, ?string $permission): bool
    {
        return $permission === null || $this->access->mayHold($user, $permission);
    }

    /** @var array<string, AtlasEntity>|null built definitions, keyed by entity key */
    private ?array $built = null;

    private float $builtAt = 0.0;

    /**
     * ⚠ BUILT ONCE PER INSTANCE, NOT ON EVERY CALL. `get()` used to rebuild all 86 definitions each
     * time (18 ms), and one data question calls it about a hundred times through the validator, the
     * builder and the preview, so every question paid ~2 s before touching a row; the pulse, a few
     * hundred lookups, paid that per area (measured 2026-11-06). The memo is bounded to a minute so a
     * registry held by a long-lived worker still sees a gate or a config change that alters an entity.
     *
     * @return list<AtlasEntity>
     */
    public function all(): array
    {
        if ($this->built === null || microtime(true) - $this->builtAt > 60) {
            $this->built = [];
            foreach ($this->providers as $p) {
                $entity = $p->entity();
                $this->built[$entity->key] = $entity;
            }
            $this->builtAt = microtime(true);
        }

        return array_values($this->built);
    }

    public function get(string $key): ?AtlasEntity
    {
        $this->all();

        return $this->built[$key] ?? null;
    }

    /**
     * Entities this audience + user may query (audience membership + the
     * entity's gating permission). The permission check short-circuits when an
     * entity declares `permission: null`.
     *
     * @return list<AtlasEntity>
     */
    public function forAudience(string $audience, Authenticatable $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (AtlasEntity $entity) => $entity->appliesToAudience($audience)
                && $this->mayAccess($entity, $audience, $user),
        ));
    }

    /**
     * Whether $user, acting as $audience, may query $entity.
     *
     * The entity-level `permission` is a BROAD-BROWSE gate ("may this user see
     * ALL rows of this type platform-wide?"). An audience that carries a
     * `rowScopes[$audience]` closure is self-limited to its own rows, so it
     * bypasses that broad permission — the rowScope IS its access control.
     * Audiences without a rowScope (e.g. admin) must still hold the permission,
     * so limited-permission staff gain no new access. Per-dimension
     * `sensitive`/`permission` gates are unaffected and still apply, so PII /
     * financial columns stay protected even for the owning audience.
     *
     * Public so PlanValidator gates EXECUTION by the same rule the catalog is
     * built with — otherwise a rowScoped audience is offered an entity it is
     * then refused at run time (e.g. a consultant's own clients/transactions).
     */
    public function mayAccess(AtlasEntity $entity, string $audience, Authenticatable $user): bool
    {
        if ($entity->permission === null) {
            return true;
        }

        if ($entity->rowScope($audience) !== null) {
            return true;
        }

        return $this->mayHold($user, $entity->permission);
    }

    /**
     * A compact catalog for the QueryPlanBuilder prompt — only the dimensions
     * and metrics this user is permitted to use, so the model never sees (and
     * cannot request) a field it isn't allowed.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * The catalog's TABLE OF CONTENTS: enough to choose an entity, nothing more.
     *
     * ⚠ THIS EXISTS SO THAT ADDING DATA STOPS TAXING EVERY QUERY. The full
     * catalog is ~1,530 characters PER ENTITY, so each new entity made every
     * single plan call more expensive whether or not anyone asked about it -
     * which turns the Atlas into a bottleneck and quietly punishes the act of
     * teaching Porter about more of the platform. Exactly backwards.
     *
     * Measured for a consultant with 14 entities:
     *
     *   full catalog          21,415 chars  (~5,354 tokens)
     *   this index             1,569 chars  (~392 tokens)
     *   index + one entity     5,827 chars  (~1,457 tokens)   73% smaller
     *
     * And the growth rate is the real prize: **~112 characters per new entity
     * instead of ~1,530**, so coverage can grow ~14x further for the same cost.
     *
     * ⚠ IT LISTS EVERY ENTITY, AND THAT IS THE POINT. Only the DETAIL is
     * deferred, never the existence: the model can always see that a thing is
     * queryable and ask for its fields. Filtering the list itself would hide
     * capabilities from the model and produce exactly the "I can't look that up"
     * refusals this work is removing.
     *
     * @return list<array<string, mixed>>
     */
    public function indexForAudience(string $audience, Authenticatable $user): array
    {
        return array_map(fn (AtlasEntity $entity) => array_filter([
            'key' => $entity->key,
            'label' => $entity->labelFor($audience),
            'plural' => $entity->pluralFor($audience),
            'also_called' => $entity->aliasesFor($audience) ?: null,
            // One example is enough to disambiguate "case" from "case note".
            'example' => $entity->sampleQuestions[0] ?? null,
        ], static fn ($v) => $v !== null), $this->forAudience($audience, $user));
    }

    public function describeForAudience(string $audience, Authenticatable $user): array
    {
        $permitted = fn ($field) => $this->mayHold($user, $field->permission);

        return array_map(function (AtlasEntity $entity) use ($permitted, $audience) {
            return array_filter([
                'key' => $entity->key,
                'label' => $entity->labelFor($audience),
                'plural' => $entity->pluralFor($audience),
                // The audience's own words for this type (1.48.0): searched, and shown so the model uses them.
                'also_called' => $entity->aliasesFor($audience) ?: null,
                'dimensions' => array_values(array_map(
                    fn (AtlasDimension $d) => array_filter([
                        'key' => $d->key,
                        'label' => $d->label,
                        // Planner-only; omitted when absent (array_filter below).
                        'description' => $d->description,
                        'type' => $d->type,
                        'filterable' => $d->filterable,
                        'groupable' => $d->groupable,
                        // Only enum dims carry allowed values; omitted otherwise.
                        'values' => $d->values,
                    ], static fn ($v) => $v !== null),
                    array_filter($entity->dimensions, $permitted),
                )),
                'metrics' => array_values(array_map(
                    fn (AtlasMetric $m) => [
                        'key' => $m->key,
                        'label' => $m->label,
                        'format' => $m->format,
                    ],
                    array_filter($entity->metrics, $permitted),
                )),
                'relations' => array_map(
                    fn (AtlasRelation $r) => [
                        'key' => $r->key,
                        'label' => $r->label,
                        'target' => $r->targetEntity,
                    ],
                    $entity->relations,
                ),
                'sample_questions' => $entity->sampleQuestions,
            ], static fn ($v) => $v !== null);
        }, $this->forAudience($audience, $user));
    }
}
