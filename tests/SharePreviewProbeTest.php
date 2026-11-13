<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Diagnostics\SharePreviewProbe;

/**
 * SharePreviewProbe (1.70.0, shared program E4e): "why doesn't my link show a picture on WhatsApp?" answered from
 * what the crawlers see. The fetcher is faked; each case is one real reason a link unfurls badly.
 */
class SharePreviewProbeTest extends TestCase
{
    /** @var list<string> */
    private array $fetched = [];

    private function png(int $width, int $height, int $padTo = 0): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $padTo > strlen($bytes) ? $bytes.str_repeat("\0", $padTo - strlen($bytes)) : $bytes;
    }

    private function page(string $head): string
    {
        return "<!doctype html><html><head>{$head}</head><body>Hi</body></html>";
    }

    private const GOOD_HEAD = '<meta property="og:title" content="Spousal sponsorship guide">'
        .'<meta property="og:image" content="https://p.test/card.png"><meta property="og:image:alt" content="The guide&#39;s card">'
        .'<meta name="twitter:card" content="summary_large_image">';

    /** @param array<string, ?array{status: int, type: ?string, body: string}> $answers */
    private function probe(array $answers): SharePreviewProbe
    {
        return new SharePreviewProbe(function (string $url, string $ua, int $max) use ($answers) {
            $this->fetched[] = $url;

            return array_key_exists($url, $answers) ? $answers[$url] : ['status' => 404, 'type' => 'text/plain', 'body' => ''];
        });
    }

    public function test_a_page_with_everything_gets_one_ok_finding_and_its_tags(): void
    {
        $report = $this->probe([
            'https://p.test/guide' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(self::GOOD_HEAD)],
            'https://p.test/card.png' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(1200, 630)],
        ])->probe('https://p.test/guide');

        $this->assertSame([['severity' => 'ok', 'text' => $report['findings'][0]['text']]], $report['findings']);
        $this->assertSame("The guide's card", $report['tags']['og:image:alt']);
        $this->assertSame([1200, 630], [$report['image']['width'], $report['image']['height']]);
        $this->assertSame(1, count(array_filter($this->fetched, fn ($u) => str_ends_with($u, 'robots.txt'))), 'robots.txt is read once');
    }

    public function test_no_image_means_a_text_only_unfurl(): void
    {
        $report = $this->probe(['https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page('<meta property="og:title" content="A">')]])->probe('https://p.test/a');

        $this->assertStringContainsString('text only', end($report['findings'])['text']);
    }

    public function test_an_image_over_300kb_is_named_for_whatsapp(): void
    {
        $report = $this->probe([
            'https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(self::GOOD_HEAD)],
            'https://p.test/card.png' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(1200, 630, 418_000)],
        ])->probe('https://p.test/a');

        $this->assertStringContainsString('418 KB', $report['findings'][0]['text']);
        $this->assertStringContainsString('WhatsApp', $report['findings'][0]['text']);
    }

    public function test_a_relative_image_address_is_an_error_and_still_measured(): void
    {
        $report = $this->probe([
            'https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(str_replace('https://p.test/card.png', '/card.png', self::GOOD_HEAD))],
            'https://p.test/card.png' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(1200, 630)],
        ])->probe('https://p.test/a');

        $this->assertSame('error', $report['findings'][0]['severity']);
        $this->assertStringContainsString('relative', $report['findings'][0]['text']);
        $this->assertSame('https://p.test/card.png', $report['image']['url']);
    }

    public function test_robots_that_block_the_image_are_named_per_crawler_longest_rule_wins(): void
    {
        $report = $this->probe([
            'https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(str_replace('https://p.test/card.png', 'https://p.test/api/og?x=1', self::GOOD_HEAD))],
            'https://p.test/api/og?x=1' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(1200, 630)],
            'https://p.test/robots.txt' => ['status' => 200, 'type' => 'text/plain', 'body' => "User-agent: *\nDisallow: /api/\n\nUser-agent: LinkedInBot\nAllow: /api/og\nDisallow: /api/\n"],
        ])->probe('https://p.test/a');

        $this->assertSame(['facebookexternalhit', 'Twitterbot'], $report['robots_blocks_image']);
    }

    public function test_a_small_or_odd_shaped_picture_is_explained(): void
    {
        $small = $this->probe([
            'https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(self::GOOD_HEAD)],
            'https://p.test/card.png' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(400, 210)],
        ])->probe('https://p.test/a');
        $this->assertStringContainsString('small thumbnail', $small['findings'][0]['text']);

        $square = $this->probe([
            'https://p.test/a' => ['status' => 200, 'type' => 'text/html', 'body' => $this->page(self::GOOD_HEAD)],
            'https://p.test/card.png' => ['status' => 200, 'type' => 'image/png', 'body' => $this->png(1000, 1000)],
        ])->probe('https://p.test/a');
        $this->assertStringContainsString('crop', $square['findings'][0]['text']);
    }

    public function test_a_refused_or_failing_page_says_so(): void
    {
        $this->assertStringContainsString('could not be fetched', (new SharePreviewProbe(fn () => null))->probe('https://p.test/a')['findings'][0]['text']);

        $report = $this->probe(['https://p.test/a' => ['status' => 404, 'type' => 'text/html', 'body' => '']])->probe('https://p.test/a');
        $this->assertStringContainsString('404', $report['findings'][0]['text']);
    }
}
