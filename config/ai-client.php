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
    |--------------------------------------------------------------------------
    | The admin key (only for a product with an AI vault console)
    |--------------------------------------------------------------------------
    |
    | ⚠ A DIFFERENT KEY FROM `trust_key`, AND THE SEPARATION IS THE SAFETY
    | MODEL. Minted with `php artisan ai:issue-key <product> --admin`, and used
    | ONLY by `$ai->admin()`: credentials, sensitive clearances, spend caps, the
    | kill switch, and the trust keys other products call with.
    |
    | The ordinary trust key travels on every generation call this product
    | makes, so it is the key most likely to leak - one already did, into a chat
    | transcript, on 2026-10-12. If the vault answered to it, four plaintext
    | `.env` lines on one box would each be an estate-wide root key.
    |
    | Leave it unset in every product that has no AI admin console. The package
    | refuses to boot if it is set to the same value as the trust key.
    |
    */

    'admin_key' => env('AI_SERVICE_ADMIN_KEY', ''),

    /*
    | Generous: a waterfall that falls through several providers can legitimately
    | take tens of seconds. This is a backstop against a hung socket, not a
    | latency budget - the service enforces its own per-provider timeouts.
    */
    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 60),

    /*
    | The chat tool host (`Laravel\Tools`, 1.29): the tools ai-service's chat engine calls
    | back. A DEPLOYMENT CREDENTIAL, not a gate: the shared signing secret (the same value as
    | this product's tool host row in ai-service's vault), the URL ai-service reaches this
    | backend on, and the product name ai-service knows this app by. Unset secret = every
    | tool call is refused. `php artisan ai:tools-register` writes the host row.
    */
    /*
    | Where this product's AI task templates live (`ai:tasks-sync`, 1.30): one Markdown file
    | per task. Null = `resources/ai-tasks` under the app.
    */
    'tasks' => [
        'path' => env('AI_TASKS_PATH'),
    ],

    /*
    | The assistant eval set `ai:eval` runs (1.31). Null = `tests/Eval/assistant_eval.json`.
    */
    'eval' => [
        'file' => env('AI_EVAL_FILE'),
    ],

    'tools' => [
        'product'  => env('AI_TOOLS_PRODUCT', ''),
        'secret'   => env('AI_TOOLS_SECRET', env('AI_CHAT_TOOLS_SECRET', '')),
        'host_url' => env('AI_TOOLS_HOST_URL', env('AI_CHAT_TOOLS_HOST_URL', env('APP_URL', ''))),
    ],

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
