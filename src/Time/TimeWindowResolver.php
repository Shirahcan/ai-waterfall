<?php

namespace Shirahcan\AiClient\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Turns a period said in words into a {@see TimeWindow}, in the asker's zone. One authority for every
 * product's assistant: Porter, Mira and Reeve all mean the same thing by "this week".
 *
 * What a model passes (the way the person said it):
 *
 *     {"last": "25m"} {"last": "13 hours"} {"last": "4d"} {"last": "2w"} {"last": "3mo"} {"last": "1y"}
 *     {"calendar": "today" | "yesterday" | "tomorrow" | "this_week" | "last_week" | "next_week" |
 *                  "this_month" | "last_month" | "next_month" | "this_quarter" | "last_quarter" |
 *                  "this_year" | "last_year" | "year_to_date"}
 *     {"from": "2026-11-02", "to": "2026-11-05"}   (a date-only `to` is the END of that day)
 *     {"since": "2026-11-02T09:00:00+01:00"}
 *
 * A plain string ("13h", "last 4 days", "this week") is read the same way.
 *
 * ⚠ CALENDAR WINDOWS COVER THE WHOLE UNIT, the future part included: "meetings this week" must count
 * Friday's call on a Tuesday. For things that only happen in the past (sign-ups, payments) the future
 * part is simply empty. Weeks start on Monday.
 *
 * ⚠ A WINDOW IT CANNOT READ IS REFUSED WITH A REASON, never guessed: {@see TimeWindowRefused} carries
 * the accepted forms so the model can try again.
 */
final class TimeWindowResolver
{
    /** About five years: a longer window is almost certainly a misread. */
    private const MAX_SECONDS = 5 * 366 * 86400;

    private const UNITS = [
        'minute' => ['m', 'min', 'mins', 'minute', 'minutes'],
        'hour' => ['h', 'hr', 'hrs', 'hour', 'hours'],
        'day' => ['d', 'day', 'days'],
        'week' => ['w', 'wk', 'wks', 'week', 'weeks'],
        'month' => ['mo', 'mon', 'mos', 'month', 'months'],
        'year' => ['y', 'yr', 'yrs', 'year', 'years'],
    ];

