<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * Supplies one {@see AtlasEntity} to the {@see AtlasRegistry}.
 *
 * Mirrors the AudienceKeyProvider pattern: each domain ships a provider; the
 * registry aggregates them. Adding an entity = add a provider to the registry's
 * canonical list.
 */
interface EntityProvider
{
    public function entity(): AtlasEntity;
}
