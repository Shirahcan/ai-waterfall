<?php

namespace Shirahcan\AiClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\AiClient\Laravel\Tools\Standard\PulseLine;
use Shirahcan\AiClient\Laravel\Tools\Standard\PulseProvider;
use Shirahcan\AiClient\Laravel\Tools\Standard\PulseReport;
use Shirahcan\AiClient\Laravel\Tools\Standard\PulseTool;
use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Shirahcan\AiClient\Time\TimeWindow;

/** `platform_pulse` (Portify plan porter-stats-reach 03a): the kit resolves the period, the product counts. */
class PulseToolTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $asked = [];

    private function provider(?PulseReport $report = null): PulseProvider
    {
        $test = $this;

        return new class($report, $test) implements PulseProvider
        {
            public function __construct(private ?PulseReport $report, private PulseToolTest $test) {}

            public function offersPulseTo(string $audience): bool
            {
                return $audience !== 'visitor';
            }

            public function domains(string $audience): array
            {
                return ['people', 'money'];
            }

            public function pulse(TimeWindow $window, ToolActor $actor, array $focus, ?string $person, bool $compare): PulseReport
            {
                $this->test->record(['window' => $window, 'focus' => $focus, 'person' => $person, 'compare' => $compare]);

                return $this->report ?? new PulseReport([
                    new PulseLine('people', 'Users joined', 7, previous: 4, change: '+3 (+75%)'),
                    PulseLine::unavailable('money', 'Checkouts'),
                ]);
            }
        };
    }

    /** @param  array<string, mixed>  $asked */
    public function record(array $asked): void
    {
        $this->asked = $asked;
    }

    public function test_it_resolves_the_period_in_the_askers_zone_and_groups_lines_by_area(): void
    {
        $out = (new PulseTool($this->provider()))->run(['window' => ['last' => '13h'], 'focus' => ['people', 'nonsense'], 'compare' => true],
            new ToolActor('u1', 'admin', timezone: 'Africa/Lagos'));

        $this->assertTrue($out['ok']);
        $this->assertSame('the last 13 hours', $out['result']['window']['name']);
        $this->assertSame('Africa/Lagos', $this->asked['window']->zone);
        $this->assertSame(['people'], $this->asked['focus']);
        $this->assertTrue($this->asked['compare']);
        $this->assertSame('+3 (+75%)', $out['result']['areas']['people'][0]['change']);
        $this->assertArrayNotHasKey('value', $out['result']['areas']['money'][0]);
        $this->assertSame('could not be read just now', $out['result']['areas']['money'][0]['could_not_read']);
        $this->assertStringStartsWith('the 13 hours before', $out['result']['compared_with']);
    }

    public function test_no_period_defaults_to_seven_days_and_says_so(): void
    {
        $out = (new PulseTool($this->provider()))->run([], new ToolActor('u1', 'consultant', timezone: 'UTC'));

        $this->assertSame('the last 7 days', $out['result']['window']['name']);
        $this->assertArrayHasKey('window_note', $out['result']);
    }

    public function test_a_period_it_cannot_read_is_an_error_for_the_model(): void
    {
        $out = (new PulseTool($this->provider()))->run(['window' => ['last' => 'soon']], new ToolActor('u1', 'admin'));

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('Never compute dates yourself', $out['error']);
    }

    public function test_an_ambiguous_person_returns_candidates_not_a_guess(): void
    {
        $tool = new PulseTool($this->provider(new PulseReport([], candidates: [['name' => 'Ivie A'], ['name' => 'Ivie B']])));

        $out = $tool->run(['person' => 'Ivie'], new ToolActor('u1', 'admin'));

        $this->assertFalse($out['result']['person_found']);
        $this->assertCount(2, $out['result']['candidates']);
        $this->assertSame('Ivie', $this->asked['person']);
    }

    public function test_never_offered_to_a_visitor(): void
    {
        $tool = new PulseTool($this->provider());

        $this->assertNull($tool->definitionFor('public'));
        $this->assertNotNull($tool->definitionFor('client'));
        $this->assertFalse($tool->run([], ToolActor::guest('g1'))['ok']);
    }
}
