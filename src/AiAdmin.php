<?php

namespace Shirahcan\AiClient;

/**
 * The estate's AI vault, reachable from a product's admin console.
 *
 * ⚠ IT NEEDS A SEPARATE ADMIN KEY, AND THAT IS THE WHOLE SAFETY MODEL. Reach it
 * through {@see AiClient::admin()}, which uses `AI_SERVICE_ADMIN_KEY` rather
 * than the ordinary trust key. The ordinary key travels on every generation
 * call this product makes, so it is the key most likely to leak - one already
 * did, into a chat transcript, on 2026-10-12 - and it must therefore be able to
 * do the least. Behind the ordinary key these methods would make every
 * product's hot-path key an estate-wide root key.
 *
 * ⚠ THE VAULT IS SHARED, SO THESE CALLS CHANGE THINGS FOR EVERY PRODUCT. A
 * credential, a bench, a kill switch and a trust key are estate-wide facts;
 * there is no "this product's half" to scope them to. What stays scoped is
 * authorship: the service records which product's console made each change.
 *
 * ⚠ AND A KEY GOES IN BUT NEVER COMES BACK OUT. No method here returns an
 * `api_key`, in any form, masked or otherwise. `key_fingerprint` is a one-way
 * hash prefix and is what answers "did my rotation land".
 */
class AiAdmin
{
    public function __construct(private AiClient $client) {}

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    /**
     * Every credential in the vault, as CONFIGURATION.
     *
     * ⚠ This is not {@see AiClient::credentialStatus()}, and the difference
     * matters to a console. That one is the RUNTIME picture - benched, circuit
     * open, size limited - which every product may read, because a bench is a
     * fact about a provider's quota. This one is how the vault is CONFIGURED:
     * the allow-list, the order, and who cleared a key for client documents.
     *
     * @return array{credentials: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function credentials(): array
    {
        $payload = $this->client->getJson('api/v1/admin/credentials');

        return [
            'credentials' => (array) ($payload['credentials'] ?? []),
            'summary'     => (array) ($payload['summary'] ?? []),
        ];
    }

    /**
     * Add a credential, or rotate the key on one that exists.
     *
     * ⚠ KEYED ON (provider, label) BY THE SERVICE, so posting the same pair
     * ROTATES rather than duplicating. That is deliberate: a delete-then-insert
     * would leave a window with one fewer rung on every waterfall call, and
     * would orphan the id the usage ledger references.
     *
     * ⚠ IT CANNOT GRANT SENSITIVE CLEARANCE, however the array is shaped. Use
     * {@see clearSensitive()}, which demands a named decider.
     *
     * @param  array<string, mixed>  $attributes  provider, kind, model, label,
     *   api_key, tier, order, base_url, timeout_seconds, enabled, allowed_products
     * @return array<string, mixed>
     */
    public function saveCredential(array $attributes): array
    {
        return (array) ($this->client->postJson('api/v1/admin/credentials', $attributes)['credential'] ?? []);
    }

    /**
     * Change one or more settings on a credential.
     *
     * ⚠ SEND ONLY WHAT CHANGED. The service treats absent fields as untouched,
     * so `['enabled' => false]` switches a rung off without blanking its model,
     * base url and allow-list. Sending a whole row back is how a console turns
     * "disable this key" into "break this key".
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateCredential(int $id, array $changes): array
    {
        return (array) ($this->client->patchJson("api/v1/admin/credentials/{$id}", $changes)['credential'] ?? []);
    }

    /**
     * Clear a credential to handle client documents, invoices and identity data.
     *
     * ⚠ `$decidedBy` IS REQUIRED BY THE SERVICE, NOT MERELY RECORDED. The
     * question asked after an incident is "who decided this provider could see
     * client passports", and an unattributed clearance is indistinguishable
     * from one nobody made. Pass the signed-in person, never the product.
     *
     * ⚠ AND IT IS NEVER INFERRED FROM THE TIER. "Paid" is a billing fact; "may
     * be shown a passport" is a contractual one. A paid consumer-plan key with
     * no DPA and training on by default is billed and non-compliant at once.
     *
     * @return array<string, mixed>
     */
    public function clearSensitive(int $id, string $decidedBy): array
    {
        return (array) ($this->client->postJson("api/v1/admin/credentials/{$id}/sensitive", [
            'sensitive_ok' => true,
            'decided_by'   => $decidedBy,
        ])['credential'] ?? []);
    }

