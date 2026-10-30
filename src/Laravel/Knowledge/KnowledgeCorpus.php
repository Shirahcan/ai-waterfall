<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

use Symfony\Component\Yaml\Yaml;

/**
 * A product's assistant knowledge as a folder of Markdown files, each with YAML front matter.
 *
 * Generalised from Portify's `KnowledgeCorpusReader`, so every product authors knowledge the same
 * way and the same mistakes fail the same build:
 *
 * ⚠ THE FILE IS THE AUTHORITY. The service's index is derived from it by `ai:knowledge-sync`;
 * a hand-edited serving row is overwritten on the next sync, and an entry whose file is deleted
 * is retired, so a removed screen is never described again.
 *
 * ⚠ A REGULATED ENTRY MUST EXPIRE. A government fact (`regulated: true`) with no `expires_at`
 * would eventually be served as a confident lie, so reading one throws.
 *
 * ⚠ TWO FILES CLAIMING ONE SLUG THROW. Otherwise one of them silently never reaches the assistant,
 * and which one depends on filesystem order.
 *
 * The keys every product reads are normalised below; anything else in the front matter is kept
 * under `meta`, so a product can enforce its own rules (Portify's `requires_gates`) without a
 * second parser.
 */
final class KnowledgeCorpus
{
    private const REQUIRED = ['slug', 'question', 'description'];

    public function __construct(private readonly string $dir) {}

    public function dir(): string
    {
        return rtrim($this->dir, '/\\');
    }

    /**
     * Every entry, sorted by path. Empty when the folder does not exist.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_dir($this->dir())) {
            return [];
        }

        $docs = array_map(fn (string $path) => $this->parse($path), $this->files());
        $this->assertUniqueSlugs($docs);

        return $docs;
    }

    /**
     * The service's wire shape for one entry (`AiKnowledge::syncEntries()`).
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function toWire(array $doc, string $status = 'published'): array
    {
        return [
            'slug'        => $doc['slug'],
            'status'      => $status,
            'audiences'   => $doc['audiences'] !== [] ? $doc['audiences'] : null,
            'expires_at'  => $doc['expires_at'],
            'question'    => $doc['question'],
            'aliases'     => $doc['aliases'],
            'description' => $doc['description'],
            'answer'      => $doc['answer'],
            // The rest travels as facets the service stores and hands back unread.
            'extra'       => [
                'domain'           => $doc['domain'],
                'topic'            => $doc['topic'],
                'partition'        => $doc['partition'],
                'surfaces'         => $doc['surfaces'],
                'source_ref'       => $doc['source_ref'],
                'source_fingerprint' => $doc['fingerprint'],
                'reviewed_at'      => $doc['reviewed_at'],
                'regulated_advice' => $doc['regulated'],
            ],
        ];
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir(), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            // README is documentation ABOUT the corpus, not an entry in it.
            if ($file->isFile() && $file->getExtension() === 'md' && strcasecmp($file->getFilename(), 'README.md') !== 0) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /** @return array<string, mixed> */
    private function parse(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $relative = trim(str_replace('\\', '/', substr($path, strlen($this->dir()))), '/');

        if (! preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $raw, $m)) {
            throw new \RuntimeException("Knowledge file has no front matter: {$relative}. Every entry needs a `---` block.");
        }

        try {
            $meta = Yaml::parse($m[1]) ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException("Invalid YAML front matter in {$relative}: ".$e->getMessage());
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($meta[$key]) || trim((string) $meta[$key]) === '') {
                throw new \RuntimeException("Knowledge file {$relative} is missing `{$key}`.");
            }
        }

        $answer = trim($m[2]);
        if ($answer === '') {
            throw new \RuntimeException("Knowledge file {$relative} has no answer body.");
        }

        $regulated = (bool) ($meta['regulated'] ?? false);
        $expires = $meta['expires_at'] ?? null;
        if ($regulated && ($expires === null || trim((string) $expires) === '')) {
            throw new \RuntimeException("Knowledge file {$relative} is `regulated: true` but has no `expires_at`.");
        }

        $list = static fn (mixed $v): array => array_values(array_map('strval', (array) ($v ?? [])));

        return [
            'slug'            => (string) $meta['slug'],
            'domain'          => (string) ($meta['domain'] ?? 'platform'),
            'topic'           => (string) ($meta['topic'] ?? 'General'),
            'partition'       => str_contains($relative, '/') ? explode('/', $relative)[0] : 'uncategorised',
            'question'        => (string) $meta['question'],
            'description'     => (string) $meta['description'],
            'answer'          => $answer,
            'aliases'         => $list($meta['aliases'] ?? []),
            // ⚠ NULL means "serves everyone". An explicit list is a HARD filter.
            'audiences'       => isset($meta['audiences']) ? $list($meta['audiences']) : null,
            'surfaces'        => $list($meta['surfaces'] ?? []),
            'requires_routes' => $list($meta['requires_routes'] ?? []),
            'requires_tools'  => $list($meta['requires_tools'] ?? []),
            'reviewed_at'     => self::date($meta['reviewed_at'] ?? null),
            'expires_at'      => self::date($expires),
            'regulated'       => $regulated,
            'source_ref'      => 'corpus:'.$relative,
            // Content hash, so an unchanged file is a no-op on every sync.
            'fingerprint'     => hash('sha256', $raw),
            'path'            => $relative,
            'meta'            => $meta,
        ];
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value)) {
            return date('Y-m-d', $value);
        }
        $parsed = strtotime((string) $value);

        return $parsed === false ? null : date('Y-m-d', $parsed);
    }

    /** @param list<array<string, mixed>> $docs */
    private function assertUniqueSlugs(array $docs): void
    {
        $seen = [];
        foreach ($docs as $doc) {
            if (isset($seen[$doc['slug']])) {
                throw new \RuntimeException("Duplicate knowledge slug `{$doc['slug']}` in {$doc['path']} and {$seen[$doc['slug']]}.");
            }
            $seen[$doc['slug']] = $doc['path'];
        }
    }
}
