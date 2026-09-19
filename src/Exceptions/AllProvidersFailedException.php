<?php

namespace Shirahcan\AiWaterfall\Exceptions;

/**
 * Every credential the waterfall had was tried and refused.
 *
 * Carries the per-attempt trail so a caller can say WHICH providers refused and
 * why, rather than rendering a blank. This is the shape Portify's callers already
 * catch, kept deliberately familiar so migrating introduces no new failure mode.
 */
class AllProvidersFailedException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $attempts = [])
    {
        parent::__construct($message);
    }
}
