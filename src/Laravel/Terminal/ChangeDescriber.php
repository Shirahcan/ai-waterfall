<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * "What changed" for a console subject (1.71.0, shared console C3): which parts an ask changed, by each part's
 * PERMANENT id, so the console names what the person sees (each one clickable), never a diff of code.
 *
 * The document's language describes it (PML's describer serves every PML document: pages, components, posts,
 * landing pages, cards).
 */
interface ChangeDescriber
{
    /**
     * @param  array<int|string, mixed>  $before  the subject as the rewind point holds it
     * @param  array<int|string, mixed>  $after  the subject as the run left it
     * @param  array<string, mixed>  $context  what the language needs to name parts (e.g. a component's settings)
     * @return array{changes: list<array{uid: string, label: string, how: string}>, total: int}
     */
    public function between(array $before, array $after, array $context = []): array;
}
