<?php

namespace Shirahcan\AiClient\Laravel\Tasks;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Shirahcan\AiClient\AiClient;

/**
 * Sync this product's task templates (`resources/ai-tasks/**.md` by default) to ai-service.
 *
 * A template that did not change writes nothing; one that did becomes a new live version (the
 * old one is kept). A malformed file fails the whole run BEFORE anything is sent, so a half-synced
 * set never goes live. Run it after every deploy, like `porter:knowledge-sync`.
 */
class SyncTasksCommand extends Command
{
    protected $signature = 'ai:tasks-sync
        {--path= : the template folder (default config ai-client.tasks.path)}
        {--dry-run : parse and list, send nothing}';

    protected $description = 'Sync this product\'s AI task templates to ai-service (a new version only when one changed)';

    public function handle(AiClient $ai): int
    {
        $path = (string) ($this->option('path') ?: config('ai-client.tasks.path') ?: base_path('resources/ai-tasks'));
        if (! is_dir($path)) {
            $this->error("No task template folder at {$path}.");

            return self::FAILURE;
        }

        $tasks = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) !== 'md' || strtolower($file->getFilename()) === 'readme.md') {
                continue;
            }
            try {
                $task = TaskTemplateFile::parse((string) file_get_contents($file->getPathname()), $file->getFilename());
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if (isset($tasks[$task['key']])) {
                $this->error("Two files claim the task key [{$task['key']}].");

                return self::FAILURE;
            }
            $tasks[$task['key']] = $task;
        }

        ksort($tasks);
        if ($this->option('dry-run')) {
            foreach ($tasks as $key => $task) {
                $this->line(sprintf('%s  (%s%s)', $key, $task['mode'], ! empty($task['sensitive']) ? ', sensitive' : ''));
            }
            $this->info(count($tasks).' template(s) parsed; nothing sent.');

            return self::SUCCESS;
        }

        try {
            $result = $ai->syncTasks(array_values($tasks), 'ai:tasks-sync');
        } catch (\Throwable $e) {
            report($e);
            $this->error('ai-service refused the sync: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Tasks synced: written=%d unchanged=%d', count($result['written'] ?? []), count($result['unchanged'] ?? [])));
        foreach ($result['written'] ?? [] as $w) {
            $this->line("  {$w['key']} -> v{$w['version']}");
        }

        return self::SUCCESS;
    }
}
