<?php

namespace Shirahcan\AiClient\Laravel\Assistant;

use Illuminate\Console\Command;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Assistant\SpecializationCatalogue;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * Publish this product's specialization vocabulary and seed its starting guides (1.64). Run it after every
 * deploy, like `ai:tasks-sync` and `ai:assistant-sync`.
 *
 * ⚠ IT NEVER OVERWRITES AN ADMIN'S EDIT. The vocabulary is code and is replaced each run; a starting guide
 * is created only when its key is absent, so a guide an admin changed (or deleted and re-made) stays theirs.
 */
class SyncSpecializationsCommand extends Command
{
    protected $signature = 'ai:specializations-sync {--dry-run : list what would be sent, send nothing}';

    protected $description = 'Publish this product\'s specialization vocabulary and seed its starting guides in ai-service';

    public function handle(AiClient $ai): int
    {
        $class = config('ai-client.specializations.catalogue');
        if ($class === null || $class === '') {
            $this->warn('No specialization catalogue is named (ai-client.specializations.catalogue). Nothing done.');

            return self::SUCCESS;
        }

        $catalogue = is_string($class) && class_exists($class) ? app($class) : null;
        if (! $catalogue instanceof SpecializationCatalogue) {
            $this->error('Not a specialization catalogue: '.(is_string($class) ? $class : gettype($class)).'.');

            return self::FAILURE;
        }

        $vocabulary = $catalogue->vocabulary();
        $defaults = $catalogue->defaults();
        $this->line('  vocabulary: '.count($vocabulary['surfaces'] ?? []).' places, '.count($vocabulary['hosts'] ?? []).' kinds of page, '.count($vocabulary['data'] ?? []).' data sources');
        foreach (array_keys($defaults) as $key) {
            $this->line("  guide: {$key}");
        }
        if ($this->option('dry-run')) {
            $this->info('Would publish the vocabulary and seed '.count($defaults).' guides.');

            return self::SUCCESS;
        }

        try {
            $ai->specializations()->publishVocabulary($vocabulary, 'sync');
            $created = 0;
            $kept = 0;
            foreach ($defaults as $key => $fields) {
                $out = $ai->specializations()->seed((string) $key, $fields + ['updated_by' => 'sync']);
                $out['kept'] ? $kept++ : $created++;
            }
        } catch (AiUnavailableException $e) {
            $this->error('ai-service refused the specializations: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Specializations synced: vocabulary published, created={$created} kept={$kept}");

        return self::SUCCESS;
    }
}
