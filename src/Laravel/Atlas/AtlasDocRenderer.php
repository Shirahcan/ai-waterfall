<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * Renders the human-readable atlas doc (`docs/porter/atlas.md`) from the live
 * catalog so it can never drift. Pure: the caller supplies the date (no
 * `now()` here) to keep output deterministic and testable.
 */
final class AtlasDocRenderer
{
    public function render(AtlasRegistry $registry, ?string $date = null): string
    {
        $date ??= '';
        $out = [];
        $out[] = '# Porter Atlas - queryable catalog';
        $out[] = '';
        $out[] = '> GENERATED FILE - do not edit by hand. Run `php artisan porter:atlas-doc` to regenerate.';
        $out[] = '> The broader human map of the admin portal lives in `docs/porter/admin-portal-map.md`.';
        $out[] = '>';
        $out[] = '> Generated: '.$date;
        $out[] = '';

        $entities = $registry->all();

        if ($entities === []) {
            $out[] = '_No entities registered yet._';
            $out[] = '';

            return implode("\n", $out);
        }

        foreach ($entities as $entity) {
            $out[] = '## '.$entity->key;
            $out[] = '';
            $out[] = '- **Label:** '.$entity->label.' / '.$entity->plural;
            $out[] = '- **Model:** `'.$entity->modelClass.'`';
            $out[] = '- **Audiences:** '.implode(', ', $entity->audiences);
            $out[] = '- **Permission:** '.($entity->permission ?? '_none_');
            $out[] = '- **Row-scoped audiences:** '.($entity->rowScopes === [] ? '_none_' : implode(', ', array_keys($entity->rowScopes)));
            $out[] = '';

            $out = array_merge($out, $this->dimensionsTable($entity->dimensions));
            $out = array_merge($out, $this->metricsTable($entity->metrics));
            $out = array_merge($out, $this->relationsTable($entity->relations));

            if ($entity->deepLinks !== []) {
                $out[] = '**Deep links:**';
                $out[] = '';
                foreach ($entity->deepLinks as $audience => $template) {
                    $out[] = '- '.$audience.': `'.$template.'`';
                }
                $out[] = '';
            }

            if ($entity->sampleQuestions !== []) {
                $out[] = '**Sample questions:** '.implode('; ', $entity->sampleQuestions);
                $out[] = '';
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param  list<AtlasDimension>  $dimensions
     * @return list<string>
     */
    private function dimensionsTable(array $dimensions): array
    {
        if ($dimensions === []) {
            return [];
        }

        $rows = ['**Dimensions:**', '', '| key | label | type | filterable | groupable | sensitive |', '|---|---|---|---|---|---|'];
        foreach ($dimensions as $d) {
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $d->key,
                $d->label,
                $d->type,
                $d->filterable ? 'yes' : 'no',
                $d->groupable ? 'yes' : 'no',
                $d->sensitive ? '🔒' : '',
            );
        }
        $rows[] = '';

        return $rows;
    }

    /**
     * @param  list<AtlasMetric>  $metrics
     * @return list<string>
     */
    private function metricsTable(array $metrics): array
    {
        if ($metrics === []) {
            return [];
        }

        $rows = ['**Metrics:**', '', '| key | label | kind | format | currency | time field | permission |', '|---|---|---|---|---|---|---|'];
        foreach ($metrics as $m) {
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s |',
                $m->key,
                $m->label,
                $m->kind,
                $m->format,
                $m->currency ?? '',
                $m->timeField ?? '',
                $m->permission ?? '',
            );
        }
        $rows[] = '';

        return $rows;
    }

    /**
     * @param  list<AtlasRelation>  $relations
     * @return list<string>
     */
    private function relationsTable(array $relations): array
    {
        if ($relations === []) {
            return [];
        }

        $rows = ['**Relations:**', '', '| key | target | type |', '|---|---|---|'];
        foreach ($relations as $r) {
            $rows[] = sprintf('| %s | %s | %s |', $r->key, $r->targetEntity, $r->type);
        }
        $rows[] = '';

        return $rows;
    }
}
