<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Shirahcan\AiClient\AiClient;

/**
 * Tells ai-service where this product serves its chat tools, and the secret both sides sign
 * with. Run once per box after `AI_TOOLS_SECRET` is set (and again after the backend's URL
 * moves). `--generate` prints a fresh secret; the secret is never passed on the command line.
 *
 * Needs this product's ai-service ADMIN key (`ai-client.admin_key`).
 */
class RegisterToolHostCommand extends Command
{
    protected $signature = 'ai:tools-register
        {--generate : print a new random secret to set as AI_TOOLS_SECRET, and stop}
        {--disable : keep the row but stop ai-service calling the tools}';

    protected $description = 'Register this product\'s chat tool host (URL + signing secret) in ai-service\'s vault';

    public function handle(AiClient $ai): int
    {
        if ($this->option('generate')) {
            $this->line(Str::random(64));

            return self::SUCCESS;
        }

        $product = (string) config('ai-client.tools.product', '');
        $secret = (string) config('ai-client.tools.secret', '');
        $url = rtrim((string) config('ai-client.tools.host_url', ''), '/');

        if ($product === '') {
            $this->error('ai-client.tools.product (AI_TOOLS_PRODUCT) is unset: the product name ai-service knows this app by.');

            return self::FAILURE;
        }
        if (strlen($secret) < 32) {
            $this->error('AI_TOOLS_SECRET is unset or shorter than 32 characters. Run with --generate, set it, then config:cache.');

            return self::FAILURE;
        }
        if ($url === '') {
            $this->error('AI_TOOLS_HOST_URL (or APP_URL) is unset.');

            return self::FAILURE;
        }
        if (! $ai->canAdminister()) {
            $this->error('This box has no ai-service admin key, so it cannot write the vault.');

            return self::FAILURE;
        }

        try {
            $host = $ai->admin()->setToolHost($product, $url, $secret, ! $this->option('disable'), 'ai:tools-register');
        } catch (\Throwable $e) {
            report($e);
            $this->error('ai-service refused the tool host: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Tool host for %s: %s (%s, secret %s).', $product, $host['base_url'] ?? $url,
            ($host['enabled'] ?? true) ? 'enabled' : 'disabled', ($host['has_secret'] ?? false) ? 'set' : 'missing'));

        return self::SUCCESS;
    }
}
