<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

use RuntimeException;

/** Today's dictation minutes are spent. Expected, not an error: the route answers 429 and the microphone says so. */
final class DictationAllowanceUsed extends RuntimeException
{
    public function __construct(public readonly int $dailyMinutes)
    {
        parent::__construct("You have used today's {$dailyMinutes} minutes of dictation. Type for now; it resets at midnight.");
    }
}
