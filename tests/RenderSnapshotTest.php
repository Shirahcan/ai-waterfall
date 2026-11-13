<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\FakeAiAdmin;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\RenderSnapshot;

/**
 * render()->snapshot() (1.70.0, shared program E2d): a picture of the product's own page, or the reason there is
 * none, never an exception for a refusal the product must act on.
 */
class RenderSnapshotTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    private function client(array $responses, string $adminKey = ''): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new AiClient(baseUrl: 'http://127.0.0.1:8007', trustKey: 'k',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']), adminKey: $adminKey);
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_a_card_comes_back_as_decoded_bytes_with_its_format(): void
    {
        $client = $this->client([$this->json(['image' => base64_encode('PNGBYTES'), 'format' => 'png', 'bytes' => 8, 'width' => 1200, 'height' => 630, 'quality' => null])]);

        $shot = $client->render()->snapshot('https://portify.shirah.co/share-card/a', waitFor: '[data-card-ready="true"]');

        $this->assertTrue($shot->ok());
        $this->assertSame('PNGBYTES', $shot->bytes);
        $this->assertSame('png', $shot->extension());
        $this->assertSame('image/png', $shot->mimeType());
        $this->assertSame([1200, 630], [$shot->width, $shot->height]);

        $sent = json_decode((string) $this->sent[0]->getBody(), true);
        $this->assertSame('/api/v1/render/snapshot', $this->sent[0]->getUri()->getPath());
        $this->assertSame(['url' => 'https://portify.shirah.co/share-card/a', 'width' => 1200, 'height' => 630, 'wait_for' => '[data-card-ready="true"]', 'max_bytes' => 300000], $sent);
    }

    public function test_a_jpeg_keeps_its_quality(): void
    {
        $shot = $this->client([$this->json(['image' => base64_encode('JPG'), 'format' => 'jpeg', 'width' => 1200, 'height' => 630, 'quality' => 80])])
            ->render()->snapshot('https://portify.shirah.co/share-card/a');

        $this->assertSame(['jpg', 'image/jpeg', 80], [$shot->extension(), $shot->mimeType(), $shot->quality]);
    }

    public function test_busy_unavailable_and_undrawable_are_retryable(): void
    {
        foreach ([[429, 'busy'], [503, 'render_unavailable'], [422, 'not_renderable']] as [$status, $code]) {
            $shot = $this->client([$this->json(['error' => $code, 'message' => 'x'], $status)])->render()->snapshot('https://portify.shirah.co/a');

            $this->assertFalse($shot->ok(), $code);
            $this->assertTrue($shot->retryable(), $code);
            $this->assertSame(RenderSnapshot::UNAVAILABLE, $shot->error, $code);
        }
    }

    public function test_a_setup_or_design_fault_is_named_and_not_retryable(): void
    {
        foreach (['host_not_registered', 'not_ready', 'too_large'] as $code) {
            $shot = $this->client([$this->json(['error' => $code, 'message' => 'why'], 422)])->render()->snapshot('https://portify.shirah.co/a');

            $this->assertSame($code, $shot->error);
            $this->assertFalse($shot->retryable(), $code);
            $this->assertSame('why', $shot->message);
        }
    }

    public function test_an_unreachable_service_is_retryable_not_a_crash(): void
    {
        $shot = $this->client([new \GuzzleHttp\Exception\ConnectException('down', new Request('POST', 'x'))])->render()->snapshot('https://portify.shirah.co/a');

        $this->assertTrue($shot->retryable());
    }

    public function test_the_fake_answers_what_was_queued_and_records_the_ask(): void
    {
        $fake = new FakeAiClient();
        $fake->queueSnapshot('https://p.test/a', RenderSnapshot::fromAnswer(['image' => base64_encode('X'), 'format' => 'png', 'width' => 1200, 'height' => 630]));

        $this->assertTrue($fake->render()->snapshot('https://p.test/a', maxBytes: 100000)->ok());
        $this->assertSame('host_not_registered', $fake->render()->snapshot('https://p.test/other')->error);
        $this->assertSame(100000, $fake->snapshotCalls()[0]['max_bytes']);
    }

    public function test_an_admin_sets_and_lists_a_products_public_hosts(): void
    {
        $client = $this->client([
            $this->json(['product' => 'portify', 'hosts' => ['portify.shirah.co']]),
            $this->json(['hosts' => [['product' => 'portify', 'hosts' => ['portify.shirah.co'], 'set_by' => null]]]),
        ], 'admin-key');

        $this->assertSame(['portify.shirah.co'], $client->admin()->setPublicHosts('portify', ['portify.shirah.co']));
        $this->assertSame('PUT', $this->sent[0]->getMethod());
        $this->assertSame('/api/v1/admin/public-hosts', $this->sent[0]->getUri()->getPath());
        $this->assertSame('portify', $client->admin()->publicHosts()[0]['product']);

        $fake = new FakeAiAdmin();
        $this->assertSame(['portify.shirah.co'], $fake->setPublicHosts('portify', ['PORTIFY.shirah.co']));
        $this->assertSame(['portify.shirah.co'], $fake->publicHosts()[0]['hosts']);
    }
}
