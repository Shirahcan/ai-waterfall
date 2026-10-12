<?php

namespace Shirahcan\AiClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Shirahcan\AiClient\AiAdmin;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AdminNotConfiguredException;
use Shirahcan\AiClient\FakeAiClient;

/**
 * The vault surface: a separate key, and a key that never comes back out.
 */
class AdminTest extends TestCase
{
    /** @var array<int, Request> */
    private array $sent = [];

    /** @param array<int, Response> $responses */
    private function client(array $responses, string $adminKey = 'admin-key'): AiClient
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
            trustKey: 'call-key',
            http: new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']),
            adminKey: $adminKey,
        );
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    private function bearer(int $i = 0): string
    {
        return $this->sent[$i]->getHeaderLine('Authorization');
    }

    /*
    |--------------------------------------------------------------------------
    | The two keys
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠ THE CENTRAL ASSERTION. The vault is called with the ADMIN key, and
     * ordinary generation is still called with the trust key.
     *
     * If these were ever the same header, every product's hot-path key - four
     * plaintext `.env` lines on one box - would be an estate-wide root key able
     * to mint trust keys and clear a credential to receive client passports.
     */
    public function test_the_vault_is_called_with_the_admin_key_and_generation_is_not(): void
    {
        $client = $this->client([
            $this->json(['credentials' => [], 'summary' => []]),
            $this->json([
                'result' => ['ok' => true], 'provider' => 'gemini', 'model' => 'm',
                'latency_ms' => 1, 'tokens' => ['in' => 1, 'out' => 1], 'attempts' => [],
            ]),
        ]);

        $client->admin()->credentials();
        $client->generateJson('chat.answer', 's', 'p');

        $this->assertSame('Bearer admin-key', $this->bearer(0));
        $this->assertSame('Bearer call-key', $this->bearer(1));
    }

    /**
     * ⚠ AND THE ADMIN KEY DOES NOT LEAK ONTO THE SHARED SINGLETON. `admin()`
     * clones; if it mutated in place, the very next generation call handled by
     * the same worker would present vault rights.
     */
    public function test_asking_for_the_vault_does_not_privilege_the_shared_client(): void
    {
        $client = $this->client([
            $this->json(['credentials' => [], 'summary' => []]),
            $this->json(['credentials' => []]),
        ]);

        $client->admin();
        $client->admin()->credentials();
        $client->credentialStatus();

        $this->assertSame('Bearer admin-key', $this->bearer(0));
        $this->assertSame('Bearer call-key', $this->bearer(1));
    }

    /**
     * ⚠ A MISSING ADMIN KEY THROWS, AND NOT AS AN AI FAILURE. Every product
     * catches `AiUnavailableException` to degrade gracefully, which is exactly
     * the wrong response: a console would show empty screens, an operator would
     * conclude the vault was empty, and nobody would be told the product was
     * never authorised to look.
     */
    public function test_no_admin_key_throws_a_configuration_error(): void
    {
        $client = $this->client([], adminKey: '');

        $this->assertFalse($client->canAdminister());

        $this->expectException(AdminNotConfiguredException::class);
        $client->admin();
    }

    public function test_the_configuration_error_is_not_an_ai_failure(): void
    {
        /*
         * Asserted structurally rather than by catching, because the mistake
         * this prevents is a future subclass change: the moment this extends an
         * AI exception, four products' degrade paths start swallowing a
         * deployment fault.
         */
        $this->assertInstanceOf(\RuntimeException::class, AdminNotConfiguredException::make());
        $this->assertNotInstanceOf(
            \Shirahcan\AiClient\Exceptions\AiUnavailableException::class,
            AdminNotConfiguredException::make(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    public function test_saving_a_credential_posts_it(): void
    {
        $this->client([$this->json(['credential' => ['id' => 7, 'provider' => 'groq']])])
            ->admin()->saveCredential([
                'provider' => 'groq', 'kind' => 'generation', 'model' => 'openai/gpt-oss-120b',
                'api_key' => 'sk-live-0001', 'tier' => 'free',
            ]);

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertSame('api/v1/admin/credentials', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertSame('sk-live-0001', $this->body()['api_key']);
    }

    /** ⚠ PATCH, because the service treats absent fields as untouched. */
    public function test_updating_sends_only_what_changed(): void
    {
        $this->client([$this->json(['credential' => ['id' => 7, 'enabled' => false]])])
            ->admin()->updateCredential(7, ['enabled' => false]);

        $this->assertSame('PATCH', $this->sent[0]->getMethod());
        $this->assertSame(['enabled' => false], $this->body());
    }

    /**
     * ⚠ A NAMED DECIDER IS PART OF THE SIGNATURE, not an optional extra. The
     * question asked after an incident is "who decided this provider could see
     * client passports", and an unattributed clearance is indistinguishable
     * from one nobody made.
     */
    public function test_clearance_cannot_be_requested_anonymously(): void
    {
        $required = array_filter(
            (new ReflectionMethod(AiAdmin::class, 'clearSensitive'))->getParameters(),
            fn (ReflectionParameter $p) => ! $p->isOptional(),
        );

        $this->assertSame(['id', 'decidedBy'], array_map(fn ($p) => $p->getName(), array_values($required)));
    }

    public function test_clearing_posts_the_decider(): void
    {
        $this->client([$this->json(['credential' => ['id' => 7, 'sensitive_ok' => true]])])
            ->admin()->clearSensitive(7, 'owner@shirah.co');

        $this->assertSame('api/v1/admin/credentials/7/sensitive', ltrim($this->sent[0]->getUri()->getPath(), '/'));
        $this->assertTrue($this->body()['sensitive_ok']);
        $this->assertSame('owner@shirah.co', $this->body()['decided_by']);
    }

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    */

    /**
     * ⚠ A CLEARED THRESHOLD MUST CROSS THE WIRE AS NULL. The habitual
     * `array_filter(fn ($v) => $v !== null)` would have stripped it, and
     * "remove the hard cap" would have become unexpressible - the service
     * validates both as `present` precisely because clearing a cap and not
     * mentioning it are different intentions.
     */
    public function test_clearing_a_cap_sends_an_explicit_null(): void
    {
        $this->client([$this->json(['budget' => ['product' => 'portify', 'armed' => false]])])
            ->admin()->setBudget('portify', null, null);

        $body = $this->body();

        $this->assertSame('PUT', $this->sent[0]->getMethod());
        $this->assertArrayHasKey('soft_micros', $body);
        $this->assertArrayHasKey('hard_micros', $body);
        $this->assertNull($body['soft_micros']);
        $this->assertNull($body['hard_micros']);

        // And the address is OMITTED rather than nulled: the service keeps the
        // one it has, because its column is NOT NULL.
        $this->assertArrayNotHasKey('notify_to', $body);
    }

    public function test_arming_a_cap_sends_micros_and_the_address(): void
    {
        $this->client([$this->json(['budget' => ['armed' => true]])])
            ->admin()->setBudget('portify', 20_000_000, 50_000_000, 'owner@shirah.co');

        $this->assertSame([
            'product' => 'portify', 'period' => 'month',
            'soft_micros' => 20_000_000, 'hard_micros' => 50_000_000,
            'notify_to' => 'owner@shirah.co',
        ], $this->body());
    }

    /*
    |--------------------------------------------------------------------------
    | The stop and the keys
    |--------------------------------------------------------------------------
    */

    public function test_stopping_requires_a_reason_in_the_signature(): void
    {
        $required = array_filter(
            (new ReflectionMethod(AiAdmin::class, 'stop'))->getParameters(),
            fn (ReflectionParameter $p) => ! $p->isOptional(),
        );

        $this->assertSame(['scope', 'by', 'reason'], array_map(fn ($p) => $p->getName(), array_values($required)));
    }

    public function test_stopping_posts_the_scope_and_the_reason(): void
    {
        $this->client([$this->json(['global' => ['stopped' => true]])])
            ->admin()->stop('global', 'owner@shirah.co', 'a key leaked');

        $this->assertSame([
            'scope' => 'global', 'by' => 'owner@shirah.co', 'reason' => 'a key leaked',
        ], $this->body());
    }

    /**
     * ⚠ THERE IS NO WAY TO ASK FOR AN ADMIN KEY. Everything else a leaked admin
     * key could do is undone by rotating it; a second admin key it minted for
     * itself would survive the rotation, and nothing in a list of trust keys
     * would look wrong about it.
     */
    public function test_the_client_offers_no_way_to_mint_an_admin_key(): void
    {
        $names = array_map(
            fn (ReflectionParameter $p) => $p->getName(),
            (new ReflectionMethod(AiAdmin::class, 'issueTrustKey'))->getParameters(),
        );

        $this->assertSame(['product', 'by', 'label'], $names);

        $this->client([$this->json(['trust_key' => ['id' => 1], 'key_shown_once' => 'k'])])
            ->admin()->issueTrustKey('finance', 'owner@shirah.co');

        $this->assertArrayNotHasKey('is_admin', $this->body());
        $this->assertArrayNotHasKey('admin', $this->body());
    }

    public function test_revoking_a_key_deletes_it(): void
    {
        $this->client([$this->json(['revoked' => true, 'remaining_active_call_keys' => 0])])
            ->admin()->revokeTrustKey(4);

        $this->assertSame('DELETE', $this->sent[0]->getMethod());
        $this->assertStringContainsString('api/v1/admin/trust-keys/4', (string) $this->sent[0]->getUri());
    }

    /*
    |--------------------------------------------------------------------------
    | The fake
    |--------------------------------------------------------------------------
    */

    public function test_the_fake_vault_is_a_real_subclass(): void
    {
        $this->assertInstanceOf(AiAdmin::class, (new FakeAiClient())->admin());
    }

    /** ⚠ ONE INSTANCE, or a console test that writes then reads sees nothing. */
    public function test_the_fake_vault_remembers_what_was_written(): void
    {
        $fake = new FakeAiClient();

        $fake->admin()->saveCredential([
            'provider' => 'groq', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0001', 'tier' => 'free',
        ]);

        $this->assertCount(1, $fake->admin()->credentials()['credentials']);
    }

    /**
     * ⚠ THE FAKE NEVER HANDS A KEY BACK EITHER. A double that returned the
     * secret would let a console be built around reading one - the single thing
     * the real surface will never give it.
     */
    public function test_the_fake_fingerprints_instead_of_returning_the_key(): void
    {
        $fake = new FakeAiClient();

        $row = $fake->admin()->saveCredential([
            'provider' => 'groq', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-SECRET-0001', 'tier' => 'free',
        ]);

        $this->assertArrayNotHasKey('api_key', $row);
        $this->assertSame(substr(hash('sha256', 'sk-live-SECRET-0001'), 0, 8), $row['key_fingerprint']);
        $this->assertStringNotContainsString('SECRET', json_encode($fake->admin()->credentials()));
    }

    /** The same (provider, label) rotates in the fake, as it does on the service. */
    public function test_the_fake_rotates_rather_than_duplicating(): void
    {
        $fake = new FakeAiClient();

        $first = $fake->admin()->saveCredential([
            'provider' => 'groq', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0001', 'tier' => 'free',
        ]);

        $second = $fake->admin()->saveCredential([
            'provider' => 'groq', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0002', 'tier' => 'free',
        ]);

        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, $fake->admin()->credentials()['credentials']);
        $this->assertNotSame($first['key_fingerprint'], $second['key_fingerprint']);
    }

    /** And a rotation keeps the clearance, because the contract is with the provider. */
    public function test_the_fake_keeps_clearance_across_a_rotation(): void
    {
        $fake = new FakeAiClient();

        $row = $fake->admin()->saveCredential([
            'provider' => 'gemini', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0001', 'tier' => 'paid',
        ]);

        $fake->admin()->clearSensitive($row['id'], 'owner@shirah.co');

        $rotated = $fake->admin()->saveCredential([
            'provider' => 'gemini', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0002', 'tier' => 'paid',
        ]);

        $this->assertTrue($rotated['sensitive_ok']);
        $this->assertSame('owner@shirah.co', $rotated['sensitive_cleared_by']);
    }

    /** ⚠ And the plain update cannot grant it, however the array is shaped. */
    public function test_the_fake_update_cannot_grant_clearance(): void
    {
        $fake = new FakeAiClient();

        $row = $fake->admin()->saveCredential([
            'provider' => 'groq', 'kind' => 'generation', 'model' => 'm',
            'label' => 'primary', 'api_key' => 'sk-live-0001', 'tier' => 'free',
        ]);

        $updated = $fake->admin()->updateCredential($row['id'], ['sensitive_ok' => true, 'order' => 5]);

        $this->assertFalse($updated['sensitive_ok']);
        $this->assertSame(5, $updated['order']);
    }

    /**
     * ⚠ THE UNARMED PRODUCTS ARE THE POINT. `ai_budget_limits` was empty on
     * production, so the hard cap could never fire for anybody - and "no cap"
     * is indistinguishable from "a generous cap" from a product's side.
     */
    public function test_the_fake_lists_products_with_no_cap(): void
    {
        $budgets = (new FakeAiClient())->admin()->budgets();

        $this->assertCount(4, $budgets['budgets']);
        $this->assertSame(0, $budgets['summary']['armed']);
        $this->assertFalse($budgets['budgets'][0]['armed']);
    }

    public function test_the_fake_arms_and_disarms_a_cap(): void
    {
        $fake = new FakeAiClient();

        $armed = $fake->admin()->setBudget('portify', 20_000_000, 50_000_000, 'owner@shirah.co');
        $this->assertTrue($armed['armed']);
        $this->assertSame(1, $fake->admin()->budgets()['summary']['armed']);

        $disarmed = $fake->admin()->setBudget('portify', null, null);
        $this->assertFalse($disarmed['armed']);
        // The address survives disarming, as it does on the service.
        $this->assertSame('owner@shirah.co', $disarmed['notify_to']);
    }

    public function test_the_fake_reports_a_product_over_its_hard_cap(): void
    {
        $fake = new FakeAiClient();
        $fake->vault()->seedSpend('portify', 60_000_000);
        $fake->admin()->setBudget('portify', 20_000_000, 50_000_000, 'owner@shirah.co');

        $portify = collect($fake->admin()->budgets()['budgets'])->firstWhere('product', 'portify');

        $this->assertTrue($portify['refusing']);
        $this->assertStringContainsString('over_budget', $portify['state']);
    }

    /**
     * ⚠ THE ASYMMETRY, IN THE DOUBLE TOO. Under a global stop every product
     * REFUSES while no product's own switch is STOPPED. A fake that collapsed
     * these would let a console ship where resuming one product appears to do
     * nothing.
     */
    public function test_the_fake_separates_a_global_stop_from_a_product_stop(): void
    {
        $fake = new FakeAiClient();

        $state = $fake->admin()->stop('global', 'owner@shirah.co', 'a key leaked');

        $this->assertTrue($state['global']['stopped']);
        $this->assertSame('a key leaked', $state['global']['detail']['reason']);

        foreach ($state['products'] as $row) {
            $this->assertTrue($row['refusing']);
            $this->assertFalse($row['stopped']);
        }

        $resumed = $fake->admin()->resume('global', 'owner@shirah.co');
        $this->assertFalse($resumed['products'][0]['refusing']);
    }

    public function test_the_fake_tracks_which_products_can_call(): void
    {
        $fake = new FakeAiClient();

        $issued = $fake->admin()->issueTrustKey('finance', 'owner@shirah.co', 'finance prod');
        $this->assertFalse($issued['trust_key']['is_admin']);

        $products = collect($fake->admin()->trustKeys()['products'])->keyBy('product');
        $this->assertTrue($products['finance']['can_call']);
        $this->assertFalse($products['studendly']['can_call']);

        $revoked = $fake->admin()->revokeTrustKey($issued['trust_key']['id']);
        $this->assertSame(0, $revoked['remaining_active_call_keys']);
        $this->assertStringContainsString('no working trust key', (string) $revoked['warning']);
    }

    /**
     * ⚠ AND THE FAKE CAN BE AN UNAUTHORISED PRODUCT, because that is the
     * deployment mistake most easily made: shipping the console to a product
     * whose env never got the key. A double that always allowed `admin()` would
     * leave the "you are not authorised" branch untested, and that branch is
     * what stops a console rendering an empty vault as an empty vault.
     */
    public function test_the_fake_can_pretend_to_have_no_admin_key(): void
    {
        $fake = (new FakeAiClient())->withoutAdminKey();

        $this->assertFalse($fake->canAdminister());

        $this->expectException(AdminNotConfiguredException::class);
        $fake->admin();
    }
}
