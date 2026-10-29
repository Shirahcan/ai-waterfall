<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/**
 * A product's side of the shared `set_reminder` tool: who may set one, and which of the product's
 * confirm actions writes it. The product's confirm route executes that action with the shared
 * params `{body, remind_at}` (ISO 8601 with offset) when the person presses Confirm.
 */
interface ReminderAdapter
{
    /** Whether this audience is offered reminders at all (it decides the manifest). */
    public function offersRemindersTo(string $audience): bool;

    /** The product's confirm-action id that saves a reminder for this actor, or null = not allowed. */
    public function reminderActionFor(ToolActor $actor): ?string;

    /** The zone to read and say times in for this actor (IANA). */
    public function timezoneFor(ToolActor $actor): string;
}
