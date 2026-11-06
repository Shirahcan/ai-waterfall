<?php

namespace Shirahcan\AiClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A period an assistant was asked about, resolved to exact instants in the asker's zone.
 *
 * ⚠ THE MODEL NEVER COMPUTES ONE OF THESE. It passes the period the way the person said it ("the last
 * 25 minutes", "this week", two dates) and {@see TimeWindowResolver} turns that into instants on the
 * server. Clock arithmetic is the step models get wrong: Porter once wrote 16:00 UTC as 6:00 PM WAT.
 *
 * ⚠ AND IT ALWAYS SAYS WHAT IT COUNTED. `label()` names the exact window in the asker's zone, so an
 * answer can state "in the last 25 minutes (6:59 PM to 7:24 PM WAT)" instead of leaving the reader to
 * guess what "recently" meant.
 *
 * Both ends are INCLUSIVE instants, to the second (`to` of a calendar day is 23:59:59 local).
 */
final class TimeWindow
{
    public const KIND_LAST = 'last';

    public const KIND_CALENDAR = 'calendar';

    public const KIND_RANGE = 'range';

    public const KIND_SINCE = 'since';

    /**
     * @param  array<string, mixed>  $spec  what was asked, as given
     */
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly string $zone,
        public readonly string $kind,
        public readonly string $name,
        public readonly array $spec,
    ) {}

    /** Length in seconds, counting both inclusive ends. */
    public function seconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp() + 1;
    }

    /** True when the window covers whole local days (a calendar unit or a date range). */
    public function isWholeDays(): bool
    {
        $z = new DateTimeZone($this->zone);

        return $this->from->setTimezone($z)->format('H:i:s') === '00:00:00'
            && $this->to->setTimezone($z)->format('H:i:s') === '23:59:59';
    }

    /**
     * The window as a person reads it, in the asker's zone, with the zone named.
     * "the last 25 minutes (6:59 PM to 7:24 PM WAT)", "this week (Mon, Nov 2 to Sun, Nov 8, WAT)".
     */
    public function label(): string
    {
        $z = new DateTimeZone($this->zone);
        $f = $this->from->setTimezone($z);
        $t = $this->to->setTimezone($z);
        $day = $f->format('Y') === $t->format('Y') ? 'D, M j' : 'D, M j, Y';
        $abbr = $t->format('T');
        $oneDay = $f->format('Y-m-d') === $t->format('Y-m-d');

        if ($this->isWholeDays()) {
            $span = $oneDay ? $f->format($day) : $f->format($day).' to '.$t->format($day);

            return "{$this->name} ({$span}, {$abbr})";
        }

        $span = $oneDay
            ? $f->format('g:i A').' to '.$t->format('g:i A')
            : $f->format($day.', g:i A').' to '.$t->format($day.', g:i A');

        return "{$this->name} ({$span} {$abbr})";
    }

    /**
     * The window to compare with: the previous calendar unit for a calendar window ("this week" ->
     * the previous week, "the last 3 months" -> the 3 months before), the same length immediately
     * before for everything else.
     */
    public function previous(): self
    {
        $resolver = new TimeWindowResolver();
        $calendarAware = $this->kind === self::KIND_CALENDAR
            || ($this->kind === self::KIND_LAST && $resolver->isCalendarLength((string) ($this->spec['last'] ?? '')));

        if ($calendarAware) {
            return $resolver->resolve($this->spec, $this->zone, $this->from->modify('-1 second'))->renamed($this->previousName());
        }

        $length = $this->seconds();
        $from = $this->from->modify("-{$length} seconds");
        $to = $this->from->modify('-1 second');

        return new self($from, $to, $this->zone, self::KIND_RANGE, $this->previousName(),
            ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)]);
    }

    /** @return array{label: string, name: string, from: string, to: string, zone: string, kind: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'name' => $this->name,
            'from' => $this->from->format(DATE_ATOM),
            'to' => $this->to->format(DATE_ATOM),
            'zone' => $this->zone,
            'kind' => $this->kind,
        ];
    }

    /** UTC `Y-m-d H:i:s`, the form a database stores timestamps in. */
    public function fromUtc(): string
    {
        return $this->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function toUtc(): string
    {
        return $this->to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function renamed(string $name): self
    {
        return new self($this->from, $this->to, $this->zone, $this->kind, $name, $this->spec);
    }

    /** What the comparison window is called: "the day before", "the 4 days before", "the previous week". */
    private function previousName(): string
    {
        return match (true) {
            $this->name === 'today' => 'the day before',
            $this->name === 'yesterday' => 'the day before yesterday',
            str_starts_with($this->name, 'the last ') => 'the '.substr($this->name, 9).' before',
            str_starts_with($this->name, 'this ') => 'the previous '.substr($this->name, 5),
            str_starts_with($this->name, 'next ') => 'this '.substr($this->name, 5),
            str_starts_with($this->name, 'last ') => 'the '.substr($this->name, 5).' before last',
            default => 'the period just before',
        };
    }
}
