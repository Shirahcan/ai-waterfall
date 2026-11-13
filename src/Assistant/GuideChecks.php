<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * The checks a guide asks for after a run (1.68.0, moved from Portify's SpecializationChecks for the shared
 * console, C2a), with NO model: how many words the readers see, whether there is a call to action, and whether
 * each required section is there.
 *
 * The product reads its own document (a PML tree, an email body) into three facts: the words, whether a reader
 * can act, and the headings. The rules here are the same for every product, so a guide behaves the same
 * wherever it runs.
 *
 * A section is found by its HEADING words (`look_for`). A required section with no heading words to look for is
 * guidance only and is not checked, because an opening answer or a closing action rarely carries a heading.
 */
final class GuideChecks
{
    /**
     * @param  array<string, mixed>  $guide  the service's guide (a template already laid over it, when chosen)
     * @param  list<string>  $headings  every heading the reader sees, as words
     * @return list<string> what the guide wanted and the document lacks, in plain words; empty when it passes
     */
    public static function judge(array $guide, int $words, bool $action, array $headings): array
    {
        $checks = (array) ($guide['checks'] ?? []);
        $misses = [];

        // A template's length is its own: the line names it, not "the guide".
        $asker = is_string($guide['template']['name'] ?? null) && $guide['template']['name'] !== '' ? 'the '.$guide['template']['name'].' template' : 'the guide';
        $min = isset($checks['min_words']) && (int) $checks['min_words'] > 0 ? (int) $checks['min_words'] : null;
        $max = isset($checks['max_words']) && (int) $checks['max_words'] > 0 ? (int) $checks['max_words'] : null;

        if ($min !== null && $words < $min) {
            $misses[] = "It has about {$words} words; {$asker} asks for at least {$min}.";
        }

        if ($max !== null && $words > $max) {
            $misses[] = "It has about {$words} words; {$asker} asks for at most {$max}.";
        }

        if (($checks['require_cta'] ?? false) === true && ! $action) {
            $misses[] = 'It has no call to action (a button, a form or a booking link).';
        }

        if (($checks['require_sections'] ?? false) === true) {
            $text = mb_strtolower(implode("\n", $headings));

            foreach ((array) ($guide['sections'] ?? []) as $section) {
                $lookFor = array_values(array_filter(array_map(static fn ($w) => mb_strtolower(trim((string) $w)), (array) ($section['look_for'] ?? [])), static fn ($w) => $w !== ''));

                if (! ($section['required'] ?? false) || $lookFor === []) {
                    continue;
                }

                $found = false;
                foreach ($lookFor as $word) {
                    if (str_contains($text, $word)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $misses[] = "The \"{$section['name']}\" section is missing (no heading with ".implode(' or ', array_map(static fn ($w) => "\"{$w}\"", $lookFor)).').';
                }
            }
        }

        return $misses;
    }

    /**
     * The words a reader sees in `$text` (markup and Markdown punctuation set aside), each with a letter or a digit.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $plain = strip_tags($text);
        $plain = (string) preg_replace('/[#*_`>\[\]\(\)|~-]+/u', ' ', $plain);

        return array_values(array_filter(preg_split('/\s+/u', $plain) ?: [], static fn (string $w) => preg_match('/[\p{L}\p{N}]/u', $w) === 1));
    }

    /**
     * The misses as one instruction for the ONE repair: what to add or trim, in the guide's words.
     *
     * @param  list<string>  $misses
     */
    public static function repairAsk(array $misses, string $what = 'page'): string
    {
        return "The {$what} does not yet meet its guide. Fix only this, keep everything else: ".implode(' ', $misses);
    }
}
