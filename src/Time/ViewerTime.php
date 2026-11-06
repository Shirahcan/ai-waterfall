<?php

namespace Shirahcan\AiClient\Time;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * How an assistant's tools write a date in prose, in ONE place, for every product (was Portify's
 * `PorterDateFormat`; moved into the kit with the data engine, plan mploynow-mira-parity P0c).
 *
 * The data tools ({@see \Shirahcan\AiClient\Laravel\Atlas\Query\QueryTools}) hand the model this string so it never reformats a
 * timestamp itself. Asking the model to write the date instead produced
 * "2026-09-22 16:32:43" verbatim in one run and a reformatted "22 Sep" in the
 * next; handing it the finished string is what makes the output stable.
 */
class ViewerTime
{
    /**
     * The zone of the person reading, for the duration of one tool run ({@see within()}).
     *
     * ⚠ A TIME WITHOUT A ZONE IS A WRONG TIME. Stored values are UTC, and this used to print them
     * bare: an admin in Lagos read "Joined Mon, Oct 26 at 11:24 PM" beside a row that said Oct 27
     * (2026-11-01). The rule is the viewer's device clock, with the zone named.
     */
    private static ?string $viewerZone = null;

    /** Run $work with times written in the viewer's zone (the browser's, from the tool token). */
    public static function within(?string $zone, callable $work): mixed
    {
        $previous = self::$viewerZone;
        self::$viewerZone = self::validZone($zone);
        try {
            return $work();
        } finally {
            self::$viewerZone = $previous;
        }
    }

    /** A real IANA zone name, or null: an unknown zone is treated as none, never guessed at. */
    public static function validZone(mixed $zone): ?string
    {
        $zone = is_string($zone) ? trim($zone) : '';
        if ($zone === '') {
            return null;
        }

        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $zone : null;
    }

    /** The viewer's zone for this tool run, so "today" is the asker's day, not UTC's. */
    public static function viewerZone(): string
    {
        return self::$viewerZone ?? 'UTC';
    }

    /** A moment as a short confirmation line reads it: "9:00 AM WAT, Nov 2", in the viewer's zone, named. */
    public static function clock(\DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::$viewerZone ?? 'UTC')->format('g:i A T, M j');
    }

    /** "Tue, Sep 8, 2026 at 10:08 AM WAT" (the viewer's zone, named), or the date alone when there is no time. */
    public static function human(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || is_numeric($value)) {
            return null;
        }

        // Only touch things that actually look like a date, so a case reference
        // or a job title is never mangled into one.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', trim($value))) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        $hasTime = (bool) preg_match('/[ T]\d{2}:\d{2}/', $value);

        if (! $hasTime) {
            // A calendar date is not converted: "Oct 27" is the same day wherever it is read.
            return $date->format('D, M j, Y');
        }

        return $date->setTimezone(self::$viewerZone ?? 'UTC')->format('D, M j, Y \a\t g:i A T');
    }
}
