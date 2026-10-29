<?php

namespace Shirahcan\AiClient;

/**
 * An in-memory AI vault, so a product can test its console with no service.
 *
 * ⚠ IT MODELS THE RULES, NOT JUST THE SHAPE, and that is the difference between
 * a useful double and one that makes every console look correct. The rules it
 * reproduces are exactly the ones a console gets wrong:
 *
 *   - a credential NEVER carries an `api_key` back out, only a fingerprint;
 *   - posting the same (provider, label) ROTATES rather than duplicating;
 *   - clearance needs a named decider, and a plain update can never grant it;
 *   - `budgets()` lists products with NO cap, because that is the state that
 *     was true on production and invisible from every product's side;
 *   - a global kill makes every product `refusing` while no product's own
 *     switch is `stopped`;
 *   - this surface cannot mint an admin key.
 *
 * A fake that returned plausible rows without those rules would let a console
 * ship that shows a masked key, duplicates credentials on rotation, and reports
 * an unarmed product as protected.
 */
class FakeAiAdmin extends AiAdmin
{
    /** @var array<int, array<string, mixed>> */
    private array $credentials = [];

    /** @var array<string, array<string, mixed>> */
    private array $budgets = [];

    /** @var array<string, array<string, int>> product => audience ('' = default) => days */
    private array $retention = [];

    /** @var array<string, list<array{provider: string, model: ?string}>> decided routes */
    private array $routes = [];

    /** @var array<int, array<string, mixed>> */
    private array $trustKeys = [];

    /** @var array<string, array{at: string, by: ?string, reason: ?string}> */
    private array $stops = [];

    /** @var array<int, array<string, mixed>> */
    public array $writes = [];

    private int $nextId = 1;

    /** @param array<int, string> $products */
    public function __construct(private array $products = ['portify', 'mploynow', 'finance', 'studendly'])
    {
        // Deliberately no parent::__construct: there is no client and no network.
    }

    /*
    |--------------------------------------------------------------------------
    | Seeding
    |--------------------------------------------------------------------------
    */

