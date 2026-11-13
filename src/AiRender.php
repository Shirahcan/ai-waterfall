<?php

namespace Shirahcan\AiClient;

use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;

/**
 * Pictures of a product's OWN pages, drawn by ai-service's headless browser (1.70.0, shared program E2d, social
 * cards). Only hosts an admin registered for the product are drawn ({@see AiAdmin::setPublicHosts()}).
 *
 * No model runs and nothing is billed; a product draws a card when something is published, never on a timer.
 */
class AiRender
{
    public function __construct(private AiClient $client) {}

    /**
     * One page at an exact size under a byte budget. Defaults to a social card: 1200x630, at most 300 KB (WhatsApp
     * drops a preview image over about that). `$waitFor` is a selector the page shows once it is fully drawn, e.g.
     * `[data-card-ready="true"]`; without one the picture may catch the page still loading.
     */
    public function snapshot(string $url, int $width = 1200, int $height = 630, ?string $waitFor = null, int $maxBytes = 300000): RenderSnapshot
    {
        try {
            return RenderSnapshot::fromAnswer($this->client->postJson('api/v1/render/snapshot', array_filter([
                'url' => $url,
                'width' => $width,
                'height' => $height,
                'wait_for' => $waitFor,
                'max_bytes' => $maxBytes,
            ], fn ($v) => $v !== null)));
        } catch (AiRequestRefusedException $e) {
            // host_not_registered, not_ready, too_large, or a validation refusal: a setup or design fault.
            return RenderSnapshot::failed($e->errorCode ?? 'invalid', $e->getMessage());
        } catch (AllProvidersFailedException|AiUnavailableException $e) {
            // Busy, no browser on this box, not drawable (errorFor maps those three to AllProvidersFailed), or
            // ai-service unreachable: all mean "keep the last picture and try later".
            return RenderSnapshot::failed(RenderSnapshot::UNAVAILABLE, $e->getMessage());
        }
    }
}
