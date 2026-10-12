<?php

namespace Shirahcan\AiClient;

use Illuminate\Support\ServiceProvider;

class AiClientServiceProvider extends ServiceProvider
{
    /**
     * The pre-1.0 class name, as a LITERAL.
     *
     * ⚠ IT CANNOT BE WRITTEN AS `AiWaterfallClient::class`, AND THAT IS THE
     * WHOLE REASON THIS CONSTANT EXISTS. `::class` is resolved by the compiler
     * from the source text, NOT through `class_alias`, so writing it would
     * produce the new name and the alias below would bind nothing.
     */
    private const LEGACY_CLIENT = 'Shirahcan\AiWaterfall\AiWaterfallClient';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-client.php', 'ai-client');

        $this->app->singleton(AiClient::class, function () {
            $key = (string) config('ai-client.trust_key', '');

            /*
             * ⚠ FAIL LOUDLY AT RESOLUTION, NOT SILENTLY AT THE FIRST CALL. An
             * unset trust key means this product was never issued one, or its env
             * did not reach the process - both are deployment mistakes, and both
             * are far cheaper to find at boot than as a 401 during a client's
             * question.
             *
             * Measured consequence of getting this wrong anyway: finance-suite
             * ran for weeks on production with no key, and because its callers
             * catch broadly, every AI feature it has degraded silently instead
             * of anybody being told.
             */
            if ($key === '') {
                throw new \RuntimeException(
                    'ai-client: AI_SERVICE_TRUST_KEY is not set. Issue one on the service '
                    .'with `php artisan ai:issue-key <product>` and put it in this app\'s .env.'
                );
            }

            return new AiClient(
                baseUrl: (string) config('ai-client.base_url'),
                trustKey: $key,
                timeout: (int) config('ai-client.timeout', 60),
            );
        });

        /*
         * ⚠ THE CONTAINER NEEDS THE OLD NAME TOO, AND `class_alias` IS NOT
         * ENOUGH ON ITS OWN.
         *
         * `src/compat.php` makes the old class name resolve to this one, so
         * type hints and `catch` blocks keep working. But a product that writes
         * `app(AiWaterfallClient::class)` passes the container the LITERAL
         * string `Shirahcan\AiWaterfall\AiWaterfallClient` - because `::class`
         * is compiled from the source text and never consults an alias. Without
         * this line the container would not find that key, would fall back to
         * reflection, and would try to construct the client with no base URL,
         * no trust key and no timeout. Every AI call in that product would die
         * at the container, not at the service.
         *
         * So the alias is the other half of the shim, and it is removed in the
         * same change as `compat.php`.
         */
        $this->app->alias(AiClient::class, self::LEGACY_CLIENT);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-client.php' => config_path('ai-client.php'),
        ], 'ai-client-config');
    }
}