    /**
     * Put a credential in the vault as if it were already there.
     *
     * ⚠ Pass `api_key` here if you like; it is stored privately and fingerprinted,
     * never returned - exactly as the service behaves.
     *
     * @param array<string, mixed> $attributes
     */
    public function seedCredential(array $attributes): static
    {
        $this->credentials[] = $this->normalise($attributes + ['id' => $this->nextId++]);

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function seedTrustKey(array $attributes): static
    {
        $this->trustKeys[] = $attributes + [
            'id' => $this->nextId++, 'product' => 'portify', 'label' => null,
            'is_active' => true, 'is_admin' => false,
            'last_seen_at' => null, 'created_at' => null, 'ever_used' => false,
        ];

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    public function credentials(): array
    {
        $rows = collect($this->credentials)->sortBy([['order', 'asc'], ['id', 'asc']])->values()->all();

        return [
            'credentials' => $rows,
            'summary' => [
                'total'      => count($rows),
                'generation' => count(array_filter($rows, fn ($r) => $r['kind'] === 'generation')),
                'search'     => count(array_filter($rows, fn ($r) => $r['kind'] === 'search')),
                'enabled'    => count(array_filter($rows, fn ($r) => $r['enabled'])),
                'sensitive_cleared' => count(array_filter($rows, fn ($r) => $r['sensitive_ok'])),
            ],
        ];
    }

    public function saveCredential(array $attributes): array
    {
        $this->writes[] = ['credential.save', $attributes['provider'] ?? null];

        $label = ($attributes['label'] ?? '') !== '' ? $attributes['label'] : ($attributes['kind'] ?? 'generation');

        foreach ($this->credentials as $i => $row) {
            if ($row['provider'] === ($attributes['provider'] ?? null) && $row['label'] === $label) {
                /*
                 * ⚠ A ROTATION, KEEPING THE ID AND THE CLEARANCE. The id is
                 * what the usage ledger references, and the contract that lets
                 * a provider see a passport is with the PROVIDER, not with the
                 * key string.
                 */
                $this->credentials[$i] = $this->normalise($attributes + [
                    'id'           => $row['id'],
                    'label'        => $label,
                    'sensitive_ok' => $row['sensitive_ok'],
                    'sensitive_cleared_by' => $row['sensitive_cleared_by'],
                    'sensitive_cleared_at' => $row['sensitive_cleared_at'],
                ]);

                return $this->credentials[$i];
            }
        }

        $this->credentials[] = $this->normalise($attributes + ['id' => $this->nextId++, 'label' => $label]);

        return end($this->credentials);
    }

    public function updateCredential(int $id, array $changes): array
    {
        $this->writes[] = ['credential.update', $id];

        /*
         * ⚠ THE PLAIN UPDATE CANNOT GRANT CLEARANCE, however the array is
         * shaped. Two ways to set one flag means one of them eventually skips
         * the attribution, which is how `sensitive_ok` ends up true with
         * nobody's name on it.
         */
        unset($changes['sensitive_ok'], $changes['sensitive_cleared_by'], $changes['sensitive_cleared_at']);

        foreach ($this->credentials as $i => $row) {
            if ($row['id'] === $id) {
                $this->credentials[$i] = $this->normalise($changes + $row);

                return $this->credentials[$i];
            }
        }

        return [];
    }

    public function clearSensitive(int $id, string $decidedBy): array
    {
        $this->writes[] = ['credential.sensitive_cleared', $id, $decidedBy];

        return $this->setClearance($id, true, $decidedBy);
    }

    public function revokeSensitive(int $id): array
    {
        $this->writes[] = ['credential.sensitive_revoked', $id];

        return $this->setClearance($id, false, null);
    }

    /** @return array<string, mixed> */
    private function setClearance(int $id, bool $clear, ?string $by): array
    {
        foreach ($this->credentials as $i => $row) {
            if ($row['id'] === $id) {
                $this->credentials[$i]['sensitive_ok'] = $clear;
                $this->credentials[$i]['sensitive_cleared_by'] = $clear ? $by : null;
                $this->credentials[$i]['sensitive_cleared_at'] = $clear ? '2026-01-01T00:00:00+00:00' : null;

                return $this->credentials[$i];
            }
        }

        return [];
    }

    /**
     * ⚠ THE SECRET NEVER SURVIVES INTO A RETURNED ROW. The fake fingerprints it
     * the same way the service does and then drops it, so a console tested
     * against this double cannot accidentally be built around reading a key
     * back - which is the one thing the real surface will never give it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes): array
    {
        $key = $attributes['api_key'] ?? null;
        unset($attributes['api_key']);

        $fingerprint = $attributes['key_fingerprint']
            ?? (is_string($key) && $key !== '' ? substr(hash('sha256', $key), 0, 8) : null);

        return $attributes + [
            'provider' => 'gemini',
            'kind'     => 'generation',
            'model'    => 'gemini-2.5-flash',
            'label'    => 'primary',
            'tier'     => 'free',
            'order'    => 100,
            'enabled'  => true,
            'base_url' => null,
            'timeout_seconds'  => 20,
            'allowed_products' => null,
            'sensitive_ok'     => false,
            'sensitive_cleared_by' => null,
            'sensitive_cleared_at' => null,
            'key_fingerprint' => $fingerprint,
            'key_set'         => $fingerprint !== null,
            'created_at'      => null,
            'updated_at'      => null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Spend
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠ EVERY PRODUCT, CONFIGURED OR NOT, like the real listing - because "no row" and a
     * deliberate choice looking identical is how `ai_budget_limits` sat empty in production
     * with the spend cap unable to fire for anybody.
     *
     * @return array{retention: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function retention(): array
    {
        $rows = [];

        foreach ($this->products as $product) {
            $windows = $this->retention[$product] ?? [];
            $configured = array_key_exists('', $windows);
            $days = $windows[''] ?? 365;

            $audiences = [];

            foreach ($windows as $audience => $value) {
                if ($audience !== '') {
                    $audiences[] = ['audience' => $audience, 'days' => $value, 'threads_held' => 0];
                }
            }

            $rows[] = [
                'product'    => $product,
                'days'       => $days,
                'configured' => $configured,
                'audiences'  => $audiences,
                'threads_held'        => 0,
                'threads_past_window' => 0,
                'threads_unstamped'   => 0,
                'state' => $configured
                    ? "kept for {$days} days after the last turn, unless an audience below says otherwise"
                    : "kept for {$days} days after the last turn - NOT configured, this is the service default",
            ];
        }

        return [
            'retention' => $rows,
            'summary'   => [
                'products'     => count($rows),
                'configured'   => count(array_filter($rows, fn (array $r) => $r['configured'])),
                'default_days' => 365,
                'max_days'     => 3650,
                'threads_past_window' => 0,
            ],
        ];
    }

    /**
     * ⚠ MOST SPECIFIC WINS, AND SETTING THE DEFAULT DOES NOT FLATTEN AN AUDIENCE, exactly as
     * the service resolves it. A fake that overwrote everything would let a product's test pass
     * on a rule production does not apply - and the real one quietly lengthening an anonymous
     * visitor's window to a signed-in client's is the mistake worth catching.
     */
    public function setRetention(string $product, int $days, ?string $audience = null): array
    {
        $key = ($audience === null || $audience === '') ? '' : $audience;

        $previous = $this->retention[$product][$key] ?? null;
        $this->retention[$product][$key] = $days;

        $this->writes[] = ['retention.set', $product, $key, $days];

        $listed = collect($this->retention()['retention'])->firstWhere('product', $product);

        return [
            'retention'            => (array) $listed,
            'threads_restamped'    => 0,
            'previous_days'        => $previous,
            'erased_by_next_prune' => 0,
        ];
    }

    /** @var list<array<string, mixed>> prices a test can seed or the console added */
    public array $rateRows = [];

    /** @var list<array<string, mixed>> paid keys the fake reports as unpriced */
    public array $unpricedKeys = [];

    public function rates(): array
    {
        return [
            'rates'    => $this->rateRows,
            'unpriced' => $this->unpricedKeys,
            'summary'  => [
                'priced'     => count($this->rateRows),
                'configured' => count(array_filter($this->rateRows, fn (array $r) => (bool) ($r['configured'] ?? false))),
                'unpriced'   => count($this->unpricedKeys),
            ],
        ];
    }

    public function addRate(array $price, ?string $setBy = null): array
    {
        $entry = $price + ['source' => 'set', 'id' => count($this->writes) + 1, 'set_by' => $setBy];
        $row = ['kind' => $price['kind'], 'match' => strtolower((string) $price['match']), 'configured' => true,
            'current' => $entry, 'next' => null, 'entries' => [$entry]];
        $this->rateRows[] = $row;
        $this->writes[] = ['rate.add', $price, $setBy];

        return $row;
    }

    public function deleteRate(int $id): array
    {
        $this->writes[] = ['rate.delete', $id];

        foreach ($this->rateRows as $i => $row) {
            if ((int) ($row['current']['id'] ?? 0) === $id) {
                unset($this->rateRows[$i]);
                $this->rateRows = array_values($this->rateRows);

                return ['rate' => $row, 'deleted' => true];
            }
        }

        return ['rate' => [], 'deleted' => false];
    }

    /** @var array<string, array{product: string, base_url: string, enabled: bool, has_secret: bool}> */
    private array $toolHosts = [];

    public function toolHosts(): array
    {
        return array_values($this->toolHosts);
    }

    public function setToolHost(string $product, string $baseUrl, ?string $secret = null, bool $enabled = true, ?string $setBy = null): array
    {
        $had = $this->toolHosts[$product]['has_secret'] ?? false;
        $this->toolHosts[$product] = ['product' => $product, 'base_url' => $baseUrl, 'enabled' => $enabled,
            'has_secret' => $had || ($secret !== null && $secret !== '')];
        $this->writes[] = ['tool_host.set', $product, $baseUrl, $setBy];

        return $this->toolHosts[$product];
    }

    /**
     * Shipped defaults a test can seed, so "undecided" is listed like the real service.
     *
     * @var array<string, list<array{provider: string, model: ?string}>>
     */
    public array $routeDefaults = [];

    public function taskRoutes(): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($this->routeDefaults), array_keys($this->routes))));
        sort($keys);

        $rows = array_map(fn (string $key) => $this->routeRow($key), $keys);

        return [
            'routes'  => $rows,
            'summary' => [
                'routes'            => count($rows),
                'configured'        => count(array_filter($rows, fn (array $r) => $r['configured'])),
                'unavailable_rungs' => 0,
            ],
        ];
    }

    public function setTaskRoute(string $routeKey, array $steps, ?string $setBy = null): array
    {
        $this->routes[$routeKey] = array_values(array_map(
            fn (array $s) => ['provider' => (string) $s['provider'], 'model' => ($s['model'] ?? null) ?: null],
            $steps,
        ));
        $this->writes[] = ['route.set', $routeKey, $this->routes[$routeKey], $setBy];

        return $this->routeRow($routeKey);
    }

    public function resetTaskRoute(string $routeKey, ?string $setBy = null): array
    {
        $removed = count($this->routes[$routeKey] ?? []);
        unset($this->routes[$routeKey]);
        $this->writes[] = ['route.reset', $routeKey, $setBy];

        return ['route' => $this->routeRow($routeKey), 'removed' => $removed];
    }

    /** @return array<string, mixed> */
    private function routeRow(string $key): array
    {
        $configured = array_key_exists($key, $this->routes);
        $mark = fn (array $steps) => array_map(fn (array $s) => $s + ['available' => true], $steps);

        return [
            'route_key'     => $key,
            'configured'    => $configured,
            'steps'         => $mark($configured ? $this->routes[$key] : ($this->routeDefaults[$key] ?? [])),
            'default_steps' => $mark($this->routeDefaults[$key] ?? []),
            'set_by'        => null,
            'updated_at'    => null,
        ];
    }

    public function budgets(): array
    {
        $rows = array_map(function (string $product) {
            $row = $this->budgets[$product] ?? null;

            if ($row === null) {
                /*
                 * ⚠ THE UNARMED ROW IS THE POINT. Every product is listed, so a
                 * console cannot quietly show only the products that happen to
                 * have a budget - which on production was none of them.
                 */
                return [
                    'product' => $product, 'armed' => false, 'period' => null,
                    'soft_micros' => null, 'hard_micros' => null, 'notify_to' => null,
                    'spend_micros' => null, 'refusing' => false,
                    'state' => 'no cap set - this product will never be refused on cost',
                ];
            }

            return $row;
        }, $this->products);

        return [
            'budgets' => $rows,
            'summary' => [
                'products' => count($rows),
                'armed'    => count(array_filter($rows, fn ($r) => $r['armed'])),
                'refusing' => count(array_filter($rows, fn ($r) => $r['refusing'])),
            ],
        ];
    }

    public function setBudget(
        string $product,
        ?int $softMicros,
        ?int $hardMicros,
        ?string $notifyTo = null,
        string $period = 'month',
    ): array {
        $this->writes[] = ['budget.set', $product, $softMicros, $hardMicros];

        $spend = $this->budgets[$product]['spend_micros'] ?? 0;
        $armed = $softMicros !== null || $hardMicros !== null;

        return $this->budgets[$product] = [
            'product' => $product,
            'armed'   => $armed,
            'period'  => $period,
            'soft_micros' => $softMicros,
            'hard_micros' => $hardMicros,
            'notify_to'   => $notifyTo ?? ($this->budgets[$product]['notify_to'] ?? null),
            'spend_micros' => $spend,
            'refusing'     => $hardMicros !== null && $spend >= $hardMicros,
            'soft_alerted_this_period' => false,
            'hard_alerted_this_period' => false,
            'state' => $hardMicros === null
                ? 'alert only - nothing will be refused on cost'
                : (($spend >= $hardMicros)
                    ? 'over the hard cap - every call is refused with over_budget'
                    : 'within budget'),
        ];
    }

    /** Pretend this product has already spent this much in the current period. */
    public function seedSpend(string $product, int $micros): static
    {
        $this->budgets[$product] = ($this->budgets[$product] ?? [
            'product' => $product, 'armed' => false, 'period' => 'month',
            'soft_micros' => null, 'hard_micros' => null, 'notify_to' => null,
            'refusing' => false, 'state' => 'alert only - nothing will be refused on cost',
        ]);

        $this->budgets[$product]['spend_micros'] = $micros;

        $hard = $this->budgets[$product]['hard_micros'];
        $this->budgets[$product]['refusing'] = $hard !== null && $micros >= $hard;

        if ($this->budgets[$product]['refusing']) {
            $this->budgets[$product]['state'] = 'over the hard cap - every call is refused with over_budget';
        }

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | The emergency stop
    |--------------------------------------------------------------------------
    */

    public function killSwitch(): array
    {
        return [
            'global' => [
                'stopped' => isset($this->stops['global']),
                'detail'  => $this->stops['global'] ?? null,
            ],
            'products' => array_map(fn (string $product) => [
                'product' => $product,
                'stopped' => isset($this->stops[$product]),
                'detail'  => $this->stops[$product] ?? null,
                /*
                 * ⚠ THE ASYMMETRY, REPRODUCED. Under a global stop every product
                 * REFUSES while no product's own switch is down. A fake that
                 * collapsed these into one field would let a console ship where
                 * resuming one product appears to do nothing.
                 */
                'refusing' => isset($this->stops['global']) || isset($this->stops[$product]),
            ], $this->products),
        ];
    }

    public function stop(string $scope, string $by, string $reason): array
    {
        $this->writes[] = ['kill.stop', $scope, $by, $reason];

        $this->stops[$scope] = ['at' => '2026-01-01T00:00:00+00:00', 'by' => $by, 'reason' => $reason];

        return $this->killSwitch();
    }

    public function resume(string $scope, string $by): array
    {
        $this->writes[] = ['kill.resume', $scope, $by];

        unset($this->stops[$scope]);

        return $this->killSwitch();
    }

    /*
    |--------------------------------------------------------------------------
    | Trust keys
    |--------------------------------------------------------------------------
    */

    public function trustKeys(): array
    {
        return [
            'trust_keys' => $this->trustKeys,
            'products'   => array_map(function (string $product) {
                $mine = array_filter($this->trustKeys, fn ($k) => $k['product'] === $product && $k['is_active']);
                $call = array_filter($mine, fn ($k) => ! $k['is_admin']);

                return [
                    'product'      => $product,
                    'active_keys'  => count($call),
                    'admin_keys'   => count($mine) - count($call),
                    'last_seen_at' => null,
                    'can_call'     => count($call) > 0,
                ];
            }, $this->products),
        ];
    }

    public function issueTrustKey(string $product, string $by, ?string $label = null): array
    {
        $this->writes[] = ['key.issue', $product, $by];

        $id = $this->nextId++;

        $this->trustKeys[] = [
            'id' => $id, 'product' => $product, 'label' => $label,
            /*
             * ⚠ `is_admin` IS HARD FALSE HERE, as it is on the service. This
             * surface cannot mint an admin key, and a fake that let it would
             * let a console ship with a button the real service refuses.
             */
            'is_active' => true, 'is_admin' => false,
            'last_seen_at' => null, 'created_at' => null, 'ever_used' => false,
        ];

        return [
            'trust_key' => ['id' => $id, 'product' => $product, 'label' => $label, 'is_admin' => false],
            'key_shown_once' => 'fake-key-'.$id,
            'next_step' => 'Set it in that product as AI_SERVICE_TRUST_KEY and rebuild its config cache.',
        ];
    }

    public function revokeTrustKey(int $id): array
    {
        $this->writes[] = ['key.revoke', $id];

        $product = null;

        foreach ($this->trustKeys as $i => $key) {
            if ($key['id'] === $id) {
                $this->trustKeys[$i]['is_active'] = false;
                $product = $key['product'];
            }
        }

        if ($product === null) {
            return ['revoked' => false];
        }

        $remaining = count(array_filter(
            $this->trustKeys,
            fn ($k) => $k['product'] === $product && $k['is_active'] && ! $k['is_admin'],
        ));

        return [
            'revoked' => true,
            'product' => $product,
            'remaining_active_call_keys' => $remaining,
            'warning' => $remaining === 0
                ? "{$product} now has no working trust key. Every AI call it makes will be refused."
                : null,
        ];
    }
}
