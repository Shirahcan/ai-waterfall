<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * Speech to text through the vault (P20f2): the client sends the audio as a file, reads the
 * transcript back, and maps "no cleared key" to a refusal rather than an outage.
 */
class TranscribeTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

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
            baseUrl: 'http://127.0.0.1:8007',
            trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
        );
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_the_audio_goes_as_a_file_and_the_transcript_comes_back(): void
    {
        $client = $this->client([$this->json([
            'text' => 'Hello, I am a welder.', 'duration_seconds' => 12.5, 'language' => 'en',
            'provider' => 'groq', 'latency_ms' => 900,
        ])]);

        $out = $client->transcribe('AUDIO-BYTES', 'intro.webm', 'candidate.intro_video');

        $this->assertSame('Hello, I am a welder.', $out['text']);
        $this->assertSame(12.5, $out['duration_seconds']);
        $this->assertSame('groq', $out['provider']);

        $request = $this->sent[0];
        $this->assertSame('/api/v1/transcribe', $request->getUri()->getPath());
        $this->assertStringContainsString('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('filename="intro.webm"', $body);
        $this->assertStringContainsString('AUDIO-BYTES', $body);
        $this->assertStringContainsString('candidate.intro_video', $body);
    }

    public function test_no_cleared_key_is_a_refusal_not_an_outage(): void
    {
        $client = $this->client([$this->json([
            'error' => 'no_transcription_credential', 'message' => 'No transcription credential is available.',
        ], 503)]);

        $this->expectException(AllProvidersFailedException::class);
        $client->transcribe('x', 'a.webm', 'candidate.intro_video');
    }

    public function test_the_fake_returns_what_was_queued_and_refuses_when_empty(): void
    {
        $fake = (new FakeAiClient())->queueTranscription('hello there', 3.0);

        $this->assertSame('hello there', $fake->transcribe('abc', 'a.webm', 't')['text']);
        $this->assertSame([['filename' => 'a.webm', 'task' => 't', 'bytes' => 3]], $fake->transcribed);

        $this->expectException(AllProvidersFailedException::class);
        $fake->transcribe('abc', 'a.webm', 't');
    }
}
