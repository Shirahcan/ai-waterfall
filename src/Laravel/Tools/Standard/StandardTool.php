<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/**
 * A tool every product's assistant offers the SAME way: one name, one schema, one confirm card,
 * defined here once. A product supplies only an adapter that says whether this person may use it
 * and which of its own confirm actions executes it.
 *
 * ⚠ IT EXISTS SO "SET A REMINDER" IS NOT WRITTEN FOUR TIMES. Each product's assistant would
 * otherwise describe the tool its own way, extract the time its own way, and draw its own card,
 * and the four would drift exactly as the four chat panels did.
 */
interface StandardTool
{
    public function name(): string;

    /** The manifest entry for this audience, or null when the audience is not offered the tool. */
    public function definitionFor(string $audience): ?array;

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, result?: mixed, error?: string, card?: array<string, mixed>}
     */
    public function run(array $input, ToolActor $actor): array;
}
