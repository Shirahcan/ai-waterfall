<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;

/**
 * How a thread's turns are handed to the product's panel (1.57). A product's messages carry its own
 * vocabulary (Porter: cards, provenance, a confirm card's outcome, the files on a question), and its
 * panel already reads that shape; binding this keeps it, so moving the panel onto spaces changes where
 * the data lives, not what the panel is given. The fallback is the neutral turn shape.
 */
interface SpaceMessages
{
    /**
     * @param  array<string, mixed>  $conversation  ai-service's thread summary
     * @param  list<array<string, mixed>>  $turns  ai-service's turns, oldest first within the page
     * @return list<array<string, mixed>>
     */
    public function present(Request $request, array $conversation, array $turns): array;
}
