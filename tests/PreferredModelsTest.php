<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The caller's model preference, which could not be expressed until now.
 *
 * ⚠ THE SERVICE HAS HONOURED `preferred_models` SINCE ITS EXECUTOR WAS WRITTEN,
 * AND NO CLIENT COULD SEND IT. The capability existed and was unreachable from
 * outside - its controller never read the field off the wire.
 *
 * The caller that needed it is Portify's case-outcome verdict: a PAID deep
 * verdict escalates the model by case complexity (`VerdictDepthRouter`), and
 * from the day Portify's seam shipped on 2026-09-19 that escalation was dropped
 * at the boundary. A client paid for a deeper read and got whatever answered
 * first, which on that bench is a free flash-lite rung. Found 2026-10-22.
 *
 * ⚠ THESE TESTS ASSERT THE REQUEST BODY, not a return value. A field that never
 * crosses the wire is exactly the defect being closed, and only the sent request
 * can show it.
 */
class PreferredModelsTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    /** @param array<int, Response> $responses */
    private function client(array $responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(
            baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        );
    }

    private function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'result' => ['ok' => true], 'provider' => 'gemini', 'model' => 'gemini-2.5-pro',
        ]));
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        $request = end($this->sent);
        self::assertNotFalse($request, 'no request was sent');

        return json_decode((string) $request->getBody(), true) ?: [];
    }

    public function test_the_preference_reaches_the_wire(): void
    {
        $client = $this->client([$this->ok()]);

        $client->withPreferredModels([
            ['provider' => 'gemini', 'model' => 'gemini-2.5-pro'],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash'],
        ])->generateJson('assess.case.outcome', 'sys', 'go');

        $this->assertSame([
            ['provider' => 'gemini', 'model' => 'gemini-2.5-pro'],
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash'],
        ], $this->lastBody()['preferred_models'] ?? null);
    }

    /**
     * ⚠ ABSENT RATHER THAN NULL WHEN NOBODY ASKED. The body is filtered of nulls
     * and empty arrays before it is sent, so a caller with no opinion must not
     * put an empty field on the wire for the service to validate.
     */
    public function test_no_preference_sends_no_field(): void
    {
        $client = $this->client([$this->ok()]);

        $client->generateJson('assess.case.outcome', 'sys', 'go');

        $this->assertArrayNotHasKey('preferred_models', $this->lastBody());
    }

    /** An empty array is "no opinion", not "prefer nothing". */
    public function test_an_empty_preference_sends_no_field(): void
    {
        $client = $this->client([$this->ok()]);

        $client->withPreferredModels([])->generateJson('assess.case.outcome', 'sys', 'go');

        $this->assertArrayNotHasKey('preferred_models', $this->lastBody());
    }

    /**
     * ⚠ A CLONE, LIKE EVERY OTHER PER-CALL SETTING. The client is a singleton, so
     * a preference set in place would push one caller's model choice onto the
     * next request handled by the same worker - a paid deep verdict's escalation
     * landing on somebody else's free classify.
     */
    public function test_the_preference_does_not_leak_into_the_next_call(): void
    {
        $client = $this->client([$this->ok(), $this->ok()]);

        $client->withPreferredModels([['provider' => 'gemini', 'model' => 'gemini-2.5-pro']])
            ->generateJson('assess.case.outcome', 'sys', 'go');

        // The ORIGINAL client, which never asked for anything.
        $client->generateJson('porter.classify', 'sys', 'go');

        $this->assertArrayNotHasKey('preferred_models', $this->lastBody());
    }

    /** The fake records what was sent, so a product can assert on it. */
    public function test_the_fake_records_the_preference(): void
    {
        $fake = new FakeAiClient();
        $fake->queue(['ok' => true]);

        $fake->withPreferredModels([['provider' => 'gemini', 'model' => 'gemini-2.5-pro']])
            ->generateJson('assess.case.outcome', 'sys', 'go');

        $this->assertSame(
            [['provider' => 'gemini', 'model' => 'gemini-2.5-pro']],
            $fake->log->preferredModels,
        );
    }

    /** And reports nothing when nobody asked, so a test cannot read a stale value. */
    public function test_the_fake_records_nothing_when_no_preference_is_set(): void
    {
        $fake = new FakeAiClient();
        $fake->queue(['ok' => true]);

        $fake->generateJson('porter.classify', 'sys', 'go');

        $this->assertNull($fake->log->preferredModels);
    }
}
