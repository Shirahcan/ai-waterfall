<?php

namespace Shirahcan\AiClient\Exceptions;

use RuntimeException;

/**
 * This product has no admin key, so it cannot administer the vault.
 *
 * ⚠ IT DELIBERATELY DOES NOT EXTEND `AiUnavailableException` OR ANY OTHER AI
 * FAILURE. Every product catches those to degrade gracefully - fall back to a
 * deterministic path, show a plainer answer - and that is exactly the wrong
 * response here. A missing admin key is a DEPLOYMENT MISTAKE: the console will
 * silently show empty screens, an operator will conclude the vault is empty, and
 * nobody will be told that the product was never authorised to look.
 *
 * So it is a RuntimeException. It surfaces, it names the env var, and it is
 * fixed rather than absorbed.
 */
class AdminNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'This product has no ai-service ADMIN key, so it cannot administer the vault. '
            . 'Mint one on the service with `php artisan ai:issue-key <product> --admin` and set it as '
            . 'AI_SERVICE_ADMIN_KEY. It is deliberately NOT the same key as AI_SERVICE_TRUST_KEY: the '
            . 'key that travels on every generation call must not be able to mint keys or clear a '
            . 'credential to receive client documents.'
        );
    }
}
