<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

use Illuminate\Console\Command;
use Shirahcan\AiClient\AiClient;

/**
 * `ai:knowledge-sync`: publish a product's knowledge folder to ai-service, and retire every entry
 * whose file is gone. For a product with no rules of its own about which entries are served; one
 * that gates entries (Portify) reads the same {@see KnowledgeCorpus} and decides status itself.
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

        if ($this->option('dry-run')) {
            $this->info(count($docs).' entries would be synced from '.$corpus->dir().'.');

            return self::SUCCESS;
        }

        $totals = $ai->knowledge()->syncEntries(array_map(static fn (array $d) => KnowledgeCorpus::toWire($d), $docs));
        $retired = $ai->knowledge()->retireEntries(array_column($docs, 'slug'));

        $this->info(sprintf('Knowledge synced: synced=%d unchanged=%d retired=%d', $totals['synced'], $totals['unchanged'], $retired));

        return self::SUCCESS;
    }
}
