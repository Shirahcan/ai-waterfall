<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\GenericUser;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Laravel\Console\ConsoleRoutes;

/**
 * Site management's console proxy (1.37): one route line in a product, a whitelist to ai-service,
 * the product's own key, the admin key only for estate rows, and the actor set by the product.
 */
class ConsoleProxyTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private MockHandler $mock;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ai-client.trust_key', 'trust');
    }

    protected function defineRoutes($router): void
    {
        ConsoleRoutes::mount('admin/ai-console');
    }

    private function client(string $adminKey = ''): void
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->sent));
        $http = new Guzzle(['handler' => $stack, 'base_uri' => 'http://ai.test/']);

        $this->app->instance(AiClient::class, new AiClient('http://ai.test', 'trust', 5, $http, $adminKey));
        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'ops@example.com']));
    }

    public function test_a_listed_read_is_forwarded_with_the_product_key_and_its_query(): void
    {
        $this->client();
        $this->mock->append(new Response(200, [], json_encode(['totals' => ['calls' => 3]])));

        $this->getJson('/admin/ai-console/usage?days=7&product=mploynow')
            ->assertOk()->assertJsonPath('totals.calls', 3);

        $req = $this->sent[0]['request'];
        $this->assertSame('/api/v1/usage', $req->getUri()->getPath());
        $this->assertSame('days=7&product=mploynow', $req->getUri()->getQuery());
        $this->assertSame('Bearer trust', $req->getHeaderLine('Authorization'));
    }

    public function test_the_actor_is_the_signed_in_user_never_the_body(): void
    {
        $this->client();
        $this->mock->append(new Response(200, [], json_encode(['changed' => true])));

        $this->patchJson('/admin/ai-console/tasks/summarize.note', ['prompt' => 'x', 'updated_by' => 'somebody-else'])->assertOk();

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('ops@example.com', $body['updated_by']);
        $this->assertSame('/api/v1/tasks/summarize.note', $this->sent[0]['request']->getUri()->getPath());
    }

    public function test_an_estate_row_without_the_admin_key_is_refused_without_calling_the_service(): void
    {
        $this->client();

        $this->getJson('/admin/ai-console/estate')->assertStatus(403)->assertJsonPath('error', 'admin_not_configured');
        $this->assertSame([], $this->sent);
    }

    public function test_an_estate_row_with_the_admin_key_uses_it(): void
    {
        $this->client('admin-key');
        $this->mock->append(new Response(200, [], json_encode(['products' => []])));

        $this->getJson('/admin/ai-console/vault/budgets')->assertOk();
        $this->assertSame('Bearer admin-key', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('/api/v1/admin/budgets', $this->sent[0]['request']->getUri()->getPath());
    }

    public function test_capabilities_are_asked_with_the_admin_key_when_the_product_holds_one(): void
    {
        $this->client('admin-key');
        $this->mock->append(new Response(200, [], json_encode(['is_admin' => true])));
        $this->getJson('/admin/ai-console/capabilities')->assertOk();
        $this->assertSame('Bearer admin-key', $this->sent[0]['request']->getHeaderLine('Authorization'));

        $this->sent = [];
        $this->client();
        $this->mock->append(new Response(200, [], json_encode(['is_admin' => false])));
        $this->getJson('/admin/ai-console/capabilities')->assertOk();
        $this->assertSame('Bearer trust', $this->sent[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_the_services_own_422_reaches_the_console_and_a_500_does_not(): void
    {
        $this->client();
        $this->mock->append(
            new Response(422, [], json_encode(['error' => 'unknown_variable', 'field' => 'prompt'])),
            new Response(500, [], json_encode(['message' => 'SQLSTATE secret internals'])),
        );

        $this->patchJson('/admin/ai-console/tasks/summarize.note', ['prompt' => '{{tone}}'])
            ->assertStatus(422)->assertJsonPath('field', 'prompt');

        $this->getJson('/admin/ai-console/state')->assertStatus(502)->assertJsonMissing(['message' => 'SQLSTATE secret internals']);
    }

    public function test_an_unlisted_path_does_not_exist(): void
    {
        $this->client();

        $this->getJson('/admin/ai-console/conversations')->assertNotFound();
        $this->getJson('/admin/ai-console/memory')->assertNotFound();
        $this->assertSame([], $this->sent);
    }

    public function test_a_knowledge_slug_keeps_its_slashes(): void
    {
        $this->client();
        $this->mock->append(new Response(200, [], json_encode(['slug' => 'consultant/payments/instalments'])));

        $this->getJson('/admin/ai-console/knowledge/consultant/payments/instalments')->assertOk();
        $this->assertSame('/api/v1/knowledge/entries/consultant/payments/instalments', $this->sent[0]['request']->getUri()->getPath());
    }

    public function test_acting_for_another_product_goes_with_the_admin_key(): void
    {
        $this->client('admin-key');
        $this->mock->append(new Response(200, [], json_encode(['tasks' => []])));

        $this->getJson('/admin/ai-console/tasks?as_product=mploynow')->assertOk();

        $req = $this->sent[0]['request'];
        $this->assertSame('Bearer admin-key', $req->getHeaderLine('Authorization'));
        $this->assertSame('as_product=mploynow', $req->getUri()->getQuery());
    }

    public function test_acting_for_another_product_without_the_admin_key_is_refused_here(): void
    {
        $this->client();

        $this->getJson('/admin/ai-console/personas?as_product=mploynow')->assertStatus(403)->assertJsonPath('error', 'admin_not_configured');
        $this->assertSame([], $this->sent);
    }

    public function test_assist_revert_and_products_are_listed(): void
    {
        $this->client('admin-key');
        $this->mock->append(
            new Response(200, [], json_encode(['suggestion' => 'x'])),
            new Response(200, [], json_encode(['changed' => true])),
            new Response(200, [], json_encode(['products' => []])),
        );

        $this->postJson('/admin/ai-console/assist', ['field' => 'voice', 'text' => 'a', 'instruction' => 'b'])->assertOk();
        $this->postJson('/admin/ai-console/tasks/summarize.note/revert', [])->assertOk();
        $this->getJson('/admin/ai-console/products')->assertOk();

        $this->assertSame('/api/v1/console/assist', $this->sent[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer trust', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('ops@example.com', json_decode((string) $this->sent[1]['request']->getBody(), true)['updated_by']);
        $this->assertSame('/api/v1/admin/products', $this->sent[2]['request']->getUri()->getPath());
        $this->assertSame('Bearer admin-key', $this->sent[2]['request']->getHeaderLine('Authorization'));
    }
}
