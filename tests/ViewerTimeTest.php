<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Time\ViewerTime;

/**
 * A time an assistant's tool writes is in the reader's zone and names it; a calendar date is never
 * shifted. Moved from Portify's `PorterDateFormatTest` with the class (plan mploynow-mira-parity P0c).
 */
class ViewerTimeTest extends TestCase
{
    public function test_a_time_is_written_in_the_viewers_zone_with_the_zone_named(): void
    {
        $out = ViewerTime::within('Africa/Lagos', fn () => ViewerTime::human('2026-10-26 23:24:00'));
        $this->assertSame('Tue, Oct 27, 2026 at 12:24 AM WAT', $out);
    }

    public function test_with_no_known_zone_it_says_utc(): void
    {
        $this->assertSame('Mon, Oct 26, 2026 at 11:24 PM UTC', ViewerTime::human('2026-10-26 23:24:00'));
        $this->assertSame('Mon, Oct 26, 2026 at 11:24 PM UTC', ViewerTime::within('Not/AZone', fn () => ViewerTime::human('2026-10-26 23:24:00')));
    }

    public function test_a_calendar_date_is_not_shifted_and_the_zone_is_restored(): void
    {
        $this->assertSame('Tue, Oct 27, 2026', ViewerTime::within('America/Vancouver', fn () => ViewerTime::human('2026-10-27')));
        $this->assertStringEndsWith('UTC', (string) ViewerTime::human('2026-10-26 23:24:00'));
    }

    public function test_a_clock_line_names_the_zone(): void
    {
        $this->assertSame('9:00 AM WAT, Nov 2', ViewerTime::within('Africa/Lagos', fn () => ViewerTime::clock(new \DateTimeImmutable('2026-11-02 08:00:00 UTC'))));
    }
}
