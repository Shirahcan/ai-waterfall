<?php

namespace Shirahcan\AiWaterfall\Exceptions;

/**
 * This product has reached its HARD spend cap.
 *
 * ⚠ DELIBERATELY NOT AllProvidersFailedException. The remedies are opposite:
 * this one is "raise the cap or find the loop", that one is "the providers are
 * down". A caller that conflates them shows the user the wrong thing and sends an
 * operator to the wrong place.
 *
 * Raising the cap takes effect on the next request - no restart, no deploy.
 */
class AiOverBudgetException extends \RuntimeException {}
