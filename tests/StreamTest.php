<?php

namespace Shirahcan\AiWaterfall\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiWaterfall\AiStreamEvent;
use Shirahcan\AiWaterfall\AiWaterfallClient;
use Shirahcan\AiWaterfall\Exceptions\AiUnavailableException;
use Shirahcan\AiWaterfall\Exceptions\AllProvidersFailedException;

/**
 * Token streaming, through the package - which is the ONLY door to the service.
 *
 * ⚠ THESE TESTS EXIST SO NOBODY HAND-ROLLS AN SSE CLIENT IN A PRODUCT. The
 * trust key, base URL, product identity, socket timeouts and error translation
 * all live in one class on purpose; a product that reaches the loopback URL
 * directly re-implements all five, and gets at least one of them wrong.
 */
class StreamTest extends TestCase
{
    private function client(Response ...$responses): AiWaterfallClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiWaterfallClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    private function sse(string ...$frames): Response
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], implode('', $frames));
    }

    private function frame(string $event, array $data): string
    {
        return 'event: '.$event."\n".'data: '.json_encode($data)."\n\n";
    }

    /** @return array<int, AiStreamEvent> */
    private function drain(iterable $stream): array
    {
        $out = [];
        foreach ($stream as $event) {
            $out[] = $event;
        }

        return $out;
    }

    public function test_it_streams_tokens_and_ends_on_done(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('stage', ['provider' => 'gemini', 'model' => 'flash']),
            $this->frame('token', ['text' => 'Hello ']),
            $this->frame('token', ['text' => 'world']),
            $this->frame('done', ['provider' => 'gemini', 'model' => 'flash']),
        ));

        $events = $this->drain($client->generateStream('chat.answer', 'sys', 'hi'));

        $text = implode('', array_map(
            fn (AiStreamEvent $e) => $e->type === AiStreamEvent::TOKEN ? $e->text() : '',
            $events,
        ));

        $this->assertSame('Hello world', $text);
        $this->assertSame(AiStreamEvent::DONE, end($events)->type);
    }

    /**
     * ⚠ THE REALISTIC FAILURE, AND THE ONE LOCALHOST HIDES. Under any real
     * network a frame is split across reads - `data: {"te` then `xt":"hi"}` - and
     * a parser that assumes one frame per chunk produces silent corruption
     * rather than an error.
     */
    public function test_a_frame_split_across_chunks_is_reassembled(): void
    {
        $whole = $this->frame('open', ['stream_id' => 'abc'])
            .$this->frame('token', ['text' => 'split me'])
            .$this->frame('done', []);

        // Hand the body over in deliberately awkward 7-byte slices.
        $client = $this->client(new Response(200, [], implode('', str_split($whole, 7))));

        $events = $this->drain($client->generateStream('chat.answer', 'sys', 'hi'));
        $tokens = array_values(array_filter($events, fn ($e) => $e->type === AiStreamEvent::TOKEN));

        $this->assertCount(1, $tokens);
        $this->assertSame('split me', $tokens[0]->text());
    }

    /**
     * ⚠ THE EVENT EVERY CONSUMER FORGETS. A rung that dies mid-sentence has
     * already put text on someone's screen; the next rung starts the answer
     * over. Swallowing `discard` here would glue two half-answers together and
     * present them as finished prose.
     */
    public function test_discard_is_surfaced_to_the_caller(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('token', ['text' => 'first attempt']),
            $this->frame('discard', ['reason' => 'rung failed']),
            $this->frame('token', ['text' => 'second attempt']),
            $this->frame('done', []),
        ));

        $types = array_map(fn ($e) => $e->type, $this->drain($client->generateStream('t', 's', 'p')));

        $this->assertContains(AiStreamEvent::DISCARD, $types,
            'discard was swallowed, so the caller will concatenate two different answers');
        $this->assertSame(
            [AiStreamEvent::OPEN, AiStreamEvent::TOKEN, AiStreamEvent::DISCARD, AiStreamEvent::TOKEN, AiStreamEvent::DONE],
            $types,
        );
    }

    /**
     * ⚠ A STREAM THAT JUST STOPS IS A FAILURE, NOT A SUCCESS. Without this the
     * caller keeps the tokens it had, sees no error, and shows a truncated
     * half-answer that reads as complete - then someone acts on it.
     */
    public function test_a_stream_that_ends_without_a_terminal_event_is_an_error(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('token', ['text' => 'half an ans']),
        ));

        $this->expectException(AiUnavailableException::class);
        $this->drain($client->generateStream('t', 's', 'p'));
    }

    public function test_the_stream_id_reaches_the_caller_before_any_token(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'cancel-me']),
            $this->frame('token', ['text' => 'x']),
            $this->frame('done', []),
        ));

        $seen = [];
        $idAt = null;

        foreach ($client->generateStream('t', 's', 'p', null, function (string $id) use (&$idAt, &$seen) {
            $idAt = count($seen);
        }) as $event) {
            $seen[] = $event->type;
        }

        $this->assertSame(0, $idAt,
            'the id must arrive before any token, or a cancel cannot fire until the answer is half-written');
    }

    /** A refusal arrives as ordinary JSON, and must map to the SAME exception as the buffered path. */
    public function test_a_refusal_maps_to_the_familiar_exception(): void
    {
        $client = $this->client(new Response(502, [], json_encode([
            'error'    => 'all_providers_failed',
            'message'  => 'Every provider refused.',
            'attempts' => [],
        ])));

        $this->expectException(AllProvidersFailedException::class);
        $this->drain($client->generateStream('t', 's', 'p'));
    }

    /**
     * ⚠ THE STREAMED FIELD IS NOT THE WHOLE ANSWER. Porter's classifier
     * returns the conversational reply alongside the intent, the matched keys
     * and the actions it wants. A caller given only the prose must re-run the
     * call blocking to get the structure - which costs more than never
     * streaming at all - so `done` carries the decoded document.
     */
    public function test_done_carries_the_whole_decoded_document(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('token', ['text' => 'Sure, here you go.']),
            $this->frame('done', [
                'provider' => 'groq',
                'model' => 'x',
                'data' => ['answer' => 'Sure, here you go.', 'intent' => 'read', 'keys' => ['cases.count']],
            ]),
        ));

        $events = $this->drain($client->generateStream('chat.classify', 's', 'p', 'answer'));
        $done = end($events);

        $this->assertSame(AiStreamEvent::DONE, $done->type);
        $this->assertSame('read', $done->document()['intent'] ?? null,
            'the structure alongside the prose was dropped, so the caller must pay for a second blocking call');
        $this->assertSame(['cases.count'], $done->document()['keys'] ?? null);
    }

    /** A plain-text generation has no document, and must say so rather than guess. */
    public function test_a_text_stream_reports_no_document(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('token', ['text' => 'hi']),
            $this->frame('done', ['provider' => 'groq', 'model' => 'x']),
        ));

        $events = $this->drain($client->generateStream('t', 's', 'p'));
        $done = end($events);
        $this->assertNull($done->document());
    }

    public function test_cancel_reports_whether_the_service_stopped_it(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['cancelled' => true])));

        $this->assertTrue($client->cancelStream('abc'));
    }

    /**
     * ⚠ A FAILED CANCEL MUST NOT THROW. The caller has already told its user the
     * turn is stopped; an exception here would either resurrect a spinner or
     * report an error for something the user did deliberately.
     */
    public function test_a_failed_cancel_is_false_not_an_exception(): void
    {
        $client = $this->client(new Response(500, [], 'boom'));

        $this->assertFalse($client->cancelStream('abc'));
    }

    public function test_a_terminal_cancelled_event_ends_the_stream_cleanly(): void
    {
        $client = $this->client($this->sse(
            $this->frame('open', ['stream_id' => 'abc']),
            $this->frame('token', ['text' => 'partial']),
            $this->frame('cancelled', ['reason' => 'client']),
        ));

        $events = $this->drain($client->generateStream('t', 's', 'p'));

        $this->assertSame(AiStreamEvent::CANCELLED, end($events)->type);
    }
}
