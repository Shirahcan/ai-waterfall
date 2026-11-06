<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\ColumnMeta;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryPlan;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\QueryResultSet;
use Shirahcan\AiClient\Laravel\Atlas\Query\Dto\TargetResult;
use Throwable;

/**
 * The period before and the per-bucket series for windowed targets (plan porter-stats-reach 02c, 02d).
 *
 * ⚠ THE ENGINE COMPUTES EVERY CHANGE. "Up 3 on the 4 days before" is a subtraction a model will get
 * wrong often enough to matter, so the figures arrive already compared, with the rule to report them
 * as given. All the extra windows run as ONE plan through the same validator and executor, so the
 * row scopes are exactly the main question's.
 */
final class WindowedResults
{
    public function __construct(private PlanValidator $validator, private QueryExecutor $executor) {}

    /**
     * @param  array<string, mixed>  $preview  the model's preview, enriched in place
     * @param  array<string, mixed>  $windowed  {@see WindowedPlan::prepare()}
     */
    public function enrich(array &$preview, QueryResultSet $resultSet, array $windowed, string $audience, Authenticatable $user): QueryResultSet
    {
        $raw = [];
        foreach ((array) ($windowed['plan']['targets'] ?? []) as $t) {
            if (is_array($t) && isset($t['id'])) {
                $raw[(string) $t['id']] = $t;
            }
        }

        $extra = [];
        foreach ($windowed['previous'] as $id => $prev) {
            $extra[] = $this->retimed($raw[$id] ?? null, "{$id}__previous", $windowed['dimensions'][$id] ?? null, $prev->fromUtc(), $prev->toUtc());
        }
        foreach ($windowed['buckets'] as $id => $buckets) {
            foreach ($buckets as $k => $b) {
                $extra[] = $this->retimed($raw[$id] ?? null, "{$id}__b{$k}", $windowed['dimensions'][$id] ?? null, $b['window']->fromUtc(), $b['window']->toUtc());
            }
        }
        $extra = array_values(array_filter($extra));
        if ($extra === []) {
            return $resultSet;
        }

        try {
            $validated = $this->validator->validate(QueryPlan::fromArray(['targets' => $extra]), $audience, $user);
            $byId = [];
            foreach ($validated->plan !== null ? $this->executor->execute($validated->plan, $audience, $user)->targets : [] as $t) {
                $byId[$t->id] = $t;
            }
        } catch (Throwable $e) {
            report($e);
            $byId = [];
        }

        $main = [];
        foreach ($resultSet->targets as $t) {
            $main[$t->id] = $t;
        }

        $seriesTargets = [];
        foreach ($preview['targets'] as $i => $p) {
            $id = (string) ($p['id'] ?? '');
            $target = $main[$id] ?? null;
            if ($target === null || $target->status !== TargetResult::STATUS_OK) {
                continue;
            }
            $groups = array_values(array_map('strval', (array) ($raw[$id]['group_by'] ?? [])));
            $metrics = self::metricKeys($target, $groups);

            if (isset($windowed['previous'][$id])) {
                $before = $byId["{$id}__previous"] ?? null;
                $preview['targets'][$i] = $this->withPrevious($p, $target, $before, $groups, $metrics, $windowed['previous'][$id]->label());
            }

            if (isset($windowed['buckets'][$id])) {
                [$preview['targets'][$i], $series] = $this->withSeries($preview['targets'][$i], $target, $windowed['buckets'][$id], $byId, $groups, $metrics);
                if ($series !== null) {
                    $seriesTargets[] = $series;
                }
            }
        }

        return new QueryResultSet(array_merge($resultSet->targets, $seriesTargets), $resultSet->presentation);
    }

