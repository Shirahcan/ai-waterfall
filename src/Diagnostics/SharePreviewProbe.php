<?php

namespace Shirahcan\AiClient\Diagnostics;

use DOMDocument;
use DOMXPath;

/**
 * How a link will unfurl when someone shares it (1.70.0, shared program E4e, social cards).
 *
 * "Why doesn't my link show a picture on WhatsApp?" is answered from evidence: the page is read the way the
 * crawlers read it, its share tags are listed, the image is fetched and measured, robots.txt is checked for the
 * image, and each problem becomes one plain finding. Every assistant can use it (Porter for pages, Mira for job
 * posts), so it lives in the kit.
 *
 * ⚠ THE PRODUCT FETCHES. The kit has no network guard of its own and must not grow a second one: the product
 * injects a fetcher that runs its own SSRF guard (Portify: `Porter\Research\Support\SsrfGuard`). The probe never
 * returns raw HTML, only tag values and findings.
 */
final class SharePreviewProbe
{
    /** The crawlers whose robots.txt rules decide whether the image is fetched. */
    public const CRAWLERS = ['facebookexternalhit', 'LinkedInBot', 'Twitterbot'];

    private const PAGE_UA = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /** @var array<string, array{answer: ?array{status: int, type: ?string, body: string}}> robots.txt per site, read once per probe */
    private array $robots = [];

    private const TAGS = [
        'og:title', 'og:description', 'og:url', 'og:site_name', 'og:type',
        'og:image', 'og:image:width', 'og:image:height', 'og:image:type', 'og:image:alt',
        'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:image:alt',
    ];

    /**
     * @param  callable(string $url, string $userAgent, int $maxBytes): ?array{status: int, type: ?string, body: string}  $fetch
     *         null when the address is refused or unreachable
     * @param  int  $whatsAppMaxBytes  WhatsApp drops a preview image over about this many bytes (sources say 300 to 600 KB)
     * @param  int  $largeMinWidth  below this width Facebook and LinkedIn draw a small thumbnail instead of a large card
     */
    public function __construct(
        private $fetch,
        private int $whatsAppMaxBytes = 300_000,
        private int $largeMinWidth = 600,
    ) {}

    /**
     * @return array{url: string, page_status: ?int, tags: array<string, string>, image: ?array{url: string, status: ?int, type: ?string, bytes: ?int, width: ?int, height: ?int}, robots_blocks_image: list<string>, findings: list<array{severity: string, text: string}>}
     */
    public function probe(string $url): array
    {
        $report = ['url' => $url, 'page_status' => null, 'tags' => [], 'image' => null, 'robots_blocks_image' => [], 'findings' => []];
        $page = ($this->fetch)($url, self::PAGE_UA, 2_000_000);

        if ($page === null) {
            return $this->finding($report, 'error', 'The page could not be fetched, so no network can build a preview from it.');
        }

        $report['page_status'] = $page['status'];

        if ($page['status'] >= 400) {
            return $this->finding($report, 'error', "The page answers {$page['status']} to crawlers, so networks show no preview.");
        }

        $tags = $this->tags($page['body']);
        $report['tags'] = $tags;
        $image = $tags['og:image'] ?? $tags['twitter:image'] ?? null;

        if (! isset($tags['og:title'])) {
            $report = $this->finding($report, 'warning', 'There is no og:title, so networks guess a title from the page.');
        }

        if ($image === null) {
            return $this->finding($report, 'error', 'There is no og:image, so the link unfurls as text only on LinkedIn, WhatsApp, Facebook and X.');
        }

        if (! preg_match('#^https?://#i', $image)) {
            $report = $this->finding($report, 'error', "The og:image address is relative ({$image}). Crawlers need a full https:// address.");
            $image = $this->absolute($url, $image);
        }

        if (($tags['twitter:card'] ?? '') !== 'summary_large_image') {
            $report = $this->finding($report, 'warning', 'twitter:card is not summary_large_image, so X shows a small square picture.');
        }

        if (! isset($tags['og:image:alt'])) {
            $report = $this->finding($report, 'info', 'There is no og:image:alt, so screen readers cannot describe the picture.');
        }

        return $this->measure($report, $image);
    }

    /** @return array<string, string> the share tags in the page's head, first value wins */
    private function tags(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $out = [];

        foreach ((new DOMXPath($dom))->query('//meta[@property or @name]') ?: [] as $meta) {
            $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));

