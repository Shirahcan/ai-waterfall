<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Laravel\Tools\Standard\ReminderAdapter;
use Shirahcan\AiClient\Laravel\Tools\Standard\SetReminderTool;
use Shirahcan\AiClient\Laravel\Tools\Standard\StandardToolbox;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/** The shared `set_reminder`: one definition and one confirm card for every product (1.32). */
class StandardToolsTest extends TestCase
{
    private function toolbox(): StandardToolbox
    {
        $adapter = new class implements ReminderAdapter {
            public function offersRemindersTo(string $audience): bool
            {
                return $audience !== 'partner';
            }

            public function reminderActionFor(ToolActor $actor): ?string
            {
                return "action.{$actor->audience}.set_reminder";
            }

            public function timezoneFor(ToolActor $actor): string
            {
                return 'America/Toronto';
            }
        };

        return new StandardToolbox(new SetReminderTool($adapter, new DateTimeImmutable('2026-10-29T12:00:00Z')));
    }

    public function test_it_is_offered_by_audience_and_never_to_a_visitor(): void
    {
        $box = $this->toolbox();

        $this->assertSame(['set_reminder'], array_column($box->definitions('consultant'), 'name'));
        $this->assertSame([], $box->definitions('public'));
        $this->assertSame([], $box->definitions('partner'));
    }

    public function test_a_valid_reminder_is_the_standard_confirm_card_in_the_users_zone(): void
    {
        $out = $this->toolbox()->run('set_reminder', ['body' => 'call Maria', 'remind_at' => '2026-10-30T13:00:00Z'],
            new ToolActor('u-1', 'consultant'));

        $this->assertTrue($out['ok']);
        $this->assertSame('action_confirm', $out['card']['type']);
        $meta = $out['card']['payload']['meta'];
        $this->assertSame('action.consultant.set_reminder', $meta['action_id']);
        $this->assertSame(['body' => 'call Maria', 'remind_at' => '2026-10-30T09:00:00-04:00'], $meta['params']);
        $this->assertSame('America/Toronto', $meta['timezone']);
        $this->assertStringStartsWith("I'll remind you to call Maria on Fri 30 Oct 2026, 9:00 AM", $out['card']['payload']['content']);
    }

    public function test_a_past_time_an_unreadable_time_or_a_guest_is_an_error_the_model_can_act_on(): void
    {
        $box = $this->toolbox();

        $this->assertFalse($box->run('set_reminder', ['body' => 'x', 'remind_at' => '2026-10-28T09:00:00Z'], new ToolActor('u-1', 'client'))['ok']);
        $this->assertFalse($box->run('set_reminder', ['body' => 'x', 'remind_at' => 'next blursday'], new ToolActor('u-1', 'client'))['ok']);
        $this->assertFalse($box->run('set_reminder', ['body' => '', 'remind_at' => '2026-10-30T09:00:00Z'], new ToolActor('u-1', 'client'))['ok']);
        $this->assertFalse($box->run('set_reminder', ['body' => 'x', 'remind_at' => '2026-10-30T09:00:00Z'], ToolActor::guest('g'))['ok']);
    }
}
