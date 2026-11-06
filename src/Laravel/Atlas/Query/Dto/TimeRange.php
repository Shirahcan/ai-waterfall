<?php

namespace Shirahcan\AiClient\Laravel\Atlas\Query\Dto;

/**
 * A date window applied to a metric (on its declared `timeField`) or to a
 * date/datetime dimension. `$from`/`$to` are concrete ISO dates emitted by the
 * planner (it resolves "this quarter" itself — the executor never parses words).
 */
final class TimeRange
{
    public function __construct(
        public readonly ?string $metric,
        public readonly ?string $dimension,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || $data === []) {
            return null;
        }

        return new self(
            metric: isset($data['metric']) ? (string) $data['metric'] : null,
            dimension: isset($data['dimension']) ? (string) $data['dimension'] : null,
            from: isset($data['from']) ? (string) $data['from'] : null,
            to: isset($data['to']) ? self::endOfDay((string) $data['to']) : null,
        );
    }

    /**
     * A bare date as the END of that day.
     *
     * ⚠ "to 2026-10-31" compared a timestamp column with the string '2026-10-31', so anything that
     * happened on the last day of a range (any time after midnight) fell outside it: "revenue this
     * month" dropped the whole of today. Found on a month-end test run, 2026-10-31.
     */
    private static function endOfDay(string $to): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($to)) === 1 ? trim($to).' 23:59:59' : $to;
    }
}