            if (in_array($key, self::TAGS, true) && ! isset($out[$key])) {
                $out[$key] = html_entity_decode(trim($meta->getAttribute('content')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $out;
    }

    private function measure(array $report, string $image): array
    {
        $shot = ($this->fetch)($image, self::PAGE_UA, 8_000_000);
        $report['image'] = ['url' => $image, 'status' => $shot['status'] ?? null, 'type' => $shot['type'] ?? null, 'bytes' => null, 'width' => null, 'height' => null];

        foreach (self::CRAWLERS as $crawler) {
            if (! $this->robotsAllow($image, $crawler)) {
                $report['robots_blocks_image'][] = $crawler;
            }
        }

        if ($report['robots_blocks_image'] !== []) {
            $report = $this->finding($report, 'error', 'robots.txt stops '.implode(', ', $report['robots_blocks_image']).' from fetching the image, so those networks show no picture.');
        }

        if ($shot === null || $shot['status'] >= 400) {
            return $this->finding($report, 'error', 'The image could not be fetched'.($shot ? " (it answers {$shot['status']})" : '').', so networks show no picture.');
        }

        $bytes = strlen($shot['body']);
        $size = @getimagesizefromstring($shot['body']) ?: null;
        $report['image']['bytes'] = $bytes;
        $report['image']['width'] = $size[0] ?? null;
        $report['image']['height'] = $size[1] ?? null;

        if ($size === null) {
            return $this->finding($report, 'error', 'The image address does not return a picture ('.($shot['type'] ?? 'unknown type').').');
        }

        if ($bytes > $this->whatsAppMaxBytes) {
            $report = $this->finding($report, 'warning', sprintf('The image is %d KB. WhatsApp drops preview images over about %d KB, so WhatsApp shows the link without it.', intdiv($bytes, 1000), intdiv($this->whatsAppMaxBytes, 1000)));
        }

        if ($size[0] < $this->largeMinWidth) {
            $report = $this->finding($report, 'warning', "The image is {$size[0]}x{$size[1]}. Below {$this->largeMinWidth} px wide, Facebook and LinkedIn show a small thumbnail instead of a large card. Use 1200x630.");
        } elseif (abs($size[0] / max(1, $size[1]) - 1.91) > 0.25) {
            $report = $this->finding($report, 'info', "The image is {$size[0]}x{$size[1]}, not about 1.91:1 (1200x630), so networks crop it.");
        }

        if ($report['findings'] === []) {
            $report = $this->finding($report, 'ok', 'The link has everything the networks need: a title, a full image address, a 1.91:1 picture they can fetch, and a size WhatsApp accepts.');
        }

        return $report;
    }

    /** Whether robots.txt lets this crawler fetch the URL: its own group, else `*`; the longest matching rule wins. */
    private function robotsAllow(string $url, string $crawler): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return true;
        }

        $robotsUrl = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/robots.txt';
        $robots = $this->robots[$robotsUrl] ??= ['answer' => ($this->fetch)($robotsUrl, self::PAGE_UA, 500_000)];
        $robots = $robots['answer'];

        if ($robots === null || $robots['status'] >= 400) {
            return true; // No robots.txt: everything may be fetched.
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $groups = $this->robotsGroups($robots['body']);
        $rules = $groups[strtolower($crawler)] ?? $groups['*'] ?? [];
        $best = ['allow' => true, 'length' => -1];

        foreach ($rules as [$allow, $pattern]) {
            if ($pattern === '') {
                continue; // "Disallow:" with nothing means allow all.
            }

            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';

            if (preg_match($regex, $path) && strlen($pattern) > $best['length']) {
                $best = ['allow' => $allow, 'length' => strlen($pattern)];
            }
        }

        return $best['allow'];
    }

    /** @return array<string, list<array{0: bool, 1: string}>> rules per lower-cased user agent */
    private function robotsGroups(string $body): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inRules = true;

                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field === 'allow', $value];
                }
            }
        }

        return $groups;
    }

    private function absolute(string $base, string $relative): string
    {
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($relative, '/') ? $origin.$relative : $origin.'/'.$relative;
    }

    private function finding(array $report, string $severity, string $text): array
    {
        $report['findings'][] = ['severity' => $severity, 'text' => $text];

        return $report;
    }
}
