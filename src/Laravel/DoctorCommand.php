<?php

namespace Shirahcan\AiClient\Laravel;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Laravel\Tasks\TaskTemplateFile;
use Throwable;

/**
 * `ai:doctor`: is this product actually wired to ai-service? Checks the key, the service, that
 * every template on disk is live, and the chat tool host's credential when the product has one.
 * Exits non-zero on any failure, so a deploy script can say so loudly.
 *
 * ⚠ IT EXISTS BECAUSE EVERY ONE OF THESE FAILED SILENTLY ON PRODUCTION. finance-suite ran for weeks
 * with no trust key (its callers catch broadly, so every AI feature quietly degraded), and its
 * deploy never synced a template, so its assistant ran a fallback prompt nobody knew about.
 */
class DoctorCommand extends Command
{
    protected $signature = 'ai:doctor
        {--path= : the template folder (default config ai-client.tasks.path)}';

    protected $description = 'Check this product is wired to ai-service: key, reachability, templates live, tool host';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(AiClient $ai): int
    {
        $this->check('trust key', trim((string) config('ai-client.trust_key')) !== '',
            'set', 'AI_SERVICE_TRUST_KEY is empty: run `php artisan ai:issue-key <product>` on the ai-service box');

        $reachable = $ai->isReachable();
        $this->check('service', $reachable, 'reachable at '.config('ai-client.base_url'),
            'not reachable at '.config('ai-client.base_url'));

        $this->templates($ai, $reachable);
        $this->toolHost();

        $this->table(['check', 'result', 'detail'], $this->rows);

        if ($this->failed) {
            $this->error('ai:doctor found a problem. Every AI feature that depends on it is degraded.');

            return self::FAILURE;
        }
        $this->info('ai:doctor: all checks passed.');

        return self::SUCCESS;
    }

    private function templates(AiClient $ai, bool $reachable): void
    {
        $path = (string) ($this->option('path') ?: config('ai-client.tasks.path') ?: base_path('resources/ai-tasks'));
        if (! is_dir($path)) {
            $this->rows[] = ['templates', 'skip', "no template folder at {$path}"];

            return;
        }

        $local = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) !== 'md' || strtolower($file->getFilename()) === 'readme.md') {
                continue;
            }
            try {
                $local[] = TaskTemplateFile::parse((string) file_get_contents($file->getPathname()), $file->getFilename())['key'];
            } catch (InvalidArgumentException $e) {
                $this->check('templates', false, '', $e->getMessage());

                return;
            }
        }

        if (! $reachable) {
            $this->check('templates', false, '', count($local).' on disk, cannot compare: the service is unreachable');

            return;
        }

        try {
            $live = array_column($ai->liveTasks()['tasks'], 'key');
        } catch (Throwable $e) {
            $this->check('templates', false, '', 'the service refused the list: '.$e->getMessage());

            return;
        }

        $missing = array_values(array_diff($local, $live));
        $this->check('templates', $missing === [], count($local).' on disk, all live',
            count($missing).' of '.count($local).' not live (run `php artisan ai:tasks-sync`): '.implode(', ', array_slice($missing, 0, 8)));
    }

    private function toolHost(): void
    {
        $product = trim((string) config('ai-client.tools.product'));
        if ($product === '') {
            $this->rows[] = ['tool host', 'skip', 'no AI_TOOLS_PRODUCT: this product offers no chat tools'];

            return;
        }

        $this->check('tool host', trim((string) config('ai-client.tools.secret')) !== '' && trim((string) config('ai-client.tools.host_url')) !== '',
            "{$product} at ".config('ai-client.tools.host_url'),
            'AI_TOOLS_SECRET or AI_TOOLS_HOST_URL is empty: every tool call is refused (then `php artisan ai:tools-register`)');
    }

    private function check(string $name, bool $ok, string $okDetail, string $failDetail): void
    {
        $this->rows[] = [$name, $ok ? 'ok' : 'FAIL', $ok ? $okDetail : $failDetail];
        $this->failed = $this->failed || ! $ok;
    }
}