    private const SECONDS = ['minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800];

    private const CALENDAR = [
        'today', 'yesterday', 'tomorrow', 'this_week', 'last_week', 'next_week', 'this_month', 'last_month',
        'next_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'year_to_date',
    ];

    private const ACCEPTED = 'Pass the period as the user said it: {"last": "25m" | "13h" | "4d" | "2w" | "3mo" | "1y"}, '
        .'{"calendar": "today" | "yesterday" | "this_week" | "last_week" | "this_month" | "last_month" | "this_quarter" | "this_year" ...}, '
        .'{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"} or {"since": "YYYY-MM-DD"}. Never compute dates yourself.';

    /**
     * @param  array<string, mixed>|string  $spec
     *
     * @throws TimeWindowRefused
     */
    public function resolve(array|string $spec, string $zone, ?DateTimeInterface $now = null): TimeWindow
    {
        $z = $this->zone($zone);
        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now'))->setTimezone($z);
        $spec = is_string($spec) ? $this->fromText($spec) : $spec;

        $window = match (true) {
            isset($spec['last']) => $this->last((string) $spec['last'], $z, $now, $spec),
            isset($spec['calendar']) => $this->calendar((string) $spec['calendar'], $z, $now, $spec),
            isset($spec['from']) || isset($spec['to']) => $this->range($spec, $z, $now),
            isset($spec['since']) => $this->since((string) $spec['since'], $z, $now, $spec),
            default => throw new TimeWindowRefused('No period was given. '.self::ACCEPTED),
        };

        if ($window->to < $window->from) {
            throw new TimeWindowRefused('The period ends before it starts. '.self::ACCEPTED);
        }
        if ($window->seconds() > self::MAX_SECONDS) {
            throw new TimeWindowRefused('That period is longer than five years; ask for a shorter one. '.self::ACCEPTED);
        }

        return $window;
    }

    /** True for a `last` length counted in calendar units (months, years). */
    public function isCalendarLength(string $last): bool
    {
        $parsed = $this->parseLength($last);

        return $parsed !== null && in_array($parsed[1], ['month', 'year'], true);
    }

    /** @return array<string, string> */
    private function fromText(string $text): array
    {
        $t = strtolower(trim($text));
        $calendar = str_replace([' ', '-'], '_', $t);
        $calendar = ['ytd' => 'year_to_date', 'so_far_this_year' => 'year_to_date'][$calendar] ?? $calendar;
        if (in_array($calendar, self::CALENDAR, true)) {
            return ['calendar' => $calendar];
        }

        $length = preg_replace('/^(the\s+)?(last|past)\s+/', '', $t) ?? $t;
        if ($this->parseLength($length) !== null) {
            return ['last' => $length];
        }

        throw new TimeWindowRefused("\"{$text}\" is not a period I can read. ".self::ACCEPTED);
    }

    /** @return array{0: float, 1: string}|null  [amount, unit] */
    private function parseLength(string $length): ?array
    {
        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([a-z]+)\s*$/i', $length, $m)) {
            return null;
        }
        $word = strtolower($m[2]);
        foreach (self::UNITS as $unit => $spellings) {
            if (in_array($word, $spellings, true)) {
                return [(float) $m[1], $unit];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $spec */
    private function last(string $length, DateTimeZone $z, DateTimeImmutable $now, array $spec): TimeWindow
    {
        $parsed = $this->parseLength($length);
        if ($parsed === null) {
            throw new TimeWindowRefused("\"{$length}\" is not a length I can read. ".self::ACCEPTED);
        }
        [$amount, $unit] = $parsed;
        if ($amount <= 0) {
            throw new TimeWindowRefused('A period must be longer than zero. '.self::ACCEPTED);
        }

        if (isset(self::SECONDS[$unit])) {
            $from = $now->modify('-'.(int) round($amount * self::SECONDS[$unit]).' seconds');
        } else {
            if ($amount != floor($amount)) {
                throw new TimeWindowRefused('Months and years must be whole numbers. '.self::ACCEPTED);
            }
            $from = $this->subMonths($now, (int) $amount * ($unit === 'year' ? 12 : 1));
        }

        $n = $amount == floor($amount) ? (string) (int) $amount : rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $name = $n === '1' ? "the last {$unit}" : "the last {$n} {$unit}s";

        return new TimeWindow($from, $now, $z->getName(), TimeWindow::KIND_LAST, $name, $spec);
    }

    /** @param  array<string, mixed>  $spec */
    private function calendar(string $which, DateTimeZone $z, DateTimeImmutable $now, array $spec): TimeWindow
    {
        $which = strtolower(str_replace([' ', '-'], '_', trim($which)));
        $which = ['ytd' => 'year_to_date'][$which] ?? $which;
        $today = $now->setTime(0, 0);
        $monday = $today->modify('-'.((int) $today->format('N') - 1).' days');
        $month = $today->modify('first day of this month');
        $quarter = $today->setDate((int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1, 1);
        $year = $today->setDate((int) $today->format('Y'), 1, 1);

        [$from, $toExclusive, $name] = match ($which) {
            'today' => [$today, $today->modify('+1 day'), 'today'],
            'yesterday' => [$today->modify('-1 day'), $today, 'yesterday'],
            'tomorrow' => [$today->modify('+1 day'), $today->modify('+2 days'), 'tomorrow'],
            'this_week' => [$monday, $monday->modify('+7 days'), 'this week'],
            'last_week' => [$monday->modify('-7 days'), $monday, 'last week'],
            'next_week' => [$monday->modify('+7 days'), $monday->modify('+14 days'), 'next week'],
            'this_month' => [$month, $month->modify('+1 month'), 'this month'],
            'last_month' => [$month->modify('-1 month'), $month, 'last month'],
            'next_month' => [$month->modify('+1 month'), $month->modify('+2 months'), 'next month'],
            'this_quarter' => [$quarter, $quarter->modify('+3 months'), 'this quarter'],
            'last_quarter' => [$quarter->modify('-3 months'), $quarter, 'last quarter'],
            'this_year' => [$year, $year->modify('+1 year'), 'this year'],
            'last_year' => [$year->modify('-1 year'), $year, 'last year'],
            'year_to_date' => [$year, null, 'the year so far'],
            default => throw new TimeWindowRefused("\"{$which}\" is not a calendar period I know. ".self::ACCEPTED),
        };

        $to = $toExclusive === null ? $now : $toExclusive->modify('-1 second');

        return new TimeWindow($from, $to, $z->getName(), TimeWindow::KIND_CALENDAR, $name, ['calendar' => $which] + $spec);
    }

    /** @param  array<string, mixed>  $spec */
    private function range(array $spec, DateTimeZone $z, DateTimeImmutable $now): TimeWindow
    {
        if (! isset($spec['from'])) {
            throw new TimeWindowRefused('A range needs a start ("from"). '.self::ACCEPTED);
        }
        $from = $this->instant((string) $spec['from'], $z, false);
        $to = isset($spec['to']) ? $this->instant((string) $spec['to'], $z, true) : $now;

        return new TimeWindow($from, $to, $z->getName(), TimeWindow::KIND_RANGE, 'the period', $spec);
    }

    /** @param  array<string, mixed>  $spec */
    private function since(string $since, DateTimeZone $z, DateTimeImmutable $now, array $spec): TimeWindow
    {
        $from = $this->instant($since, $z, false);

        return new TimeWindow($from, $now, $z->getName(), TimeWindow::KIND_SINCE, 'since '.$from->format('D, M j'), $spec);
    }

    /** A date (start or end of that local day) or a full timestamp (its own offset, or the asker's zone). */
    private function instant(string $value, DateTimeZone $z, bool $endOfDay): DateTimeImmutable
    {
        $value = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $day = new DateTimeImmutable($value.' 00:00:00', $z);

                return $endOfDay ? $day->modify('+1 day')->modify('-1 second') : $day;
            }

            return (new DateTimeImmutable($value, $z))->setTimezone($z);
        } catch (Throwable) {
            throw new TimeWindowRefused("\"{$value}\" is not a date I can read. ".self::ACCEPTED);
        }
    }

    /** Whole months back, keeping the day of the month where it exists (Mar 31 - 1 month = Feb 28/29). */
    private function subMonths(DateTimeImmutable $at, int $months): DateTimeImmutable
    {
        $first = $at->modify('first day of this month')->modify("-{$months} months");
        $day = min((int) $at->format('j'), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    private function zone(string $zone): DateTimeZone
    {
        try {
            return new DateTimeZone($zone !== '' ? $zone : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
