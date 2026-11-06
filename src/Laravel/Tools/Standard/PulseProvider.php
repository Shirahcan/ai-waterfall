<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Shirahcan\AiClient\Time\TimeWindow;

/**
 * A product's answer to "what happened in this period?" for one person, behind {@see PulseTool}.
 *
 * ⚠ EVERY FIGURE IS COUNTED BY THE PRODUCT, NEVER BY THE MODEL, and scoped to what the asker may see:
 * a consultant's pulse is their practice, a client's is their own case. A source that could not be
 * read returns a line with `unavailable` set and no value, never a zero.
 */
interface PulseProvider
{
    /** Whether this audience is offered the pulse at all. */
    public function offersPulseTo(string $audience): bool;

    /**
     * The areas this audience's pulse covers, for the tool's `focus` parameter ("people", "cases", "money"...).
     *
     * @return list<string>
     */
    public function domains(string $audience): array;

    /**
     * @param  list<string>  $focus  areas asked for; empty = all
     * @param  string|null  $person  one person, as the user named them; null = everyone the asker may see
     */
    public function pulse(TimeWindow $window, ToolActor $actor, array $focus, ?string $person, bool $compare): PulseReport;
}
