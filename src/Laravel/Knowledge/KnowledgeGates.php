<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

/**
 * Whether a product's feature switch is on, for an entry's `requires_gates` (1.50.0).
 *
 * ⚠ KNOWLEDGE SHIPS WITH THE CODE, EVEN WHEN THE CODE SHIPS DARK. A file for a feature that is off in
 * production stays in the corpus, and `ai:knowledge-sync` holds it back until its switch is on, so the
 * assistant never describes a screen nobody can open yet. Bound by the product.
 */
interface KnowledgeGates
{
    /** True or false, or null for a key the product does not know (a typo: held back, never served). */
    public function isOpen(string $key): ?bool;
}
