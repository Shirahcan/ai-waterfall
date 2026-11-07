<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The checks that make a stale knowledge entry a red build instead of a silent lie (1.50.0). A product's test
 * class uses this, supplies its corpus and what it really has, and calls each check.
 *
 * ⚠ KNOWLEDGE THAT NAMES SOMETHING MUST BE PROVEN TO STILL EXIST. Six months after a screen moves, an
 * assistant confidently sending someone to a 404 is worse than having no knowledge at all, because the
 * failure is invisible. Generalised from Portify's `KnowledgeStalenessTest`.
 */
trait ChecksKnowledgeCorpus
{
    /** @return list<array<string, mixed>> */
    abstract protected function knowledgeDocs(): array;

    /** @param  list<string>  $screens  {@see self::nextPages()} */
    protected function assertEveryRouteExists(array $screens): void
    {
        $checked = 0;
        foreach ($this->knowledgeDocs() as $doc) {
            foreach ((array) $doc['requires_routes'] as $route) {
                $checked++;
                Assert::assertTrue(self::screenExists((string) $route, $screens),
                    "Knowledge `{$doc['slug']}` ({$doc['path']}) describes the route `{$route}`, which no longer exists. "
                    .'Restore the screen or update the entry: the assistant must not send a person to a 404.');
            }
        }

        // ⚠ A guard that guards nothing reports green and everyone stops worrying.
        Assert::assertGreaterThan(0, $checked, 'No entry declares `requires_routes`, so this drift guard checked nothing.');
    }

    /** @param  list<string>  $tools  every tool name the assistant really has */
    protected function assertEveryToolExists(array $tools): void
    {
        foreach ($this->knowledgeDocs() as $doc) {
            foreach ((array) $doc['requires_tools'] as $tool) {
                Assert::assertContains($tool, $tools, "Knowledge `{$doc['slug']}` ({$doc['path']}) promises the tool `{$tool}`, which does not exist.");
            }
        }
    }

    /** @param  list<string>  $gates  every registered switch key */
    protected function assertEveryGateIsRegistered(array $gates): void
    {
        foreach ($this->knowledgeDocs() as $doc) {
            foreach ((array) ($doc['meta']['requires_gates'] ?? []) as $key) {
                Assert::assertContains($key, $gates, "Knowledge `{$doc['slug']}` ({$doc['path']}) requires the switch `{$key}`, which is not registered: it would be held back for ever.");
            }
        }
    }

    /** ⚠ Never fix this one by pushing the date without re-checking the figure against its source. */
    protected function assertNoRegulatedEntryExpired(): void
    {
        foreach ($this->knowledgeDocs() as $doc) {
            if ($doc['regulated'] && $doc['expires_at'] !== null) {
                Assert::assertTrue(strtotime((string) $doc['expires_at']) > time(),
                    "Knowledge `{$doc['slug']}` ({$doc['path']}) expired on {$doc['expires_at']}. Re-verify the figure against its source, or delete the entry.");
            }
        }
    }

    /** @param  list<string>  $audiences  the assistant's real audiences */
    protected function assertEveryAudienceIsReal(array $audiences): void
    {
        foreach ($this->knowledgeDocs() as $doc) {
            if ($doc['audiences'] === null) {
                continue;
            }
            Assert::assertNotEmpty($doc['audiences'], "`{$doc['slug']}` declares an EMPTY audience list, so nobody can retrieve it. Omit the key to serve everyone.");
            foreach ($doc['audiences'] as $audience) {
                Assert::assertContains($audience, $audiences, "`{$doc['slug']}` names the unknown audience `{$audience}`.");
            }
        }
    }

    /**
     * Every page route of a Next.js `app` folder. A `(group)` folder adds no segment.
     *
     * @return list<string>
     */
    protected static function nextPages(string $appDir): array
    {
        if (! is_dir($appDir)) {
            return [];
        }

        $routes = [];
        $base = rtrim(str_replace('\\', '/', $appDir), '/');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'page.tsx') {
                continue;
            }
            $relative = trim(substr(dirname(str_replace('\\', '/', $file->getPathname())), strlen($base)), '/');
            $segments = array_values(array_filter(explode('/', $relative), static fn (string $s) => $s !== '' && ! str_starts_with($s, '(')));
            $routes[] = '/'.implode('/', $segments);
        }

        return $routes;
    }

    /**
     * A declared route matches a real screen, or a section with screens beneath it, so an entry may name
     * "/client/documents" without listing every dynamic child.
     *
     * @param  list<string>  $screens
     */
    protected static function screenExists(string $declared, array $screens): bool
    {
        $declared = '/'.trim($declared, '/');
        foreach ($screens as $screen) {
            if ($screen === $declared || str_starts_with($screen, rtrim($declared, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
