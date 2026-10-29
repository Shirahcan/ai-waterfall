<?php

namespace Shirahcan\AiClient\Laravel\Eval;

use Illuminate\Console\Command;

/**
 * Run this product's assistant eval set through its real chat path and score every reply.
 *
 * The set is a JSON file (`ai-client.eval.file`, default `tests/Eval/assistant_eval.json`):
 * `{items: [{id, audience, prompt, page_route?, expect: {...}}], users: {audience: email},
 * forbid_for_audiences: {audience: [phrases]}}`. The product binds an {@see EvalDriver}.
 *
 * ⚠ IT SPENDS AI MONEY AND WRITES CONVERSATIONS, so it never runs in production.
 */
class EvalCommand extends Command
{
    protected $signature = 'ai:eval
        {--only= : comma-separated item ids, or an audience}
        {--file= : the eval set (default config ai-client.eval.file)}
        {--out= : where to write the JSON report}';

    protected $description = 'Run the assistant eval set through the real chat path and score every reply';

    public function handle(EvalDriver $driver, EvalScorer $scorer): int
    {
        if (app()->isProduction()) {
            $this->error('ai:eval spends AI money and writes eval conversations; it never runs in production.');

            return self::FAILURE;
        }

        $file = (string) ($this->option('file') ?: config('ai-client.eval.file') ?: base_path('tests/Eval/assistant_eval.json'));
        $set = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($set) || ! is_array($set['items'] ?? null)) {
            $this->error("No eval set at {$file}.");

            return self::FAILURE;
        }

        $forbid = (array) ($set['forbid_for_audiences'] ?? []);
        $results = [];
        foreach ($this->filter($set['items'], (string) $this->option('only')) as $item) {
            $results[] = $last = $this->runOne($driver, $scorer, $item, $set, $forbid);
            $this->line(sprintf('%s %-14s %5dms %s', $last['pass'] ? 'PASS' : 'FAIL', $item['id'],
                $last['ms'], $last['pass'] ? '' : implode('; ', $last['failures'])));
        }

        $report = self::report($results);
        $out = (string) ($this->option('out') ?: storage_path('app/ai-eval-'.now()->format('Ymd-His').'.json'));
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        foreach ($report['by_audience'] as $audience => $row) {
            $this->line(sprintf('%-11s %d/%d', $audience, $row['passed'], $row['total']));
        }
        $this->info(sprintf('%d/%d passed (p50 %dms). Report: %s', $report['passed'], $report['total'], $report['p50_ms'], $out));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function filter(array $items, string $only): array
    {
        $wanted = array_filter(array_map('trim', explode(',', $only)));
        if ($wanted === []) {
            return array_values($items);
        }

        return array_values(array_filter($items, static fn (array $i): bool => in_array($i['id'] ?? '', $wanted, true)
            || in_array($i['audience'] ?? '', $wanted, true)));
    }

    /** @return array<string, mixed> */
    private function runOne(EvalDriver $driver, EvalScorer $scorer, array $item, array $set, array $forbid): array
    {
        $base = ['id' => $item['id'] ?? '?', 'audience' => $item['audience'] ?? '?', 'prompt' => $item['prompt'] ?? ''];
        $start = microtime(true);
        try {
            $reply = $driver->answer($item, $set);
        } catch (\Throwable $e) {
            report($e);
            $reply = ['content' => '', 'kind' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $ms = (int) ((microtime(true) - $start) * 1000);

        if ($reply === null) {
            return $base + ['pass' => false, 'failures' => ['cannot run here'], 'ms' => 0];
        }

        return $base + $scorer->score($item, $reply, $forbid) + ['ms' => $ms, 'kind' => $reply['kind'] ?? null,
            'reply' => mb_substr((string) ($reply['content'] ?? ''), 0, 1200), 'error' => $reply['error'] ?? null];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function report(array $results): array
    {
        $byAudience = [];
        foreach ($results as $r) {
            $row = $byAudience[$r['audience']] ?? ['passed' => 0, 'total' => 0];
            $row['total']++;
            $row['passed'] += $r['pass'] ? 1 : 0;
            $byAudience[$r['audience']] = $row;
        }
        $times = array_column($results, 'ms');
        sort($times);

        return [
            'ran_at'      => now()->toIso8601String(),
            'total'       => count($results),
            'passed'      => count(array_filter($results, static fn ($r) => $r['pass'])),
            'p50_ms'      => $times === [] ? 0 : $times[intdiv(count($times), 2)],
            'by_audience' => $byAudience,
            'items'       => $results,
        ];
    }
}
