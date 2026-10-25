<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Illuminate\Database\Eloquent\Model;
use Shirahcan\AiClient\ConversationTurn;

/**
 * What a product's message model keeps in ai-service, and how to read it back (P21d).
 */
interface HeldTurnSubject
{
    /** The column on the product's row holding the service's turn id. */
    public function column(): string;

    /** Whether reads come from the service at all (the product's gate). */
    public function enabled(): bool;

    /**
     * The product's attributes for one turn. A null turn means the service holds none for this
     * row (erased, or never mirrored); the subject decides what that reads as.
     *
     * @return array<string, mixed>
     */
    public function hydrate(?ConversationTurn $turn, Model $model): array;
}
