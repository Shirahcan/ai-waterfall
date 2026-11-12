<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * A console subject whose guide offers TEMPLATES (1.65, owner 2026-11-12): the formats inside a guide ("Story",
 * "How-to guide" under "Blog post"). Optional and additive: a {@see SpecializedSubject} implements it too, and the
 * console then draws which template applies and lets the person switch it. Without it the console shows none.
 *
 * The choice is kept by the kit in its own slot (`TerminalSessions::templateChoice`), beside the guide's; the
 * product decides what applies, because only it knows which guide applies to the subject now.
 */
interface TemplatedSubject extends SpecializedSubject
{
    /**
     * The templates of the guide that applies to the subject now, active only; [] when no guide applies or it
     * has none.
     *
     * @return list<array{key: string, name: string, use_when: string, group: string, example_titles: list<string>, starter: string}>
     */
    public function templates(string $ref): array;

    /**
     * The template that applies now: `{key, name, source}` where `source` is `person` (chosen) or `porter` (the
     * assistant's remembered pick), or null when none applies (none chosen yet, `none`, or the choice belongs to
     * another guide).
     *
     * @param  array{guide: string, key: string, by: ?string, at: ?string, source: string}|null  $choice
     * @return array{key: string, name: string, source: string}|null
     */
    public function template(string $ref, ?array $choice): ?array;

    /** The key of the guide that applies to the subject now, so a template choice is stored under it; null when none. */
    public function templateGuide(string $ref): ?string;
}
