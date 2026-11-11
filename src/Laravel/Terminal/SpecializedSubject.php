<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * A console subject whose work can follow a specialization (1.64): a named guide for one kind of output.
 * Optional: a product's {@see TerminalSubject} implements it too, and the console then draws which guide
 * applies and lets the person switch it. Without it the console never shows one.
 *
 * The PERSON's choice is stored by the kit beside the subject's brief (`TerminalSessions::specializationChoice`);
 * the product decides what applies, because only it knows the subject's own default (a blog post's page is a
 * blog post) and whether its assistant picks one per ask.
 */
interface SpecializedSubject
{
    /**
     * The guide that applies to the subject now: `{key, name, source}` where `source` is `person` (they chose
     * it), `page` (the subject's own kind decides) or `porter` (the assistant picks per ask, `key` null), or
     * null when none applies (the person turned it off, or there is none).
     *
     * @param  array{key: string, by: ?string, at: ?string}|null  $choice  the person's choice, or null
     * @return array{key: ?string, name: string, source: string}|null
     */
    public function specialization(string $ref, ?array $choice): ?array;

    /**
     * The guides the person may choose from for this subject, active only.
     *
     * @return list<array{key: string, name: string, use_when: string}>
     */
    public function specializations(string $ref): array;
}
