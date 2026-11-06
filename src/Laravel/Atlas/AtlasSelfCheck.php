<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Contracts\Auth\Authenticatable;
use Shirahcan\AiClient\Laravel\Atlas\Query\QueryTools;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Runs every question shape Porter's data engine can be asked, against the REAL database, and lists
 * the ones that fail (plan porter-stats-reach 01d).
 *
 * ⚠ THE TESTS CANNOT DO THIS, AND THAT IS WHY IT EXISTS. They run on SQLite, which does not enforce
 * MySQL / MariaDB's ONLY_FULL_GROUP_BY rule, so 75 grouped breakdowns failed on production with every
 * test green, and one dimension read a column a migration had dropped (sweep, 2026-11-06). The
 * builder turns any failure into an "unavailable" target and `report()`s the exception; this listens
 * for that report so the failure carries the database's own words.
 *
 * Read-only by construction: every check goes through {@see QueryTools::runQuery()}, which only selects.
 */
final class AtlasSelfCheck
{
    public function __construct(private AtlasRegistry $registry, private QueryTools $tools) {}

    /**
     * A check the validator REFUSES for this person (a fee column a consultant may not see, a
     * breakdown their role cannot ask for) is counted in `refused`, not `failed`: it is the access
     * rule working, and the model is told why at run time.
     *
     * @return array{checked: int, refused: int, failed: list<array{entity: string, label: string, check: string, error: string}>}
     */
    public function run(string $audience, Authenticatable $user, ?string $entityKey = null): array
    {
        $checked = 0;
        $refused = 0;
        $failed = [];
        $lastError = null;

        $listener = function (MessageLogged $e) use (&$lastError): void {
            if ($e->level === 'error' && $lastError === null) {
                $ex = $e->context['exception'] ?? null;
                $lastError = $ex instanceof Throwable ? $ex->getMessage() : $e->message;
            }
        };
        // Listeners cannot be removed one at a time; this one only writes to a local, so it is inert
        // once the run returns (the command and the tool each run once per process / request).
        Event::listen(MessageLogged::class, $listener);

        foreach ($this->registry->forAudience($audience, $user) as $entity) {
                if ($entityKey !== null && $entity->key !== $entityKey) {
                    continue;
                }

                foreach ($this->checksFor($entity) as $check => $target) {
                    $lastError = null;
                    $checked++;
                    try {
                        $run = $this->tools->runQuery(['targets' => [$target + ['id' => 'check']]], $audience, $user);
                        $preview = $run['preview'];
                        if ($run['result'] === null && isset($preview['hint']) && $lastError === null) {
                            $refused++;

                            continue;
                        }
                        $error = $lastError
                            ?? ($preview['error'] ?? null)
                            ?? (isset($preview['targets'][0]['could_not_read']) ? 'could not be read' : null);
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }

                    if ($error !== null) {
                        $failed[] = ['entity' => $entity->key, 'label' => $entity->plural, 'check' => $check, 'error' => self::firstLine((string) $error)];
                    }
                }
            }

        return ['checked' => $checked, 'refused' => $refused, 'failed' => $failed];
    }

    /**
     * One list, each self-aggregating metric alone, and a count grouped by each groupable dimension.
     *
     * @return array<string, array<string, mixed>>
     */
    private function checksFor(AtlasEntity $entity): array
    {
        $checks = ['list' => ['entity' => $entity->key, 'limit' => 1]];
        $count = null;

        foreach ($entity->metrics as $metric) {
            if (! $metric->aggregate) {
                continue;
            }
            $count ??= $metric->kind === 'count' ? $metric->key : null;
            $checks["metric {$metric->key}"] = ['entity' => $entity->key, 'metrics' => [$metric->key]];
        }

        if ($count !== null) {
            foreach ($entity->dimensions as $dim) {
                if ($dim->groupable) {
                    $checks["{$count} by {$dim->key}"] = ['entity' => $entity->key, 'metrics' => [$count], 'group_by' => [$dim->key], 'limit' => 3];
                }
            }
        }

        return $checks;
    }

    /** The database's own sentence, without the SQL it was given (that can carry values). */
    private static function firstLine(string $error): string
    {
        $error = preg_replace('/\s*\(Connection: .*$/s', '', $error) ?? $error;

        return mb_substr(trim($error), 0, 240);
    }
}