    /** @param  array<string, mixed>|null  $target */
    private function retimed(?array $target, string $id, ?string $dimension, string $from, string $to): ?array
    {
        if ($target === null || $dimension === null) {
            return null;
        }
        $target['id'] = $id;
        $target['time_range'] = ['dimension' => $dimension, 'from' => $from, 'to' => $to];
        unset($target['offset'], $target['link']);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<string>  $groups
     * @param  list<string>  $metrics
     * @return array<string, mixed>
     */
    private function withPrevious(array $p, TargetResult $now, ?TargetResult $before, array $groups, array $metrics, string $label): array
    {
        if ($before === null || $before->status !== TargetResult::STATUS_OK) {
            $p['previous'] = ['window' => $label, 'could_not_read' => 'The period before could not be read; give the current figure only.'];

            return $p;
        }

        $key = fn (array $row) => json_encode(array_map(fn ($g) => $row[$g] ?? null, $groups));
        $prior = [];
        foreach ($before->rows as $row) {
            $prior[$key($row)] = $row;
        }

        if ($groups === []) {
            $cur = $now->rows[0] ?? [];
            $old = $before->rows[0] ?? [];
            $p['previous'] = ['window' => $label] + array_combine($metrics, array_map(fn ($m) => $old[$m] ?? 0, $metrics));
            $p['change'] = array_combine($metrics, array_map(fn ($m) => self::change($cur[$m] ?? 0, $old[$m] ?? 0), $metrics));
        } else {
            foreach ($now->rows as $r => $row) {
                if (! isset($p['rows'][$r])) {
                    break;
                }
                $old = $prior[$key($row)] ?? [];
                foreach ($metrics as $m) {
                    $p['rows'][$r]["previous_{$m}"] = $old[$m] ?? 0;
                    $p['rows'][$r]["change_{$m}"] = self::change($row[$m] ?? 0, $old[$m] ?? 0);
                }
            }
            $p['previous'] = ['window' => $label];
        }
        $p['compare_rule'] = 'The change is already computed against '.$label.'. Report it exactly as given; never work out a difference yourself.';

        return $p;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<array{label: string, window: \Shirahcan\AiClient\Time\TimeWindow}>  $buckets
     * @param  array<string, TargetResult>  $byId
     * @param  list<string>  $groups
     * @param  list<string>  $metrics
     * @return array{0: array<string, mixed>, 1: ?TargetResult}
     */
    private function withSeries(array $p, TargetResult $main, array $buckets, array $byId, array $groups, array $metrics): array
    {
        $series = [];
        $rows = [];
        $unread = 0;
        foreach ($buckets as $k => $b) {
            $t = $byId["{$main->id}__b{$k}"] ?? null;
            if ($t === null || $t->status !== TargetResult::STATUS_OK) {
                $unread++;
                $series[] = ['period' => $b['label'], 'could_not_read' => true];

                continue;
            }
            if ($groups === []) {
                $row = ['period' => $b['label']] + array_combine($metrics, array_map(fn ($m) => $t->rows[0][$m] ?? 0, $metrics));
                $series[] = $row;
                $rows[] = ['id' => "{$main->id}-{$k}"] + $row;
            } else {
                $series[] = ['period' => $b['label'], 'rows' => array_slice($t->rows, 0, 10)];
                foreach ($t->rows as $j => $r) {
                    $rows[] = ['id' => "{$main->id}-{$k}-{$j}", 'period' => $b['label']] + $r;
                }
            }
        }

        $p['series'] = $series;
        $p['series_rule'] = 'One row per period, oldest first; a period with nothing in it is a real 0. '
            .($unread > 0 ? "{$unread} period(s) could not be read: say so, never treat them as 0. " : '')
            .'Report the figures as given.';

        $columns = array_merge([new ColumnMeta(key: 'period', label: 'Period', type: 'string')], $main->columns);

        return [$p, new TargetResult(
            id: "{$main->id}_series",
            entity: $main->entity,
            columns: $columns,
            rows: $rows,
            total: count($rows),
            interpretation: $main->interpretation.', per period',
        )];
    }

    /**
     * The numeric columns of a result that are not group labels: the metrics.
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    private static function metricKeys(TargetResult $t, array $groups): array
    {
        return array_values(array_map(fn (ColumnMeta $c) => $c->key, array_filter($t->columns,
            fn (ColumnMeta $c) => in_array($c->type, ['number', 'money'], true) && ! in_array($c->key, $groups, true))));
    }

    /** "+3 (+25%)", "-2 (-40%)", "+5 (none before)", "no change". */
    public static function change(mixed $now, mixed $before): string
    {
        if (! is_numeric($now) || ! is_numeric($before)) {
            return 'not comparable';
        }
        $d = (float) $now - (float) $before;
        if (abs($d) < 0.000001) {
            return 'no change';
        }
        $sign = $d > 0 ? '+' : '-';
        $abs = abs($d);
        $num = floor($abs) == $abs ? number_format($abs) : number_format($abs, 2);
        if ((float) $before == 0.0) {
            return "{$sign}{$num} (none before)";
        }

        return sprintf('%s%s (%s%d%%)', $sign, $num, $sign, (int) round($abs / abs((float) $before) * 100));
    }
}
