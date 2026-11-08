<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Turns;

use RuntimeException;

/**
 * A turn the product declined for a reason the person can read ("You do not have access to this
 * conversation."). The message is shown as written; anything else that escapes a runner is reported
 * and the person reads the runner's `failureMessage()`.
 */
final class TurnRefused extends RuntimeException {}
