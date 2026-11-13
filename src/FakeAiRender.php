<?php

namespace Shirahcan\AiClient;

/**
 * The render surface of {@see FakeAiClient} (1.70.0). A real AiRender subclass, so a product's type hints hold.
 * Queue answers with `FakeAiClient::queueSnapshot()`; read what was asked with `snapshotCalls()`.
 */
class FakeAiRender extends AiRender
{
    public function __construct(private FakeAiClient $fake)
    {
        parent::__construct($fake);
    }

    public function snapshot(string $url, int $width = 1200, int $height = 630, ?string $waitFor = null, int $maxBytes = 300000): RenderSnapshot
    {
        return $this->fake->snapshotFor($url, $width, $height, $waitFor, $maxBytes);
    }
}
