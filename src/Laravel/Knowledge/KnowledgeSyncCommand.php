<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

use Illuminate\Console\Command;
use Shirahcan\AiClient\AiClient;

/**
 * `ai:knowledge-sync`: publish a product's knowledge to ai-service, and retire every entry whose file is
 * gone. One step, for every product (1.50.0: it also holds back an entry whose `requires_gates` are off,
 * and publishes what a bound {@see KnowledgeSource} adds, such as admin-written entries).
 */
final class KnowledgeSyncCommand extends Command
{
    protected $signature = 'ai:knowledge-sync
        {--path= : the knowledge folder (default: config ai-client.knowledge_path)}
        {--dry-run : report what would be sent, send nothing}';

    protected $description = "Publish this product's knowledge Markdown to ai-service, retiring deleted files.";

    public function handle(AiClient $ai): int
    {
        $dir = (string) ($this->option('path') ?: config('ai-client.knowledge_path', base_path('docs/knowledge')));
        $corpus = new KnowledgeCorpus($dir);
        $docs = $corpus->all();

        if ($docs === []) {
            // ⚠ NOTHING IS RETIRED FROM AN EMPTY READ. A missing folder and "this product publishes
            // nothing" look the same here, and the first would wipe a working index.
            $this->warn("No knowledge files at {$corpus->dir()}; nothing synced, nothing retired.");

            return self::SUCCESS;
        }

        $gates = $this->laravel->bound(KnowledgeGates::class) ? $this->laravel->make(KnowledgeGates::class) : null;
        $held = 0;
        $wire = [];
        foreach ($docs as $doc) {
            $closed = self::firstClosedGate((array) ($doc['meta']['requires_gates'] ?? []), $gates);
            $entry = KnowledgeCorpus::toWire($doc, $closed === null ? 'published' : 'unpublished');
            if ($closed !== null) {
                $entry['extra']['unpublished_reason'] = "gate {$closed} is off";
                $held++;
            }
            $wire[] = $entry;
        }
        foreach ($this->laravel->tagged('ai-client.knowledge.sources') as $source) {
            /** @var KnowledgeSource $source */
            $wire = array_merge($wire, $source->entries());
        }

        if ($this->option('dry-run')) {
            $this->info(count($wire)." entries would be synced ({$held} held back by a switch) from ".$corpus->dir().'.');

            return self::SUCCESS;
        }

        $totals = $ai->knowledge()->syncEntries($wire);
        $retired = $ai->knowledge()->retireEntries(array_column($wire, 'slug'));

        $this->info(sprintf('Knowledge synced: synced=%d unchanged=%d held=%d retired=%d', $totals['synced'], $totals['unchanged'], $held, $retired));

        return self::SUCCESS;
    }

    /**
     * The first required switch that is off or unknown, or null when the entry may be served.
     *
     * ⚠ FAILS CLOSED. An unknown key, or a product that never bound {@see KnowledgeGates}, holds the entry
     * back: a typo must not publish a dark feature's knowledge.
     *
     * @param  array<int, mixed>  $keys
     */
    public static function firstClosedGate(array $keys, ?KnowledgeGates $gates): ?string
    {
        foreach ($keys as $key) {
            if ($gates === null || $gates->isOpen((string) $key) !== true) {
                return (string) $key;
            }
        }

        return null;
    }
}
