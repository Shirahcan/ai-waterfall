<?php

namespace Shirahcan\AiClient\Laravel\Knowledge;

/**
 * Knowledge that is not a Markdown file: entries an admin wrote on a screen, or generated from the code
 * (1.50.0). `ai:knowledge-sync` publishes these with the corpus, so its retire step keeps them.
 *
 * Bound by the product, tagged `ai-client.knowledge.sources`.
 */
interface KnowledgeSource
{
    /**
     * Entries in the service's wire shape ({@see KnowledgeCorpus::toWire()}). Set `extra.kind` so a console
     * can tell them apart: `admin`, `generated`.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array;
}
