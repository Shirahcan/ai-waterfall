<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Shirahcan\AiClient\Assistant\TemplateMerge;

/**
 * A guided Build, step by step (1.80.0, shared console C5; Portify's BuildSpecialization until then). A product's
 * Build calls one step at a time:
 *
 *   start()          which guide applies before the needs step (the person's, the page's own, or the assistant
 *                    picks), with its template laid over it when one is chosen;
 *   adopt()          the assistant's pick from the needs step, when it named a listed guide;
 *   adoptTemplate()  its template pick inside the guide, remembered for the subject so a rebuild keeps it;
 *   theirStructure() the person set the page's own structure, so the guide's sections and checks give way;
 *   forWriter()      the guide and the data fetched for it, as the writer reads it;
 *   check()          the guide's checks on what was written, with no model;
 *   propose()        the host record's fields drafted after a Build that changed the page.
 *
 * The state is a plain array: `applied` (key, name, source, guide), `guide` (the EFFECTIVE guide, a template laid
 * over it, or null), `base` (the guide as stored), `template` ({key, name, source} or null), `menu` (the guides
 * the assistant may pick from, each with its templates), `template_menu`, `template_off` (the person chose none),
 * `own_structure` (the person set the structure), `surface`, `subject_ref` and `host_kind`.
 *
 * The product supplies what is its own: its resolver and switches, the record a page belongs to (its HOST:
 * `{kind, label, record}`), the data a guide fetches, how its documents parse and are checked, and its drafter.
 */
abstract class GuideBuild
{
    /** How the level-1 heading miss opens: the one miss a repair may fix on any ask, since it adds no words. */
    public const MAIN_HEADING_MISS = 'It has a main (level 1) heading';

    abstract protected function resolver(): GuideResolver;

    /** The guides' surface for a Build mode (`page`, `component`, `element`). */
    abstract protected function surfaceFor(string $mode): string;

    /** No guide at all for this document or mode (one element, a template, a page the guides are not for). */
    abstract protected function skips(mixed $page, string $mode): bool;

    /**
     * The record the page belongs to, whose kind picks its default guide and which holds the drafted fields.
     *
     * @return array{kind: string, label: string, record: mixed}|null
     */
    abstract protected function host(mixed $page): ?array;

    abstract protected function templatesOn(): bool;

    /**
     * What the platform fetched for the guide (its `data` sources), as the writer reads it.
     *
     * @param  array<string, mixed>  $guide
     * @param  array{kind: string, label: string, record: mixed}|null  $host
     * @return array<string, mixed>
     */
    abstract protected function gather(array $guide, ?array $host, string $topic): array;

    /**
     * The written document as a tree; [] when it cannot be read.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function nodesOf(?string $source): array;

    /**
     * What the guide wanted and the tree lacks.
     *
     * @param  array<string, mixed>  $guide
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    abstract protected function misses(array $guide, array $nodes): array;

    /**
     * Misses of the page's SHAPE the product adds for a host (Portify: a blog post never has a level-1 heading).
     * Each should open with {@see MAIN_HEADING_MISS} when it is that one, so a repair may fix it on any ask.
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    protected function shapeMisses(array $state, array $nodes): array
    {
        return [];
    }

    /** Drafts the host's fields after a Build; null when the product drafts none. */
    protected function drafter(): ?HostFieldDrafter
    {
        return null;
    }

