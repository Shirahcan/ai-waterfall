<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query;

use Shirahcan\AiClient\Laravel\Atlas\AtlasDimension;
use Shirahcan\AiClient\Laravel\Atlas\AtlasEntity;
use Shirahcan\AiClient\Laravel\Atlas\AtlasRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Shirahcan\AiClient\Time\TimeWindow;
use Shirahcan\AiClient\Time\TimeWindowRefused;
use Shirahcan\AiClient\Time\TimeWindowResolver;

/**
 * Periods on a data question: `window`, `compare` and per-hour / day / week / month buckets
 * (plan porter-stats-reach 02b to 02d).
 *
 * ⚠ THE MODEL SAYS THE PERIOD, THE SERVER COMPUTES IT. A target carries `window` exactly as the person
 * said it ({"last": "25m"}, {"calendar": "this_week"}); the kit's resolver turns that into instants in
 * the asker's zone, and this class turns those into the engine's existing `time_range` on the right
 * date field. The model never writes a timestamp, and every answer can say the window it counted.
 *
 * ⚠ EACH BUCKET IS ITS OWN EXACT WINDOW, NOT A SQL DATE FORMAT. Production is MariaDB with no time-zone
 * tables (named-zone CONVERT_TZ returns NULL, measured 2026-11-06), so the database cannot cut a day at
 * a Lagos or Toronto midnight. Running one count per bucket, with that bucket's local boundaries, is
 * exact across DST changes and half-hour zones and works for every metric, not only additive ones. It
 * costs one count per bucket, which is why buckets are capped.
 */
final class WindowedPlan
{
    public const EVERY = ['hour', 'day', 'week', 'month'];

    /** One count per bucket: enough for "per day this month" or "per hour today", not a year by hour. */
    public const MAX_BUCKETS = 62;

