<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where ai-service lives
    |--------------------------------------------------------------------------
    |
    | Loopback, always. Siblings on this box reach each other via /etc/hosts, and
    | the service has no public server block by design - it holds every AI
    | provider key in the estate.
    |
    */

    'base_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8007'),

    /*
    | Issued BY the service (`php artisan ai:issue-key <product>`), because the
    | callee owns the key. This product never mints its own.
    */
    'trust_key' => env('AI_SERVICE_TRUST_KEY', ''),

    /*
    | Generous: a waterfall that falls through several providers can legitimately
    | take tens of seconds. This is a backstop against a hung socket, not a
    | latency budget - the service enforces its own per-provider timeouts.
    */
    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Deliberately absent
    |--------------------------------------------------------------------------
    |
    | ⚠ No provider names. No model ids. No rates. No API keys. The moment a
    | product's config names a model, that product has an opinion about routing
    | and the single source of truth has leaked back out of the service.
    |
    */

];
