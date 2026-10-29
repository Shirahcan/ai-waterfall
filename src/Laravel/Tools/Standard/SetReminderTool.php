<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use DateTimeImmutable;
use DateTimeZone;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Throwable;

/**
 * The shared `set_reminder`: the model passes the reminder as typed fields, this checks them, and
 * the person gets the estate's standard confirm card (`action_confirm`, drawn by `@shirahcan/ai-ui`).
 * Nothing is saved until they press Confirm, which runs the product's own action with
 * `{body, remind_at}`.
 *
 * ⚠ TYPED FIELDS, NOT A SENTENCE TO RE-READ. A product that took "remind me Friday at 9" as text
 * had to make a second model call to pull the time back out; the engine already knows it.
 */
final class SetReminderTool implements StandardTool
{
    public const NAME = 'set_reminder';

    public const MAX_BODY = 500;

    public function __construct(private ReminderAdapter $adapter, private ?DateTimeImmutable $now = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definitionFor(string $audience): ?array
    {
        // A visitor with no account has nobody to remind.
        if ($audience === 'public' || ! $this->adapter->offersRemindersTo($audience)) {
            return null;
        }

        return [
            'name'        => self::NAME,
            'description' => 'Set a personal reminder for the user at a time they gave ("remind me Friday at 9 to call Maria"). '
                .'Shows them a card to confirm; nothing is saved until they do. Resolve relative times ("tomorrow", "in 2 hours") '
                .'against today and their timezone from the frame, and pass the result with its offset.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'body'      => ['type' => 'string', 'description' => 'What to remind them about, in their own words. Under 500 characters.'],
                'remind_at' => ['type' => 'string', 'description' => 'When, as ISO 8601 with the UTC offset, e.g. 2026-10-30T09:00:00-04:00. Must be in the future.'],
            ], 'required' => ['body', 'remind_at']],
        ];
    }

    public function run(array $input, ToolActor $actor): array
    {
        $action = $actor->isGuest() ? null : $this->adapter->reminderActionFor($actor);
        if ($action === null) {
            return ['ok' => false, 'error' => 'Reminders are not available to this user here.'];
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            return ['ok' => false, 'error' => 'A reminder needs a short description (under 500 characters). Ask what it is about.'];
        }

        try {
            $at = new DateTimeImmutable((string) ($input['remind_at'] ?? ''));
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'That time could not be read. Ask the user when, then pass ISO 8601 with an offset.'];
        }

        $now = $this->now ?? new DateTimeImmutable('now');
        if ($at <= $now) {
            return ['ok' => false, 'error' => 'That time has already passed. Ask for a time in the future.'];
        }

        $zone = $this->zone($this->adapter->timezoneFor($actor));
        $local = $at->setTimezone($zone);
        $when = $local->format('D j M Y, g:i A').' ('.$zone->getName().')';

        return [
            'ok'     => true,
            'result' => ['proposed' => self::NAME, 'when' => $when, 'card_says' => 'Confirm to set it.'],
            'card'   => ['type' => 'action_confirm', 'payload' => [
                // First person, like every assistant line (the shared frame rule).
                'content' => "I'll remind you to {$body} on {$when}. Confirm below and I'll set it.",
                'kind'    => 'action_confirm',
                'meta'    => [
                    'action_id'   => $action,
                    'module_id'   => self::NAME,
                    'params'      => ['body' => $body, 'remind_at' => $local->format(DATE_ATOM)],
                    'timezone'    => $zone->getName(),
                    'can_refine'  => true,
                    'can_explain' => true,
                    'standard'    => self::NAME,
                ],
            ]],
        ];
    }

    private function zone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