    public function __construct(private AtlasRegistry $registry, private TimeWindowResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $rawPlan
     * @return array{plan: array<string, mixed>, windows: array<string, array<string, string>>, previous: array<string, TimeWindow>, buckets: array<string, list<array{label: string, window: TimeWindow}>>, dimensions: array<string, string>, errors: list<string>}
     */
    public function prepare(array $rawPlan, string $zone, ?DateTimeInterface $now = null): array
    {
        $out = ['plan' => $rawPlan, 'windows' => [], 'previous' => [], 'buckets' => [], 'dimensions' => [], 'errors' => []];

        foreach ((array) ($rawPlan['targets'] ?? []) as $i => $target) {
            if (! is_array($target)) {
                continue;
            }
            $id = (string) ($target['id'] ?? 't'.($i + 1));
            $target['id'] = $id;

            // A bucket entry in group_by is an object, never a field key: take it out before the plan is parsed.
            $every = null;
            if (is_array($target['group_by'] ?? null)) {
                foreach ($target['group_by'] as $k => $g) {
                    if (is_array($g)) {
                        $every = strtolower((string) ($g['every'] ?? ''));
                        $target['window_field'] ??= $g['field'] ?? null;
                        unset($target['group_by'][$k]);
                    }
                }
                $target['group_by'] = array_values($target['group_by']);
            }
            $every ??= isset($target['every']) ? strtolower((string) $target['every']) : null;

            $spec = $target['window'] ?? null;
            $compare = ($target['compare'] ?? null) === 'previous';
            unset($target['window'], $target['compare'], $target['every']);
            $field = isset($target['window_field']) ? (string) $target['window_field'] : null;
            unset($target['window_field']);
            $out['plan']['targets'][$i] = $target;

            if ($spec === null || $spec === '' || $spec === []) {
                if ($every !== null || $compare) {
                    $out['errors'][] = "Target {$id}: a comparison or a per-{$every} breakdown needs a `window` (the period, as the user said it).";
                }

                continue;
            }

            $entity = $this->registry->get((string) ($target['entity'] ?? ''));
            if ($entity === null) {
                continue; // the validator names an unknown record type
            }

            try {
                $window = $this->resolver->resolve(is_array($spec) || is_string($spec) ? $spec : [], $zone, $now);
            } catch (TimeWindowRefused $e) {
                $out['errors'][] = "Target {$id}: ".$e->getMessage();

                continue;
            }

            $dim = $this->timeDimension($entity, $target, $field);
            if ($dim === null) {
                $fields = array_values(array_map(fn (AtlasDimension $d) => $d->key, array_filter($entity->dimensions, fn (AtlasDimension $d) => self::isDate($d))));
                $out['errors'][] = "Target {$id}: {$entity->plural} have no date field to count \"{$window->name}\" by"
                    .($fields === [] ? '.' : '; pass `window_field` as one of: '.implode(', ', $fields).'.');

                continue;
            }

            $out['plan']['targets'][$i]['time_range'] = self::range($dim, $window);
            $out['dimensions'][$id] = $dim->key;
            $out['windows'][$id] = ['label' => $window->label(), 'counted_by' => mb_strtolower($dim->label)];
            if ($compare) {
                $out['previous'][$id] = $window->previous();
            }

            if ($every !== null) {
                if (! in_array($every, self::EVERY, true)) {
                    $out['errors'][] = "Target {$id}: \"{$every}\" is not a bucket; use one of ".implode(', ', self::EVERY).'.';

                    continue;
                }
                $buckets = $this->buckets($window, $every);
                if (count($buckets) > self::MAX_BUCKETS) {
                    $out['errors'][] = "Target {$id}: {$window->name} by {$every} is ".count($buckets).' buckets; the most is '.self::MAX_BUCKETS.'. Use a larger bucket or a shorter period.';

                    continue;
                }
                $out['buckets'][$id] = $buckets;
            }
        }

        return $out;
    }

    /**
     * The window as the engine's `time_range` on one date field.
     *
     * @return array{dimension: string, from: string, to: string}
     */
    public static function range(AtlasDimension $dim, TimeWindow $window): array
    {
        return ['dimension' => $dim->key, 'from' => $window->fromUtc(), 'to' => $window->toUtc()];
    }

    /**
     * The date a period is counted by, decided here and never left to the model: the one it named, else
     * the date the first metric is defined over, else the record type's default sort date, else when the
     * record was created.
     */
    public function timeDimension(AtlasEntity $entity, array $target, ?string $field): ?AtlasDimension
    {
        if ($field !== null && ($d = $entity->dimension($field)) !== null && self::isDate($d)) {
            return $d;
        }

        foreach ((array) ($target['metrics'] ?? []) as $key) {
            $metric = is_string($key) ? $entity->metric($key) : null;
            if ($metric?->timeField === null) {
                continue;
            }
            foreach ($entity->dimensions as $d) {
                if (self::isDate($d) && ($d->key === $metric->timeField || $d->path === $metric->timeField)) {
                    return $d;
                }
            }
        }

        foreach ($entity->dimensions as $d) {
            if (self::isDate($d) && ($d->key === 'created_at' || $d->path === 'created_at')) {
                return $d;
            }
        }

        /*
         * ⚠ NEVER `updated_at`. It is row-modification time and moves whenever any background process
         * writes the row (107 of 181 cases disagreed with their real last move by more than a day,
         * measured), so "cases this week" counted by it is a confident wrong number. With no better
         * date the model is asked to name one.
         */
        $sort = $entity->defaultSort['key'] ?? null;
        if (is_string($sort) && ($d = $entity->dimension($sort)) !== null && self::isDate($d) && $d->path !== 'updated_at') {
            return $d;
        }

        return null;
    }

    /**
     * Local hour / day / week / month buckets covering the window, each clipped to it.
     *
     * @return list<array{label: string, window: TimeWindow}>
     */
    public function buckets(TimeWindow $window, string $every): array
    {
        $z = new DateTimeZone($window->zone);
        $from = $window->from->setTimezone($z);
        $to = $window->to->setTimezone($z);
        $manyDays = $from->format('Y-m-d') !== $to->format('Y-m-d');

        $start = match ($every) {
            'hour' => $from->setTime((int) $from->format('G'), 0),
            'day' => $from->setTime(0, 0),
            'week' => $from->setTime(0, 0)->modify('-'.((int) $from->format('N') - 1).' days'),
            default => $from->setTime(0, 0)->modify('first day of this month'),
        };
        $step = ['hour' => '+1 hour', 'day' => '+1 day', 'week' => '+7 days', 'month' => '+1 month'][$every] ?? '+1 day';

        $out = [];
        for ($s = $start; $s <= $to && count($out) <= self::MAX_BUCKETS; $s = $next) {
            $next = $s->modify($step);
            $bFrom = $s < $from ? $from : $s;
            $bTo = $next->modify('-1 second') > $to ? $to : $next->modify('-1 second');
            $label = match ($every) {
                'hour' => $manyDays ? $s->format('D, M j, g A') : $s->format('g A'),
                'day' => $s->format('D, M j'),
                'week' => 'week of '.$s->format('M j'),
                default => $s->format('M Y'),
            };
            $out[] = ['label' => $label, 'window' => new TimeWindow(
                DateTimeImmutable::createFromInterface($bFrom), DateTimeImmutable::createFromInterface($bTo),
                $window->zone, TimeWindow::KIND_RANGE, $label, [],
            )];
        }

        return $out;
    }

    private static function isDate(AtlasDimension $d): bool
    {
        return in_array($d->type, ['datetime', 'date'], true);
    }
}
