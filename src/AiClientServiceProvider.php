<?php

namespace Shirahcan\AiClient;

use Illuminate\Support\ServiceProvider;
use Shirahcan\AiClient\Laravel\Conversations\HeldConversations;

class AiClientServiceProvider extends ServiceProvider
{
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

            $adminKey = (string) config('ai-client.admin_key', '');

            /*
             * ⚠ AN ADMIN KEY THAT EQUALS THE TRUST KEY IS REFUSED AT BOOT.
             *
             * It is the single most likely misconfiguration here - the same
             * value pasted into both env lines, or a `--admin` key set as
             * AI_SERVICE_TRUST_KEY - and it silently undoes the whole reason
             * there are two: every generation call this product makes would
             * then carry vault-administration rights. Nothing downstream could
             * detect it, because the service only sees one key either way.
             */
            if ($adminKey !== '' && hash_equals($key, $adminKey)) {
                throw new \RuntimeException(
                    'ai-client: AI_SERVICE_ADMIN_KEY must not be the same key as AI_SERVICE_TRUST_KEY. '
                    .'Mint a separate one with `php artisan ai:issue-key <product> --admin`. The key that '
                    .'travels on every generation call must not be able to administer the vault.'
                );
            }

            return new AiClient(
                baseUrl: (string) config('ai-client.base_url'),
                trustKey: $key,
                timeout: (int) config('ai-client.timeout', 60),
                adminKey: $adminKey,
            );
        });

        /*
         * ⚠ SCOPED, NOT A SINGLETON, AND THE DIFFERENCE IS LOAD-BEARING. `HeldConversations`
         * holds the conversations this REQUEST has read, so a list and then one of its rows
         * costs one call. A singleton would survive between requests in a long-lived worker
         * (Octane, a queue process), and the product would then render a title somebody
         * renamed an hour ago, or keep showing a thread retention has already erased. The
         * service is the authority; a copy that outlives the request is a second one.
         */
        $this->app->scoped(HeldConversations::class, fn () => new HeldConversations);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-client.php' => config_path('ai-client.php'),
        ], 'ai-client-config');
    }
}
