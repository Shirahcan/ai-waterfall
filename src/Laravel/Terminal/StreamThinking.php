<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Closure;

/**
 * Thinking mode for the streams made inside one piece of console work (1.67.0, moved from Portify for the shared
 * console, C1b; owner 2026-11-08: "for
 * this console thinking should be on; can be left as is for the others").
 *
 * A caller that shows the assistant's reasoning wraps its work in `during()`; every template stream made inside
 * it asks ai-service for the model's reasoning and hands it to the listener as it grows. Outside a scope
 * nothing asks, so every other stream in the product is exactly what it was. Scoped like
 * RunOptions, and restored in `finally`, so a queue worker never carries it into the
 * next job.
 */
final class StreamThinking
{
    private static ?Closure $listener = null;

    /**
     * @template T
     *
     * @param  callable(string $reasoningSoFar): void  $listener
     * @param  callable(): T  $work
     * @return T
     */
    public static function during(callable $listener, callable $work): mixed
    {
        $previous = self::$listener;
        self::$listener = Closure::fromCallable($listener);

        try {
            return $work();
        } finally {
            self::$listener = $previous;
        }
    }

    /** The listener of the scope this stream runs in, or null (thinking mode off). */
    public static function listener(): ?Closure
    {
        return self::$listener;
    }
}
