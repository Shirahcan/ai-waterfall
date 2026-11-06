<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Context handed to each {@see \Shirahcan\AiClient\Laravel\Atlas\AtlasMetric} resolver
 * when SafeQueryBuilder compiles a metric subquery. Carries the audience + user
 * (so a resolver can add its own row-scope), the optional time window, and the
 * currency guard.
 */
final class MetricContext
{
    public function __construct(
        public readonly string $audience,
        public readonly Authenticatable $user,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $currency = null,
    ) {}
}
