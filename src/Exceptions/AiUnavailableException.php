<?php

namespace Shirahcan\AiWaterfall\Exceptions;

/**
 * The service could not be reached, or failed in a way with no better name.
 *
 * ⚠ Callers should treat this exactly as they treat today's "all providers
 * failed": degrade to a deterministic path, tell the user honestly, do not retry
 * in a loop. It must never be a reason to call a provider directly.
 */
class AiUnavailableException extends \RuntimeException {}
