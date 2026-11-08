<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

/** The fallback switch: `ai-client.spaces.enabled` (env `AI_SPACES_ENABLED`), off unless set. */
final class ConfigSpaceSettings implements SpaceSettings
{
    public function enabled(): bool
    {
        return (bool) config('ai-client.spaces.enabled', false);
    }
}
