<?php

namespace Shirahcan\AiWaterfall\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\AiWaterfall\AiUsageReport;
use Shirahcan\AiWaterfall\AiWaterfallClient;
use Shirahcan\AiWaterfall\Exceptions\AiUnavailableException;

/**
 * Usage figures are READ from the service, never recomputed by a product.
 *
 * ⚠ THE DEFECT THIS PREVENTS IS ALREADY ON THE RECORD. Portify priced its own
 * AI calls locally and its ledger reported $114.71 of spend from free providers
 * that never charged a penny - because a product cannot know the credential
 * TIER that served a call, and the tier is what decides the price. The service
 * knows; the product asks.
 */
class UsageReportTest extends TestCase
{
    private function client(Response ...$responses): AiWaterfallClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiWaterfallClient('http://127.0.0.1:8007', 'test-key', 60,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8007/']));
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'from' => '2026-09-01T00:00:00+00:00',
            'to' => '2026-09-22T00:00:00+00:00',
            'totals' => [
                'calls' => 420, 'tokens_in' => 90_000, 'tokens_out' => 12_000,
                'spend_micros' => 86_000, 'savings_micros' => 1_240_000,
                'savings_is_estimate' => true,
            ],
            'by_product' => [
                ['product' => 'portify', 'calls' => 400, 'spend_micros' => 86_000, 'savings_micros' => 1_200_000],
                ['product' => 'mploynow', 'calls' => 20, 'spend_micros' => 0, 'savings_micros' => 40_000],
            ],
            'by_tier' => [
                ['tier' => 'free', 'calls' => 413], ['tier' => 'paid', 'calls' => 7],
            ],
            'by_outcome' => ['ok' => 390, 'rate_limited' => 25, 'failed' => 5],
            'savings_basis' => 'Free-tier attempts priced at the paid rung.',
        ], $overrides);
    }

    public function test_it_carries_the_services_figures_untouched(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertSame(420, $report->calls);
        $this->assertSame(86_000, $report->spendMicros);
        $this->assertSame(1_240_000, $report->savingsMicros);
        $this->assertSame(102_000, $report->tokensTotal());
        $this->assertSame(0.086, $report->spend());
        $this->assertSame(1.24, $report->savings());
    }

    /**
     * ⚠ SAVINGS MUST NOT BE PRESENTABLE AS BANKED MONEY. It is a counterfactual,
     * so the basis travels with it and the estimate flag is always set.
     */
    public function test_savings_carries_its_basis_and_its_estimate_flag(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertTrue($report->savingsIsEstimate);
        $this->assertStringContainsString('paid rung', $report->savingsBasis);
    }

    /**
     * ⚠ AN OLDER SERVICE THAT OMITS THE FLAG MUST NOT READ AS EXACT. Defaulting
     * to false would turn a missing field into a confident claim.
     */
    public function test_a_missing_estimate_flag_defaults_to_estimated(): void
    {
        $payload = $this->payload();
        unset($payload['totals']['savings_is_estimate'], $payload['savings_basis']);

        $report = $this->client($this->json(200, $payload))->usageReport();

        $this->assertTrue($report->savingsIsEstimate);
        $this->assertStringContainsString('not reported', $report->savingsBasis);
    }

    public function test_a_product_slice_is_reachable_from_the_estate_view(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertSame(400, $report->forProduct('portify')['calls']);
        $this->assertSame(0, $report->forProduct('mploynow')['spend_micros']);
    }

    /**
     * ⚠ NULL AND ZERO ARE DIFFERENT ANSWERS. Null means the product made no
     * calls; a row of zeros would claim it ran and spent nothing. A product
     * whose AI silently stopped working looks exactly like a quiet one if those
     * are flattened - and this estate has already had a product sit in outage
     * unnoticed for want of that distinction.
     */
    public function test_an_absent_product_is_null_not_zero(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertNull($report->forProduct('studendly'));
    }

    /**
     * ⚠ FAILURES SIT BESIDE SPEND. A month of near-zero cost is excellent news
     * if the work succeeded on free rungs and an emergency if it was failing,
     * and the spend figure alone cannot tell those apart - one of them even
     * looks like a saving.
     */
    public function test_failures_are_counted_beside_spend(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertSame(30, $report->failedCalls());
        $this->assertSame(['free' => 413, 'paid' => 7], $report->callsByTier());
        $this->assertStringContainsString('30 not ok', $report->summary());
    }

    /**
     * ⚠ THE PRODUCT NAME COMES FROM THE TRUST KEY. A product that spells its own
     * name can spell it wrong, and `finance` vs `finance-suite` has already been
     * exactly that trap here.
     */
    public function test_my_usage_scopes_by_the_name_the_service_resolved(): void
    {
        $report = $this->client(
            $this->json(200, ['product' => 'portify']),
            $this->json(200, $this->payload()),
        )->myUsageReport();

        $this->assertInstanceOf(AiUsageReport::class, $report);
        $this->assertSame(420, $report->calls);
    }

    /** An unnamed caller fails loudly rather than silently reporting the estate. */
    public function test_an_unidentified_caller_refuses_rather_than_widening(): void
    {
        $this->expectException(AiUnavailableException::class);

        $this->client($this->json(200, []))->myUsageReport();
    }

    /**
     * ⚠ A CACHE FAULT MUST NOT BECOME A REPORTING OUTAGE. This package runs in
     * four products with different cache drivers. There is no container in this
     * test process, so the Cache facade throws - which is exactly the condition
     * being asserted: a TTL still returns a correct report by falling through to
     * a direct fetch.
     *
     * One misconfigured store should cost speed, never the answer.
     */
    public function test_a_ttl_still_reports_when_the_cache_is_unavailable(): void
    {
        $report = $this->client($this->json(200, $this->payload()))
            ->usageReport([], cacheSeconds: 300);

        $this->assertSame(420, $report->calls, 'a broken cache swallowed the report');
    }

    /** No TTL means a fresh read every time, which is right for a CLI. */
    public function test_without_a_ttl_each_call_fetches(): void
    {
        $client = $this->client(
            $this->json(200, $this->payload()),
            $this->json(200, $this->payload(['totals' => ['calls' => 999]])),
        );

        $this->assertSame(420, $client->usageReport()->calls);
        $this->assertSame(999, $client->usageReport()->calls,
            'the second call did not reach the service, so a CLI would show stale figures');
    }

    /**
     * ⚠ THE QUERY IS PART OF THE CACHE KEY. Two windows or product filters are
     * two different answers; sharing a key would serve the estate total to a
     * caller that asked about one product - a wrong number with total
     * confidence. Asserted through behaviour: differing queries must not
     * collide.
     */
    public function test_different_queries_do_not_share_an_answer(): void
    {
        $client = $this->client(
            $this->json(200, $this->payload(['totals' => ['calls' => 10]])),
            $this->json(200, $this->payload(['totals' => ['calls' => 20]])),
        );

        $this->assertSame(10, $client->usageReport(['product' => 'portify'], cacheSeconds: 300)->calls);
        $this->assertSame(20, $client->usageReport(['product' => 'mploynow'], cacheSeconds: 300)->calls);
    }

    /** The untouched payload stays reachable for anything the DTO omits. */
    public function test_the_raw_payload_is_preserved(): void
    {
        $report = $this->client($this->json(200, $this->payload()))->usageReport();

        $this->assertSame($this->payload()['by_provider'] ?? [], $report->byProvider);
        $this->assertArrayHasKey('totals', $report->raw);
    }
}
