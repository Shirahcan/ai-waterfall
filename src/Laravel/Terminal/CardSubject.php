<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Illuminate\Http\Request;

/**
 * A document's SHARE CARD as a console subject (1.73.0, shared program E4c), the same for every product: the ref is
 * `{kind}:{document id}` (for example `site_page_card:9f2c...`, `growth_post_card:...`), so a card has its own
 * sessions, rewind points and "what changed", apart from its page or post.
 *
 * A product supplies only what it alone knows: who may open which document's card, the document's name, the card
 * as it is now, its context budget and its change describer. The Build itself is pml-kit's `Card\CardBuild` with
 * ai-service's `shared.pml.card`; the answer's meaning is {@see CardConsoleAsk}.
 *
 * ⚠ THE PRODUCT DECIDES WHO MAY OPEN WHAT ({@see TerminalSubject}): `mayOpen()` false is a 404, never a hint that the
 * document exists.
 */
abstract class CardSubject implements ConsoleSubject
{
    /** The console route's kind segment for this product's cards (`site_page_card`). */
    abstract public function kind(): string;

    /** Whether this person may work on this document's card (gates, ownership, permissions). */
    abstract protected function mayOpen(Request $request, string $documentId): bool;

    /** The document's own name ("Study permit checklist"), or null when it has none. */
    abstract protected function documentLabel(string $documentId): ?string;

    /**
     * The card as the console works on it now (the guarded nodes the builder shows).
     *
     * @return list<array<string, mixed>>
     */
    abstract public function design(string $documentId): array;

    /** The product's console context budget, in tokens. */
    abstract protected function budgetTokens(): int;

    public function ref(Request $request, string $kind, string $id): ?string
    {
        return $kind === $this->kind() && $id !== '' && $this->mayOpen($request, $id) ? $this->kind().':'.$id : null;
    }

    /** "Study permit checklist: share card", so the console's header says which card it is on. */
    public function label(string $ref): string
    {
        $document = $this->documentLabel($this->idOf($ref));

        return ($document !== null && trim($document) !== '' ? trim($document) : 'This page').': share card';
    }

    /** A card is small: its size is its nodes as JSON, against the product's one console budget. */
    public function sizing(string $ref): array
    {
        return [
            'design_chars' => strlen((string) json_encode($this->design($this->idOf($ref)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'budget_tokens' => $this->budgetTokens(),
        ];
    }

    public function idOf(string $ref): string
    {
        return substr($ref, strlen($this->kind()) + 1);
    }
}
