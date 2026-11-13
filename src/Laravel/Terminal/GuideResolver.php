<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Illuminate\Support\Facades\Cache;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Assistant\TemplateMerge;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * Which guide a console subject follows (1.69.0, moved from Portify's SpecializationResolver for the shared console,
 * C2b; owner 2026-11-11: "page decides, Porter fills in, the person overrides"):
 *
 *   1. the person's choice in the console (kept per subject beside its /brief): a guide, or `none`;
 *   2. the subject's host record: a blog post's page follows the guide that is the default for blog posts;
 *   3. otherwise the assistant picks one per ask from the active guides (the needs step), or none.
 *
 * Then the template: the person's, or the assistant's remembered pick, laid over the guide ({@see TemplateMerge}).
 *
 * A product supplies its switches, which surface a subject is on, and which kind of record hosts it.
 *
 * ⚠ A GUIDE THAT CANNOT BE READ NEVER FAILS A RUN. ai-service unreachable reads as "no guide" and the run says so
 * in a step; the subject is still written.
 */
abstract class GuideResolver
{
    /** Guides are edited in the shared console (ai-service), so a short cache is the only staleness: a minute. */
    public const CACHE_SECONDS = 60;

    public const SOURCE_PERSON = 'person';

    public const SOURCE_PAGE = 'page';

    public const SOURCE_PORTER = 'porter';

    public function __construct(protected readonly AiClient $ai, protected readonly TerminalSessions $sessions) {}

    /** Whether guides apply at all (the product's gate). */
    abstract protected function guidesOn(): bool;

    /** Whether templates apply inside guides (the product's gate). */
    abstract protected function templatesOn(): bool;

    /** The surface a console subject is on (`page`, `component`, ...), as the product's catalogue names it. */
    abstract protected function surfaceOf(string $subjectRef): string;

    /** The kind of record that hosts the subject (`blog_post`, `landing_page`), or null when it has none. */
    abstract protected function hostKindOf(string $subjectRef): ?string;

    /** The cache key prefix for one product's guide list. */
    protected function cacheKey(string $surface, bool $templates): string
    {
        return 'ai-client:specializations:'.$surface.($templates ? ':templates' : '');
    }

    /**
     * The active guides for one surface, in the service's shape; [] when off or unreachable.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(string $surface): array
    {
        if (! $this->guidesOn()) {
            return [];
        }

        // Templates ride the same read: each guide carries its active templates while they are on.
        $templates = $this->templatesOn();

        try {
            return Cache::remember($this->cacheKey($surface, $templates), self::CACHE_SECONDS,
                fn () => $this->ai->specializations()->list($surface, true, $templates)['specializations']);
        } catch (AiUnavailableException $e) {
            report($e);

            return [];
        }
    }

    /**
     * What applies to a console subject, for the console and the run. `$choice` is the person's choice when the
     * caller already has it; otherwise it is read. `$withTemplate` false skips the template read.
     *
     * @param  array{key: string, by?: ?string, at?: ?string}|null  $choice
     * @return array<string, mixed>|null  {key, name, source, guide} (+ base, template, template_off with a template)
     */
    public function forSubject(string $subjectRef, ?array $choice = null, bool $choiceGiven = false, bool $withTemplate = true): ?array
    {
        if (! $this->guidesOn()) {
            return null;
        }

        if (! $choiceGiven) {
            try {
                $choice = $this->sessions->specializationChoice($subjectRef);
            } catch (AiUnavailableException $e) {
                report($e);
                $choice = null;
            }
        }

        $applied = $this->resolveFor($this->surfaceOf($subjectRef), $this->hostKindOf($subjectRef), $choice['key'] ?? null);

        return $applied === null || ! $withTemplate ? $applied : $this->withTemplate($applied, $this->templateChoice($subjectRef));
    }

