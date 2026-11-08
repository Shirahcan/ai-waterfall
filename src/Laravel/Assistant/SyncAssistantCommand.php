<?php

namespace Shirahcan\AiClient\Laravel\Assistant;

use Illuminate\Console\Command;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Assistant\Surface;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * Publish this product's assistant to ai-service (1.56): create its record the first time (its name and
 * its product's name from `ai-client.assistant`), and keep its list of surfaces current. Run it after
 * every deploy, like `ai:tasks-sync`.
 *
 * ⚠ IT NEVER OVERWRITES AN ADMIN'S EDIT. Once the record exists its name, look and voice belong to the
 * console; this only refreshes the surfaces, which are code (a surface exists when its class does).
 */
class SyncAssistantCommand extends Command
{
    protected $signature = 'ai:assistant-sync {--dry-run : list what would be sent, send nothing}';

    protected $description = 'Publish this product\'s assistant record (created once) and its surfaces to ai-service';

    public function handle(AiClient $ai): int
    {
        $key = (string) (config('ai-client.assistant.key') ?? '');
        if ($key === '') {
            $this->warn('No assistant is named (AI_ASSISTANT). Nothing done.');

            return self::SUCCESS;
        }

        $surfaces = [];
        foreach ((array) config('ai-client.assistant.surfaces', []) as $class) {
            $surface = is_string($class) && class_exists($class) ? app($class) : null;
            if (! $surface instanceof Surface) {
                $this->error('Not an assistant surface: '.(is_string($class) ? $class : gettype($class)).'.');

                return self::FAILURE;
            }
            $surfaces[] = ['key' => $surface->key(), 'mode' => $surface->mode(), 'label' => $surface->label(), 'description' => $surface->description()];
        }

        try {
            $existing = $ai->assistantRecord($key);
        } catch (AiUnavailableException $e) {
            $this->error('ai-service could not be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $fields = ['surfaces' => $surfaces];
        if ($existing === null) {
            $name = (string) (config('ai-client.assistant.display_name') ?? '');
            $product = (string) (config('ai-client.assistant.product_name') ?? '');
            if ($name === '' || $product === '') {
                $this->error("Assistant [{$key}] does not exist yet: set AI_ASSISTANT_NAME and AI_ASSISTANT_PRODUCT_NAME to create it.");

                return self::FAILURE;
            }
            $fields += array_filter(['display_name' => $name, 'product_name' => $product,
                'voice_persona_key' => config('ai-client.assistant.voice_persona_key')], static fn ($v) => $v !== null && $v !== '');
        }

        foreach ($surfaces as $s) {
            $this->line("  {$s['key']} ({$s['mode']}): {$s['label']}");
        }
        if ($this->option('dry-run')) {
            $this->info(($existing === null ? 'Would create' : 'Would update the surfaces of')." assistant [{$key}].");

            return self::SUCCESS;
        }

        try {
            $ai->publishAssistant($key, $fields);
        } catch (AiUnavailableException $e) {
            $this->error('ai-service refused the assistant: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($existing === null ? 'Created' : 'Updated the surfaces of')." assistant [{$key}] (".count($surfaces).' surfaces).');

        return self::SUCCESS;
    }
}
