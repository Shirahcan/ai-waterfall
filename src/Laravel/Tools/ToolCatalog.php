<?php

namespace Shirahcan\AiClient\Laravel\Tools;

/**
 * A product's chat tools: the ONLY part of the tool host a product writes.
 *
 * Bind it in the product's service provider (`$this->app->bind(ToolCatalog::class, ...)`);
 * the kit's routes, middleware and controller do the signing, the token, the time limit and
 * the machine contract ai-service's ProductToolClient reads.
 *
 * ⚠ TOOLS READ, THEY NEVER WRITE. An action tool returns a PROPOSAL (a `card`); the person
 * confirms it in the product, which executes it.
 */
interface ToolCatalog
{
    /**
     * The tools this audience may use, in ai-service's manifest shape:
     * `{name, description, input_schema, timeout_seconds?}`.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(string $audience): array;

    /**
     * Run one tool for the actor the token named.
     *
     * A deferred answer is `{ok: true, result: {status: "deferred", ticket, label, note}}`,
     * resumed later with `AiClient::continueTurn()`.
     *
     * @param  array<string, mixed>  $input  already checked against the tool's schema by ai-service
     * @return array{ok: bool, result?: mixed, error?: string, card?: array<string, mixed>}
     */
    public function run(string $name, array $input, ToolActor $actor): array;

    /**
     * Whether the actor still exists (a user deleted mid-turn holds a token that must open
     * nothing). A guest is accepted by the kit before this is asked.
     */
    public function actorExists(ToolActor $actor): bool;
}
