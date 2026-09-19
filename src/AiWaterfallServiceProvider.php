<?php

namespace Shirahcan\AiWaterfall;

use Illuminate\Support\ServiceProvider;

class AiWaterfallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-waterfall.php', 'ai-waterfall');

        $this->app->singleton(AiWaterfallClient::class, function () {
            $key = (string) config('ai-waterfall.trust_key', '');

            /*
             * ⚠ FAIL LOUDLY AT RESOLUTION, NOT SILENTLY AT THE FIRST CALL. An
             * unset trust key means this product was never issued one, or its env
             * did not reach the process - both are deployment mistakes, and both
             * are far cheaper to find at boot than as a 401 during a client's
             * question.
             */
            if ($key === '') {
                throw new \RuntimeException(
                    'ai-waterfall: AI_SERVICE_TRUST_KEY is not set. Issue one on the service '
                    .'with `php artisan ai:issue-key <product>` and put it in this app\'s .env.'
                );
            }

            return new AiWaterfallClient(
                baseUrl: (string) config('ai-waterfall.base_url'),
                trustKey: $key,
                timeout: (int) config('ai-waterfall.timeout', 60),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-waterfall.php' => config_path('ai-waterfall.php'),
        ], 'ai-waterfall-config');
    }
}