    /**
     * Withdraw clearance. Every sensitive task then refuses with
     * `no_compliant_credential` unless another cleared credential exists - and
     * that refusal is correct behaviour, not a fault.
     *
     * @return array<string, mixed>
     */
    public function revokeSensitive(int $id): array
    {
        return (array) ($this->client->postJson("api/v1/admin/credentials/{$id}/sensitive", [
            'sensitive_ok' => false,
        ])['credential'] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | Spend
    |--------------------------------------------------------------------------
    */

    /**
     * Every configured product's thresholds, armed or not, with its spend.
     *
     * ⚠ READ `armed`. The service enumerates products that have NO budget row,
     * because "no cap" and "a generous cap" are indistinguishable from a
     * product's side - the AI simply never refuses. `ai_budget_limits` was empty
     * on production, so the hard cap could never fire for anybody while six
     * tests covered it.
     *
     * @return array{budgets: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function budgets(): array
    {
        $payload = $this->client->getJson('api/v1/admin/budgets');

        return [
            'budgets' => (array) ($payload['budgets'] ?? []),
            'summary' => (array) ($payload['summary'] ?? []),
        ];
    }

    /**
     * Set one product's thresholds, in MICROS of a dollar.
     *
     * ⚠ MICROS, BECAUSE THAT IS WHAT THE LEDGER RECORDS. Taking dollars here
     * would put the conversion in two places, and the copy that drifts is
     * always the one in the UI.
     *
     * ⚠ SOFT ALERTS, HARD REFUSES, AND THEY ARE NOT INTERCHANGEABLE. Soft is
     * ordinary spend drift, where stopping a product would be the wrong cure - a
     * consultant mid-filing should not lose Porter because the month ran hot.
     * Hard is the buffer until a human acts.
     *
     * ⚠ AND NEITHER IS A DEFENCE AGAINST A RUNAWAY LOOP. Both measure money,
     * which only accumulates after the requests have been made; the service's
     * per-product rate limit is what catches a loop in the first minutes.
     *
     * @return array<string, mixed>
     */
    public function setBudget(
        string $product,
        ?int $softMicros,
        ?int $hardMicros,
        ?string $notifyTo = null,
        string $period = 'month',
    ): array {
        /*
         * ⚠ THE THRESHOLDS ARE SENT EVEN WHEN NULL, and the usual
         * `array_filter(fn ($v) => $v !== null)` would have been a real defect
         * here: the service validates them as `present`, because clearing a cap
         * and not mentioning it are different intentions, and a filtered body
         * would have made "remove the hard cap" unexpressible.
         */
        $body = [
            'product'     => $product,
            'period'      => $period,
            'soft_micros' => $softMicros,
            'hard_micros' => $hardMicros,
        ];

        if ($notifyTo !== null) {
            // Omitted rather than nulled: the service keeps the address it has
            // when none is given, since `notify_to` is NOT NULL on its side.
            $body['notify_to'] = $notifyTo;
        }

        return (array) ($this->client->putJson('api/v1/admin/budgets', $body)['budget'] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | The emergency stop
    |--------------------------------------------------------------------------
    */

    /**
     * What is stopped right now.
     *
     * ⚠ READ BOTH FIELDS PER PRODUCT. `stopped` is that product's own switch;
     * `refusing` is the effective answer the generation path gets, which is also
     * true under a global stop. A console that showed only one would either
     * report a healthy product that refuses every call, or make a global stop
     * look like four independent product stops - so resuming one would appear
     * to do nothing.
     *
     * @return array{global: array<string, mixed>, products: array<int, array<string, mixed>>}
     */
    public function killSwitch(): array
    {
        $payload = $this->client->getJson('api/v1/admin/kill');

        return [
            'global'   => (array) ($payload['global'] ?? []),
            'products' => (array) ($payload['products'] ?? []),
        ];
    }

    /**
     * Stop spending. `$scope` is `global` or a product name.
     *
     * ⚠ THE REASON IS REQUIRED BY THE SERVICE. An unexplained stop is
     * indistinguishable from one somebody forgot to undo, and guessing wrong
     * costs either days of no AI or resumed spend that was stopped for a reason.
     *
     * @return array<string, mixed>
     */
    public function stop(string $scope, string $by, string $reason): array
    {
        return $this->client->postJson('api/v1/admin/kill', [
            'scope' => $scope, 'by' => $by, 'reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    public function resume(string $scope, string $by): array
    {
        return $this->client->postJson('api/v1/admin/kill/resume', ['scope' => $scope, 'by' => $by]);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may call the service
    |--------------------------------------------------------------------------
    */

    /**
     * Every trust key, and per product whether it can call at all.
     *
     * ⚠ READ `can_call` AND `ever_used`. finance-suite ran for weeks on
     * production with no working trust key, and because its callers catch
     * broadly, every AI feature it had degraded silently rather than anybody
     * being told. "Issued but never used" is the shape of that fault.
     *
     * @return array{trust_keys: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>}
     */
    /**
     * How long each product keeps its transcripts, and whether anybody decided.
     *
     * ⚠ READ THE `audiences` LIST, NOT ONLY THE PRODUCT LINE. One product can owe several
     * promises - Portify keeps 30 days for a visitor on its own site and for ever for anybody
     * signed in - and the product line alone would misreport every audience that has its own
     * window.
     *
     * ⚠ AND READ `configured`. A product with no row is not unprotected, it runs on the service
     * default, but nobody has DECIDED it - and "no row" looking identical to a deliberate choice
     * is exactly how `ai_budget_limits` sat empty in production with the spend cap unable to
     * fire for anybody.
     *
     * @return array{retention: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function retention(): array
    {
        $payload = $this->client->getJson('api/v1/admin/retention');

        return [
            'retention' => array_values((array) ($payload['retention'] ?? [])),
            'summary'   => (array) ($payload['summary'] ?? []),
        ];
    }

    /**
     * Set how long a product - or one of its audiences - keeps transcripts.
     *
     * ⚠ IT RE-STAMPS THE EXISTING THREADS, which is the point. Without that, shortening a
     * window would change the console and not the data: the screen would say 90 days while
     * every row kept a year. The reply's `erased_by_next_prune` is the consequence, and it is
     * worth reading BEFORE the prune runs rather than after.
     *
     * ⚠ OMITTING `$audience` SETS THE PRODUCT'S DEFAULT and does NOT overwrite an audience that
     * has its own window. Most specific wins, so a default set carelessly cannot quietly
     * lengthen an anonymous visitor's window to a signed-in client's.
     *
     * @return array{retention: array<string, mixed>, threads_restamped: int, previous_days: ?int, erased_by_next_prune: int}
     */
    public function setRetention(string $product, int $days, ?string $audience = null): array
    {
        $body = ['product' => $product, 'days' => $days];

        if ($audience !== null && $audience !== '') {
            $body['audience'] = $audience;
        }

        $payload = $this->client->putJson('api/v1/admin/retention', $body);

        return [
            'retention'            => (array) ($payload['retention'] ?? []),
            'threads_restamped'    => (int) ($payload['threads_restamped'] ?? 0),
            'previous_days'        => $payload['previous_days'] ?? null,
            'erased_by_next_prune' => (int) ($payload['erased_by_next_prune'] ?? 0),
        ];
    }

    /**
     * Every task route: which models each task (or task variant) tries first.
     *
     * READ `configured`. An undecided route runs on the service's shipped
     * default, which is not the same as somebody choosing it. And read each
     * rung's `available`: a rung naming a model the vault holds no enabled
     * credential for is skipped silently by the walk.
     *
     * @return array{routes: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function taskRoutes(): array
    {
        $payload = $this->client->getJson('api/v1/admin/task-routes');

        return [
            'routes'  => array_values((array) ($payload['routes'] ?? [])),
            'summary' => (array) ($payload['summary'] ?? []),
        ];
    }

    /**
     * Replace one route's ladder (1 to 8 rungs, first is tried first).
     *
     * @param  list<array{provider: string, model?: ?string}>  $steps
     * @return array<string, mixed> the route as now listed
     */
    public function setTaskRoute(string $routeKey, array $steps, ?string $setBy = null): array
    {
        $body = ['route_key' => $routeKey, 'steps' => array_values($steps)];

        if ($setBy !== null && $setBy !== '') {
            $body['set_by'] = $setBy;
        }

        return (array) ($this->client->putJson('api/v1/admin/task-routes', $body)['route'] ?? []);
    }

    /**
     * Put one route back on the service's shipped default.
     *
     * @return array{route: array<string, mixed>, removed: int}
     */
    public function resetTaskRoute(string $routeKey, ?string $setBy = null): array
    {
        $body = ['route_key' => $routeKey];

        if ($setBy !== null && $setBy !== '') {
            $body['set_by'] = $setBy;
        }

        $payload = $this->client->postJson('api/v1/admin/task-routes/reset', $body);

        return [
            'route'   => (array) ($payload['route'] ?? []),
            'removed' => (int) ($payload['removed'] ?? 0),
        ];
    }

    public function trustKeys(): array
    {
        $payload = $this->client->getJson('api/v1/admin/trust-keys');

        return [
            'trust_keys' => (array) ($payload['trust_keys'] ?? []),
            'products'   => (array) ($payload['products'] ?? []),
        ];
    }

    /**
     * Mint an ordinary CALL key for a product. The plaintext is in
     * `key_shown_once` and exists nowhere else, ever again.
     *
     * ⚠ THIS CANNOT MINT AN ADMIN KEY, and that is deliberate rather than an
     * omission. Everything else a leaked admin key could do is undone by
     * rotating that key; a second admin key it minted for itself would survive
     * the rotation, and nothing in a list of trust keys would look wrong about
     * it. Admin keys come only from `ai:issue-key --admin`, over SSH.
     *
     * ⚠ SHOW IT ONCE AND DO NOT STORE IT. A console that saved this value has
     * just created a second place the estate's AI can be reached from.
     *
     * @return array<string, mixed>
     */
    public function issueTrustKey(string $product, string $by, ?string $label = null): array
    {
        return $this->client->postJson('api/v1/admin/trust-keys', array_filter([
            'product' => $product, 'by' => $by, 'label' => $label,
        ], fn ($v) => $v !== null));
    }

    /**
     * Revoke a key. Deactivation, not deletion - the row is what attributes an
     * old usage line to a caller.
     *
     * ⚠ CHECK `remaining_active_call_keys` AND `warning` IN THE REPLY. Taking a
     * product's last key away means every AI call it makes is refused from that
     * moment, which is exactly the silent state finance-suite sat in.
     *
     * @return array<string, mixed>
     */
    public function revokeTrustKey(int $id): array
    {
        return $this->client->deleteJson("api/v1/admin/trust-keys/{$id}");
    }
}