    /** Whether this document's host fields may be proposed (a record whose fields live elsewhere may not). */
    protected function proposes(mixed $page): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $console  the Build's console block (`subject_ref` when it came from the console)
     * @return array<string, mixed>
     */
    public function start(string $mode, mixed $page, array $console): array
    {
        $surface = $this->surfaceFor($mode);
        $subjectRef = is_string($console['subject_ref'] ?? null) ? $console['subject_ref'] : null;
        $hostKind = $this->host($page)['kind'] ?? null;
        $none = ['applied' => null, 'guide' => null, 'base' => null, 'template' => null, 'menu' => [], 'template_menu' => [], 'template_off' => false, 'surface' => $surface, 'subject_ref' => $subjectRef, 'host_kind' => $hostKind];

        if ($this->skips($page, $mode)) {
            return $none;
        }

        $resolver = $this->resolver();
        $applied = $subjectRef !== null
            ? $resolver->forSubject($subjectRef)
            : $resolver->resolveFor($surface, $hostKind, null);

        if ($applied === null) {
            return $none;
        }

        $base = $applied['base'] ?? $applied['guide'];
        $template = $applied['template'] ?? null;
        $off = (bool) ($applied['template_off'] ?? false);

        return [
            'applied' => $applied,
            'guide' => $applied['guide'],
            'base' => $base,
            'template' => $template,
            'menu' => $applied['source'] === GuideResolver::SOURCE_PORTER ? self::needsMenu($resolver->catalogue($surface)) : [],
            'template_menu' => $base !== null && $template === null && ! $off ? $resolver::templateMenu($base) : [],
            'template_off' => $off,
            'surface' => $surface,
            'subject_ref' => $subjectRef,
            'host_kind' => $hostKind,
        ];
    }

    /**
     * The guides the assistant may pick from, each with the templates inside it, so one needs call picks both.
     *
     * @param  list<array<string, mixed>>  $guides
     * @return list<array<string, mixed>>
     */
    private static function needsMenu(array $guides): array
    {
        return array_map(static function (array $g) {
            $templates = GuideResolver::templateMenu($g);

            return ['key' => (string) $g['key'], 'name' => (string) $g['name'], 'use_when' => (string) ($g['use_when'] ?? '')] + ($templates !== [] ? ['templates' => $templates] : []);
        }, $guides);
    }

    /**
     * What the needs step reads: the guide to follow, or the guides to pick from.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function needsFacts(array $state): array
    {
        if ($state['guide'] !== null) {
            return ['specialization' => GuideResolver::forPrompt($state['guide'])]
                + (($state['template_menu'] ?? []) !== [] ? ['templates' => $state['template_menu']] : []);
        }

        return $state['menu'] !== [] ? ['specializations' => $state['menu']] : [];
    }

    /**
     * The assistant's pick, kept only when it is one of the guides it was offered.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function adopt(array $state, ?string $picked): array
    {
        if ($state['guide'] !== null || $picked === null || $state['menu'] === []) {
            return $state;
        }

        $guide = GuideResolver::find($this->resolver()->catalogue($state['surface']), $picked);
        if ($guide === null) {
            return $state;
        }

        return [
            ...$state,
            'guide' => $guide,
            'base' => $guide,
            'template_menu' => GuideResolver::templateMenu($guide),
            'applied' => ['key' => $guide['key'], 'name' => (string) $guide['name'], 'source' => GuideResolver::SOURCE_PORTER, 'guide' => $guide],
        ];
    }

    /**
     * The assistant's template pick inside the guide, kept only when it is one of the guide's templates, no template
     * is set and the person did not turn templates off. It is REMEMBERED for a console subject, so a later rebuild
     * keeps the format instead of guessing again.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function adoptTemplate(array $state, ?string $picked): array
    {
        if ($picked === null || $state['guide'] === null || ($state['template'] ?? null) !== null || ($state['template_off'] ?? false) || ! $this->templatesOn()) {
            return $state;
        }
        $base = $state['base'] ?? $state['guide'];
        $template = GuideResolver::find((array) ($base['templates'] ?? []), $picked);
        if ($template === null) {
            return $state;
        }

        $guideKey = (string) ($state['applied']['key'] ?? $base['key'] ?? '');
        if (is_string($state['subject_ref'] ?? null) && $guideKey !== '') {
            $this->resolver()->rememberTemplate($state['subject_ref'], $guideKey, (string) $template['key']);
        }

        return [
            ...$state,
            'guide' => TemplateMerge::apply($base, $template),
            'template' => ['key' => (string) $template['key'], 'name' => (string) $template['name'], 'source' => GuideResolver::SOURCE_PORTER],
            'template_menu' => [],
        ];
    }

    /**
     * The person set this page's own structure or length: their structure replaces the guide's sections, and the
     * guide's checks no longer apply, so a page that does what the person asked is never "repaired" back.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function theirStructure(array $state, bool $set): array
    {
        return $state['guide'] === null || ! $set ? $state : [...$state, 'own_structure' => true];
    }

    /**
     * The guide as the writer reads it, with what the platform fetched for it; null when none applies.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public function forWriter(array $state, mixed $page, string $topic): ?array
    {
        if ($state['guide'] === null) {
            return null;
        }

        $prompt = GuideResolver::forPrompt($state['guide']);
        unset($prompt['data']);

        return [
            ...$prompt,
            ...(($state['own_structure'] ?? false) === true ? ['person_set_structure' => true] : []),
            'fetched' => $this->gather($state['guide'], $this->host($page), $topic),
        ];
    }

    /**
     * The step line the console shows: which guide, and why (first person: the console's steps are the assistant's).
     *
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: string}|null
     */
    public function stepLine(array $state, mixed $page): ?array
    {
        if ($state['guide'] === null || $state['applied'] === null) {
            return null;
        }

        $host = $this->host($page);
        $why = match ($state['applied']['source']) {
            GuideResolver::SOURCE_PERSON => 'You chose it for this page.',
            GuideResolver::SOURCE_PAGE => $host !== null ? 'This page is '.$host['label'].'.' : 'It is this page\'s default.',
            default => 'I chose it for this ask.',
        };
        $template = $state['template'] ?? null;
        if ($template !== null) {
            $why .= $template['source'] === GuideResolver::SOURCE_PERSON
                ? ' You chose the '.$template['name'].' template.'
                : ' I picked the '.$template['name'].' template and will keep it for this page (switch with /template).';
        }
        if (($state['own_structure'] ?? false) === true) {
            $why .= ' You set the structure, so I follow yours and the guide fills in only what you left open.';
        }

        return ['Following the '.$state['applied']['name'].' guide'.($template !== null ? ': '.$template['name'] : ''), $why];
    }

