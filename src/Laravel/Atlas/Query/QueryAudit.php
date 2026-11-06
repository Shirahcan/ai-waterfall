<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where a product records what its assistant looked up (Portify: `porter_query_logs`). Optional: with
 * none bound, {@see QueryTools} records nothing.
 *
 * ⚠ `$targets` carries KEYS ONLY (entity, selected fields, metric keys, "field op" per filter,
 * group-by keys), never a filter value: a value can be a person's name. A failed write must never fail
 * the question; {@see QueryTools} catches and logs it.
 */
interface QueryAudit
{
    /**
     * @param  list<array<string, mixed>>  $targets
     * @param  string  $status  `ok`, `partial` (some targets unreadable), `rejected` (the validator
     *                          refused the plan) or `failed`, as {@see QueryTools} reports it
     */
    public function record(Authenticatable $user, string $audience, array $targets, string $status, int $rows, int $durationMs, ?string $reason): void;
}
