<?php

namespace Shirahcan\AiClient;

/**
 * What {@see FakeAiClient} was asked to do, shared across every clone of it.
 *
 * ⚠ IT IS AN OBJECT FOR ONE REASON: `withBudget()` and `withCorrelation()`
 * return `clone $this`, and they have to - the client is a singleton, so
 * mutating in place would leak one user's budget or correlation onto the next
 * request handled by the same worker.
 *
 * But a clone copies ARRAYS by value. So every call recorded after a `with*()`
 * landed on the clone and was invisible from the instance the test was holding:
 *
 *     $fake = new FakeAiClient();
 *     $this->app->instance(AiClient::class, $fake);
 *     // ... code under test calls ->withBudget(6)->generateJson(...)
 *     $fake->calls;   // []  <- the call happened, and the fake forgot it
 *
 * ⚠ AND IT FAILS AS A FALSE NEGATIVE, which is the worst direction. A test
 * asserting "the AI was called with X" sees an empty log and reports that the
 * code under test never called the AI - so the natural reaction is to go
 * looking for a bug in working code. Worse, a test asserting "the AI was NOT
 * called" passes unconditionally.
 *
 * An object is shared by handle across clones, so the log is the same log.
 */
class FakeCallLog
{
    /** @var array<int, array{task: string, mode: string, payload: mixed}> */
    public array $calls = [];

    /** @var array<int, array{task: string, query: string}> */
    public array $searches = [];

    /**
     * The correlation bag of the LAST call, or null.
     *
     * Last rather than a list, deliberately: a test asking "whose question was
     * this" is almost always asserting about the one call it just triggered,
     * and `->calls` already holds the sequence for the rare case that is not
     * true.
     *
     * @var array<string, string>|null
     */
    public ?array $correlation = null;

    /**
     * The voice the last call asked to speak in, or null.
     *
     * @var array{persona: string, audience: ?string}|null
     */
    public ?array $persona = null;
}
