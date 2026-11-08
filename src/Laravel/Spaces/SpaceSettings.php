<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

/**
 * Whether this product's panel runs on assistant spaces (1.57). A product with DB-backed gates binds
 * its own (Portify `assistant.spaces.enabled`, MployNow `ai.assistant_spaces.enabled`); the fallback
 * reads `ai-client.spaces.enabled`. OFF: every space route answers 404 and the product's own routes
 * keep serving, unchanged.
 */
interface SpaceSettings
{
    public function enabled(): bool;
}
