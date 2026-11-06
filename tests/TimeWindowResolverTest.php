<?php

namespace Shirahcan\AiClient\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Time\TimeWindowRefused;
use Shirahcan\AiClient\Time\TimeWindowResolver;

/** The one period grammar every product's assistant uses (Portify plan porter-stats-reach 02a). */
class TimeWindowResolverTest extends TestCase
{
    private const LAGOS = 'Africa/Lagos';

    /** Fri 6 Nov 2026, 7:24 PM in Lagos (18:24 UTC). */
    private function now(string $zone = self::LAGOS, string $at = '2026-11-06 19:24:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone($zone));
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function test_the_last_25_minutes_is_exact_and_says_so(): void
    {
        $w = (new TimeWindowResolver())->resolve(['last' => '25m'], self::LAGOS, $this->now());

        $this->assertSame('2026-11-06 17:59:00', $this->utc($w->from));
        $this->assertSame('2026-11-06 18:24:00', $this->utc($w->to));
        $this->assertSame('the last 25 minutes (6:59 PM to 7:24 PM WAT)', $w->label());
        $this->assertSame('2026-11-06 17:59:00', $w->fromUtc());
    }

    public function test_words_and_shorthand_mean_the_same(): void
    {
        $r = new TimeWindowResolver();
        foreach (['13h', '13 hours', 'last 13 hours', 'the past 13 hrs'] as $said) {
            $this->assertSame('the last 13 hours', $r->resolve($said, self::LAGOS, $this->now())->name, $said);
        }
        $this->assertSame('the last hour', $r->resolve(['last' => '1h'], self::LAGOS, $this->now())->name);
        $this->assertSame('the last 1.5 hours', $r->resolve(['last' => '1.5h'], self::LAGOS, $this->now())->name);
        $this->assertSame('this week', $r->resolve('this week', self::LAGOS, $this->now())->name);
    }

    public function test_today_is_the_askers_day_not_utc(): void
    {
        // 00:30 in Lagos is still 23:30 UTC the day before.
        $w = (new TimeWindowResolver())->resolve(['calendar' => 'today'], self::LAGOS, $this->now(self::LAGOS, '2026-11-06 00:30:00'));

        $this->assertSame('2026-11-05 23:00:00', $this->utc($w->from));
        $this->assertSame('2026-11-06 22:59:59', $this->utc($w->to));
        $this->assertSame('today (Fri, Nov 6, WAT)', $w->label());
    }

    public function test_this_week_runs_monday_to_sunday_and_compares_with_the_week_before(): void
    {
        $w = (new TimeWindowResolver())->resolve(['calendar' => 'this_week'], self::LAGOS, $this->now());

        $this->assertSame('this week (Mon, Nov 2 to Sun, Nov 8, WAT)', $w->label());
        $prev = $w->previous();
        $this->assertSame('the previous week (Mon, Oct 26 to Sun, Nov 1, WAT)', $prev->label());
    }

    public function test_the_previous_window_of_a_length_is_the_same_length_just_before(): void
    {
        $w = (new TimeWindowResolver())->resolve(['last' => '4d'], self::LAGOS, $this->now());
        $prev = $w->previous();

        $this->assertSame($w->seconds(), $prev->seconds());
        $this->assertSame($w->from->modify('-1 second')->getTimestamp(), $prev->to->getTimestamp());
        $this->assertSame('the 4 days before', $prev->name);
    }

    public function test_months_are_calendar_months(): void
    {
        $w = (new TimeWindowResolver())->resolve(['last' => '1mo'], self::LAGOS, $this->now(self::LAGOS, '2026-03-31 12:00:00'));

        $this->assertSame('2026-02-28 12:00:00', $w->from->format('Y-m-d H:i:s'));
    }

    public function test_a_date_only_end_is_the_end_of_that_day(): void
    {
        $w = (new TimeWindowResolver())->resolve(['from' => '2026-11-02', 'to' => '2026-11-05'], self::LAGOS, $this->now());

        $this->assertSame('2026-11-02 00:00:00', $w->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 23:59:59', $w->to->format('Y-m-d H:i:s'));
        $this->assertSame('the period (Mon, Nov 2 to Thu, Nov 5, WAT)', $w->label());
    }

    public function test_a_dst_change_day_is_23_hours_long(): void
    {
        // Toronto springs forward on Sun 8 Mar 2026.
        $w = (new TimeWindowResolver())->resolve(['calendar' => 'today'], 'America/Toronto', $this->now('America/Toronto', '2026-03-08 12:00:00'));

        $this->assertSame(23 * 3600, $w->seconds());
    }

    public function test_since_runs_to_now(): void
    {
        $w = (new TimeWindowResolver())->resolve(['since' => '2026-11-02'], self::LAGOS, $this->now());

        $this->assertSame('since Mon, Nov 2', $w->name);
        $this->assertSame('2026-11-06 19:24:00', $w->to->format('Y-m-d H:i:s'));
    }

    public function test_what_it_cannot_read_is_refused_with_the_accepted_forms(): void
    {
        $r = new TimeWindowResolver();
        foreach ([['last' => '0h'], ['last' => '3 fortnights'], ['calendar' => 'someday'], ['last' => '9y'], ['from' => '2026-11-05', 'to' => '2026-11-01'], []] as $bad) {
            try {
                $r->resolve($bad, self::LAGOS, $this->now());
                $this->fail('accepted '.json_encode($bad));
            } catch (TimeWindowRefused $e) {
                $this->assertStringContainsString('Never compute dates yourself', $e->getMessage());
            }
        }
    }
}