    /**
     * The template's reminder for the person, shown in the console after a Build; null when it has none.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: string}|null
     */
    public static function reminderLine(array $state): ?array
    {
        $reminder = trim((string) ($state['guide']['template']['reminder'] ?? ''));

        return $reminder === '' || ($state['template'] ?? null) === null ? null : ['Before you publish', $reminder];
    }

    /** A miss that changes the page's shape only (a heading's level), never its words; repaired on any ask. */
    public static function isShapeMiss(string $miss): bool
    {
        return str_starts_with($miss, self::MAIN_HEADING_MISS);
    }

    /**
     * What the guide wanted and the written document lacks; [] when it passes or nothing applies.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function check(array $state, ?string $source): array
    {
        // The person set the structure: nothing is "repaired" back, the shape checks included.
        $nodes = $state['guide'] === null || ($state['own_structure'] ?? false) === true ? [] : $this->nodesOf($source);
        if ($nodes === []) {
            return [];
        }

        return [...$this->misses($state['guide'], $nodes), ...$this->shapeMisses($state, $nodes)];
    }

    /**
     * The host record's fields drafted from the page just written; null when the guide lists none for it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public function propose(array $state, mixed $page, ?string $source): ?array
    {
        $drafter = $this->drafter();
        if ($drafter === null || ! $this->proposes($page)) {
            return null;
        }

        $host = $state['guide'] === null ? null : $this->host($page);
        if ($host === null || $drafter->fieldsFor($state['guide'], $host['kind']) === []) {
            return null;
        }

        $nodes = $this->nodesOf($source);

        return $nodes === [] ? null : $drafter->draft($state['guide'], $host, $nodes);
    }

    /**
     * What the run's record keeps: which guide and why, never its words.
     *
     * @param  array<string, mixed>  $state
     * @return array{key: ?string, name: string, source: string}|null
     */
    public static function record(array $state): ?array
    {
        return $state['guide'] === null || $state['applied'] === null
            ? null
            : ['key' => $state['applied']['key'], 'name' => $state['applied']['name'], 'source' => $state['applied']['source']]
                + (($state['template'] ?? null) !== null ? ['template' => $state['template']] : []);
    }
}
