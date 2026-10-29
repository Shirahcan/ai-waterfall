<?php

namespace Shirahcan\AiClient\Laravel\Tasks;

use InvalidArgumentException;

/**
 * One task template as a product authors it: a Markdown file, synced to ai-service.
 *
 *   ---
 *   key: intake.question
 *   mode: json                  # text | json | structured
 *   route_key: intake.question  # optional
 *   sensitive: false
 *   required_keys: [question]   # optional, json mode
 *   persona: porter             # optional
 *   ---
 *   ## System
 *   You write one intake question...
 *
 *   ## Prompt
 *   Program: {{program}}. Asked so far: {{asked}}.
 *
 *   ## Schema
 *   ```json
 *   {"type": "object", "properties": {"question": {"type": "string"}}}
 *   ```
 *
 * ⚠ THE FILE IS THE AUTHORITY, THE SERVICE ROW IS THE SERVING COPY, exactly as the knowledge
 * corpus works: edit the file, run `ai:tasks-sync`, and a new version goes live.
 */
final class TaskTemplateFile
{
    private const MODES = ['text', 'json', 'structured'];

    /** @return array<string, mixed> the sync payload for one task */
    public static function parse(string $contents, string $where = 'template'): array
    {
        $contents = str_replace("\r\n", "\n", $contents);
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $contents, $m) !== 1) {
            throw new InvalidArgumentException("{$where}: no front matter (--- ... ---) at the top.");
        }

        $meta = self::frontMatter($m[1], $where);
        $sections = self::sections($m[2]);

        $key = (string) ($meta['key'] ?? '');
        if (preg_match('/^[a-z0-9_.:-]{1,64}$/', $key) !== 1) {
            throw new InvalidArgumentException("{$where}: `key` is missing or not a task key.");
        }
        $mode = (string) ($meta['mode'] ?? 'text');
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("{$where}: `mode` must be one of ".implode(', ', self::MODES).'.');
        }
        foreach (['system', 'prompt'] as $required) {
            if (trim($sections[$required] ?? '') === '') {
                throw new InvalidArgumentException("{$where}: the `## ".ucfirst($required).'` section is missing or empty.');
            }
        }

        $schema = null;
        if (isset($sections['schema'])) {
            $json = preg_replace('/\A\s*```(?:json)?\s*|\s*```\s*\z/', '', $sections['schema']);
            $schema = json_decode((string) $json, true);
            if (! is_array($schema)) {
                throw new InvalidArgumentException("{$where}: the `## Schema` section is not valid JSON.");
            }
        }

        return array_filter([
            'key'           => $key,
            'mode'          => $mode,
            'system'        => trim($sections['system']),
            'prompt'        => trim($sections['prompt']),
            'json_schema'   => $schema,
            'schema_name'   => isset($meta['schema_name']) ? (string) $meta['schema_name'] : null,
            'required_keys' => isset($meta['required_keys']) ? (array) $meta['required_keys'] : null,
            'route_key'     => isset($meta['route_key']) ? (string) $meta['route_key'] : null,
            'sensitive'     => (bool) ($meta['sensitive'] ?? false),
            'persona'       => isset($meta['persona']) ? (string) $meta['persona'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private static function frontMatter(string $block, string $where): array
    {
        $meta = [];
        foreach (explode("\n", $block) as $line) {
            $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv) !== 1) {
                throw new InvalidArgumentException("{$where}: cannot read front matter line `{$line}`.");
            }
            $value = trim($kv[2]);
            $meta[$kv[1]] = match (true) {
                $value === 'true'                                   => true,
                $value === 'false'                                  => false,
                str_starts_with($value, '[') && str_ends_with($value, ']') => array_values(array_filter(
                    array_map(static fn ($v) => trim($v, " \t'\""), explode(',', substr($value, 1, -1))),
                    static fn ($v) => $v !== '',
                )),
                default                                             => trim($value, "'\""),
            };
        }

        return $meta;
    }

    /** @return array<string, string> lower-cased heading => body */
    private static function sections(string $body): array
    {
        $out = [];
        $parts = preg_split('/^##\s+(.+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $out[strtolower(trim($parts[$i]))] = $parts[$i + 1];
        }

        return $out;
    }
}