    /**
     * The template stored for a subject: the person's, or the assistant's remembered pick; null when templates are
     * off, none is stored, or ai-service cannot be read.
     *
     * @return array{guide: string, key: string, by: ?string, at: ?string, source: string}|null
     */
    public function templateChoice(string $subjectRef): ?array
    {
        if (! $this->templatesOn()) {
            return null;
        }

        try {
            return $this->sessions->templateChoice($subjectRef);
        } catch (AiUnavailableException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Remember the assistant's own template pick for a subject, so a later rebuild keeps the format instead of
     * re-guessing. Never over the person's choice; a failure to reach ai-service only means the next ask picks again.
     */
    public function rememberTemplate(string $subjectRef, string $guide, string $key, string $by = 'Porter'): void
    {
        if (! $this->templatesOn()) {
            return;
        }

        try {
            if ($this->sessions->templateChoice($subjectRef) !== null) {
                return;
            }
            $this->sessions->setTemplate($subjectRef, $guide, $key, $by, TerminalSessions::TEMPLATE_SOURCES[1]);
        } catch (AiUnavailableException $e) {
            report($e);
        }
    }

    /**
     * The applied guide with its template laid over it: `template` = `{key, name, source}` (null when none applies),
     * `guide` = the EFFECTIVE guide ({@see TemplateMerge}) and `base` = the guide as stored, for its template menu.
     * A choice belongs to one guide: a template of another guide, `none`, or one no longer active applies nothing.
     *
     * @param  array<string, mixed>  $applied
     * @param  array{guide: string, key: string, source?: string}|null  $choice
     * @return array<string, mixed>
     */
    public function withTemplate(array $applied, ?array $choice): array
    {
        $base = $applied['guide'];
        // `none` for this guide: the person turned templates off here, so the assistant is not offered one either.
        $off = $choice !== null && $choice['key'] === 'none' && $choice['guide'] === ($applied['key'] ?? null);
        $applied = [...$applied, 'base' => $base, 'template' => null, 'template_off' => $off];

        if ($base === null || $choice === null || ! $this->templatesOn() || $choice['key'] === 'none' || $choice['guide'] !== ($applied['key'] ?? null)) {
            return $applied;
        }

        $template = self::find((array) ($base['templates'] ?? []), $choice['key']);

        if ($template === null) {
            return $applied;
        }

        return [
            ...$applied,
            'guide' => TemplateMerge::apply($base, $template),
            'template' => ['key' => (string) $template['key'], 'name' => (string) $template['name'], 'source' => (string) ($choice['source'] ?? self::SOURCE_PERSON)],
        ];
    }

    /**
     * The guide a kind of record defaults to on a surface, as stored; null when none.
     *
     * @return array<string, mixed>|null
     */
    public function defaultGuideOn(string $surface, string $hostKind): ?array
    {
        foreach ($this->catalogue($surface) as $guide) {
            if (in_array($hostKind, (array) ($guide['defaults_for'] ?? []), true)) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * The rungs: the person's choice, then the host record's default, then "the assistant picks" when any guide is
     * active.
     *
     * @return array{key: ?string, name: string, source: string, guide: ?array<string, mixed>}|null
     */
    public function resolveFor(string $surface, ?string $hostKind, ?string $chosen): ?array
    {
        if (! $this->guidesOn() || $chosen === 'none') {
            return null;
        }

        $guides = $this->catalogue($surface);

        if ($chosen !== null && ($guide = self::find($guides, $chosen)) !== null) {
            return ['key' => $guide['key'], 'name' => (string) $guide['name'], 'source' => self::SOURCE_PERSON, 'guide' => $guide];
        }

        if ($hostKind !== null) {
            foreach ($guides as $guide) {
                if (in_array($hostKind, (array) ($guide['defaults_for'] ?? []), true)) {
                    return ['key' => $guide['key'], 'name' => (string) $guide['name'], 'source' => self::SOURCE_PAGE, 'guide' => $guide];
                }
            }
        }

        return $guides === [] ? null : ['key' => null, 'name' => 'Porter picks', 'source' => self::SOURCE_PORTER, 'guide' => null];
    }

    /**
     * The templates the assistant may pick from inside a guide, as the needs step reads them.
     *
     * @param  array<string, mixed>|null  $guide  the guide as stored (its `templates`)
     * @return list<array{key: string, name: string, use_when: string}>
     */
    public static function templateMenu(?array $guide): array
    {
        return array_values(array_map(static fn (array $t) => ['key' => $t['key'], 'name' => $t['name'], 'use_when' => $t['use_when']], static::templateOptions($guide)));
    }

    /**
     * The templates the console and the creation pickers offer: what a person needs to choose one. A product with a
     * templates switch overrides this to answer [] while it is off.
     *
     * @param  array<string, mixed>|null  $guide  the guide as stored (its `templates`)
     * @return list<array{key: string, name: string, use_when: string, group: string, example_titles: list<string>, starter: string}>
     */
    public static function templateOptions(?array $guide): array
    {
        if ($guide === null) {
            return [];
        }

        return array_values(array_map(static fn (array $t) => [
            'key' => (string) $t['key'],
            'name' => (string) $t['name'],
            'use_when' => (string) ($t['use_when'] ?? ''),
            'group' => (string) ($t['group'] ?? ''),
            'example_titles' => array_values(array_map('strval', (array) ($t['example_titles'] ?? []))),
            'starter' => (string) ($t['starter'] ?? ''),
        ], array_filter((array) ($guide['templates'] ?? []), static fn ($t) => is_array($t) && isset($t['key'], $t['name']))));
    }

    /** @param  list<array<string, mixed>>  $guides */
    public static function find(array $guides, ?string $key): ?array
    {
        foreach ($guides as $guide) {
            if (($guide['key'] ?? null) === $key) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * The guide as the prompts read it: what to follow, never the service's bookkeeping.
     *
     * @param  array<string, mixed>  $guide
     * @return array{name: string, instructions: string, sections: list<array<string, mixed>>, data: list<string>}
     */
    public static function forPrompt(array $guide): array
    {
        $template = is_array($guide['template'] ?? null) ? $guide['template'] : null;

        return [
            'name' => (string) ($guide['name'] ?? ''),
            'instructions' => (string) ($guide['instructions'] ?? ''),
            'sections' => array_values(array_map(static fn (array $s) => array_filter([
                'name' => $s['name'] ?? '',
                'purpose' => ($s['purpose'] ?? '') !== '' ? $s['purpose'] : null,
                'required' => (bool) ($s['required'] ?? false),
                // A template's section says how it is drawn.
                'look' => trim((string) ($s['look'] ?? '')) !== '' ? trim((string) $s['look']) : null,
            ], static fn ($v) => $v !== null), (array) ($guide['sections'] ?? []))),
            'data' => array_values((array) ($guide['data'] ?? [])),
            // The format the subject is written in: its name, its design and what to ask first. Its reminder and field
            // rules are the console's and the field drafts', never a writer's (a reminder must never reach the page).
            ...($template === null ? [] : ['template' => array_filter([
                'name' => $template['name'] ?? '',
                'design' => ($template['design'] ?? []) !== [] ? $template['design'] : null,
                'asks' => ($template['asks'] ?? []) !== [] ? $template['asks'] : null,
            ], static fn ($v) => $v !== null && $v !== '')]),
        ];
    }

    /**
     * The guides the assistant may pick from, as the needs step reads them.
     *
     * @param  list<array<string, mixed>>  $guides
     * @return list<array{key: string, name: string, use_when: string}>
     */
    public static function menu(array $guides): array
    {
        return array_values(array_map(static fn (array $g) => ['key' => (string) $g['key'], 'name' => (string) $g['name'], 'use_when' => (string) ($g['use_when'] ?? '')], $guides));
    }
}
