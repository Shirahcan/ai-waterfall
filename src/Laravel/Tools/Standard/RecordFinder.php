<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/**
 * The product's side of `find_record`: look up ONE named record the way the product's own search
 * box would, inside this actor's scope, and read it.
 *
 * ⚠ SCOPE IS THE PRODUCT'S. The kit never sees a table: the adapter must return only what this
 * actor could find by typing the same words into the product's search.
 */
interface RecordFinder
{
    /** Whether this audience is offered the lookup at all (it decides the manifest). */
    public function offersLookupTo(string $audience): bool;

    /**
     * Records matching `$query` for this actor.
     *
     * `matches` lists every hit by kind ("People", "Cases"...), each item with a `title`, an
     * optional `subtitle` and an optional `url`. `profiles` carries the full read of the best
     * person/record matches (facts, related records, money...), in whatever shape the product
     * holds; any `url`/`page` on them, or on rows under them, is linked for the model.
     *
     * @return array{matches: list<array{kind: string, items: list<array<string, mixed>>}>, profiles?: list<array<string, mixed>>}
     */
    public function find(string $query, ToolActor $actor): array;
}
