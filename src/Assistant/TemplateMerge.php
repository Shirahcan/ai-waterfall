<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * A guide with a template laid over it (1.68.0, moved from Portify for the shared console, C2a): the EFFECTIVE
 * guide a run follows. Everything downstream (the needs step, the writers, the checks, the host-field drafts) reads
 * it exactly as it reads a guide, so a template changes the work without a second code path.
 *
 *   sections      the template's, when it has any (each carries its `look`), else the guide's;
 *   instructions  the guide's, then the template's (the guide's rules always hold; the template adds the format's);
 *   checks        the guide's, with every value the template SETS over it (a key it leaves out stays the guide's);
 *   data          the template's list when it has one, else the guide's;
 *   template      `{key, name, design, asks, reminder, field_rules}`: what the prompts and the Build read from it.
 *
 * Pure: no I/O, so the same guide and template always give the same effective guide.
 */
final class TemplateMerge
{
    /**
     * @param  array<string, mixed>  $guide  the service's guide
     * @param  array<string, mixed>  $template  one of its templates, in the service's wire shape
     * @return array<string, mixed>
     */
    public static function apply(array $guide, array $template): array
    {
        $sections = array_values(array_filter((array) ($template['sections'] ?? []), static fn ($s) => is_array($s) && trim((string) ($s['name'] ?? '')) !== ''));
        $checks = array_merge((array) ($guide['checks'] ?? []), array_filter((array) ($template['checks'] ?? []), static fn ($v) => $v !== null));
        $instructions = trim(implode(' ', array_filter([trim((string) ($guide['instructions'] ?? '')), trim((string) ($template['instructions'] ?? ''))], static fn (string $s) => $s !== '')));

        return [
            ...$guide,
            'instructions' => $instructions,
            'sections' => $sections !== [] ? $sections : (array) ($guide['sections'] ?? []),
            'checks' => $checks,
            'data' => is_array($template['data'] ?? null) ? array_values($template['data']) : (array) ($guide['data'] ?? []),
            'template' => self::block($template),
        ];
    }

    /**
     * What a prompt and the Build read from the template (never its bookkeeping).
     *
     * @param  array<string, mixed>  $template
     * @return array{key: string, name: string, design: array<string, string>, asks: list<array<string, mixed>>, reminder: string, field_rules: array<string, string>}
     */
    public static function block(array $template): array
    {
        $design = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', (array) ($template['design'] ?? [])), static fn (string $v) => $v !== '');

        return [
            'key' => (string) ($template['key'] ?? ''),
            'name' => (string) ($template['name'] ?? ''),
            'design' => $design,
            'asks' => array_values(array_filter((array) ($template['asks'] ?? []), static fn ($a) => is_array($a) && trim((string) ($a['question'] ?? '')) !== '')),
            'reminder' => trim((string) ($template['reminder'] ?? '')),
            'field_rules' => array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', (array) ($template['field_rules'] ?? [])), static fn (string $v) => $v !== ''),
        ];
    }
}
