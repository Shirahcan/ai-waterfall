<?php

namespace Shirahcan\AiClient\Laravel\Assistant;

use Illuminate\Console\Command;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Assistant\SpecializationCatalogue;
use Shirahcan\AiClient\Assistant\TemplateCatalogue;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * Publish this product's specialization vocabulary and seed its starting guides (1.64). Run it after every
 * deploy, like `ai:tasks-sync` and `ai:assistant-sync`.
 *
 * ⚠ IT NEVER OVERWRITES AN ADMIN'S EDIT. The vocabulary is code and is replaced each run; a starting guide is
 * created when its key is absent and refreshed (1.64.1) only while it still carries the sync's own `updated_by`,
 * so a guide an admin changed (or deleted and re-made) stays theirs.
 */
class SyncSpecializationsCommand extends Command
{
    /** Who the starting guides are written by: a guide still carrying it has not been changed by an admin. */
    public const SEEDER = 'sync';

    protected $signature = 'ai:specializations-sync {--dry-run : list what would be sent, send nothing}';

    protected $description = 'Publish this product\'s specialization vocabulary and seed (or refresh, while unedited) its starting guides in ai-service';

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
        // 1.65: the formats inside each guide, when the catalogue offers them.
        $templates = $catalogue instanceof TemplateCatalogue ? $catalogue->templates() : [];
        $this->line('  vocabulary: '.count($vocabulary['surfaces'] ?? []).' places, '.count($vocabulary['hosts'] ?? []).' kinds of page, '.count($vocabulary['data'] ?? []).' data sources');
        foreach (array_keys($defaults) as $key) {
            $this->line("  guide: {$key}");
        }
        foreach ($templates as $guide => $rows) {
            foreach (array_keys($rows) as $key) {
                $this->line("  template: {$guide}/{$key}");
            }
        }
        if ($this->option('dry-run')) {
            $this->info('Would publish the vocabulary and seed '.count($defaults).' guides and '.array_sum(array_map('count', $templates)).' templates.');

            return self::SUCCESS;
        }

        $guideCounts = ['created' => 0, 'refreshed' => 0, 'unchanged' => 0, 'kept' => 0];
        $templateCounts = $guideCounts;
        $seededGuides = [];
        try {
            $ai->specializations()->publishVocabulary($vocabulary, self::SEEDER);
            foreach ($defaults as $key => $fields) {
                // The seeder's own name always: a catalogue cannot pass a guide off as an admin's.
                $out = $ai->specializations()->seed((string) $key, ['updated_by' => self::SEEDER] + $fields);
                $guideCounts[self::outcome($out)]++;
                $seededGuides[] = (string) $key;
            }
        } catch (AiUnavailableException $e) {
            $this->error('ai-service refused the specializations: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($templates as $guide => $rows) {
            $position = 0;
            foreach ($rows as $key => $fields) {
                $position++;
                try {
                    $out = $ai->specializations()->seedTemplate((string) $guide, (string) $key, ['updated_by' => self::SEEDER, 'position' => $position] + $fields);
                    $templateCounts[self::outcome($out)]++;
                } catch (AiUnavailableException $e) {
                    // One refused template (or a guide an admin deleted) never stops the rest.
                    $this->warn("  template {$guide}/{$key} was refused: ".$e->getMessage().(in_array((string) $guide, $seededGuides, true) ? '' : ' (its guide is not one this product seeds)'));
                }
            }
        }

        $this->info("Specializations synced: vocabulary published, created={$guideCounts['created']} refreshed={$guideCounts['refreshed']} unchanged={$guideCounts['unchanged']} kept={$guideCounts['kept']} (kept = changed by an admin)");
        if ($templates !== []) {
            $this->info("Templates synced: created={$templateCounts['created']} refreshed={$templateCounts['refreshed']} unchanged={$templateCounts['unchanged']} kept={$templateCounts['kept']}");
        }

        return self::SUCCESS;
    }

    /** @param  array{kept: bool, refreshed?: bool, created?: bool}  $out */
    private static function outcome(array $out): string
    {
        return match (true) {
            $out['kept'] => 'kept',
            $out['refreshed'] ?? false => 'refreshed',
            $out['created'] ?? false => 'created',
            default => 'unchanged',
        };
    }
}
