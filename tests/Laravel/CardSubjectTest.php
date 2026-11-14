<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Laravel\Terminal\CardConsoleAsk;
use Shirahcan\AiClient\Laravel\Terminal\CardSubject;
use Shirahcan\AiClient\Laravel\Terminal\ChangeDescriber;
use Shirahcan\AiClient\Laravel\Terminal\TerminalSubject;

/**
 * A share card as a console subject, and what a card Build's answer means (1.73.0, shared program E4c).
 *
 * ⚠ WHAT THIS GUARDS: a card is its own subject (`{kind}:{id}`), opened only when the product says so; its header
 * names the document; a refused card is an answer, not a change; the Build's notes reach the session as findings.
 */
class CardSubjectTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    private function subject(): CardSubject
    {
        return new class extends CardSubject
        {
            public function kind(): string
            {
                return 'site_page_card';
            }

            protected function mayOpen(Request $request, string $documentId): bool
            {
                return $documentId === 'p1';
            }

            protected function documentLabel(string $documentId): ?string
            {
                return $documentId === 'p1' ? 'Study permit checklist' : null;
            }

            public function design(string $documentId): array
            {
                return [['primitive' => 'heading', 'props' => ['text' => 'Study', 'level' => 1]]];
            }

            protected function budgetTokens(): int
            {
                return 48000;
            }

            public function changes(string $ref): ChangeDescriber
            {
                return new class implements ChangeDescriber
                {
                    public function between(array $before, array $after, array $context = []): array
                    {
                        return ['changes' => [], 'total' => 0];
                    }
                };
            }
        };
    }

    public function test_a_card_is_its_own_subject_opened_only_when_the_product_allows_it(): void
    {
        $subject = $this->subject();
        $request = Request::create('/');

        $this->assertSame('site_page_card:p1', $subject->ref($request, 'site_page_card', 'p1'));
        $this->assertNull($subject->ref($request, 'site_page_card', 'p2'), 'another document is a 404');
        $this->assertNull($subject->ref($request, 'site_page', 'p1'), 'the page itself is a different subject');

        $this->assertSame('Study permit checklist: share card', $subject->label('site_page_card:p1'));
        $this->assertSame('This page: share card', $subject->label('site_page_card:p9'));
        $this->assertSame(48000, $subject->sizing('site_page_card:p1')['budget_tokens']);
        $this->assertGreaterThan(0, $subject->sizing('site_page_card:p1')['design_chars']);
        $this->assertInstanceOf(TerminalSubject::class, $subject);
    }

    public function test_a_card_builds_answer_is_read_as_changed_refused_or_noted(): void
    {
        /** @var CardSubjectTestAsk $ask the outcome reading needs no session store */
        $ask = (new ReflectionClass(CardSubjectTestAsk::class))->newInstanceWithoutConstructor();

        $changed = $ask->read(['summary' => 'I made the title bigger.', 'data' => [
            'changed' => true, 'refused' => false, 'checks' => [], 'notes' => ['Suggestion: add the photo.'],
            'changes' => ['changes' => [['uid' => 'a', 'label' => 'Title', 'how' => 'changed']], 'total' => 1],
        ]]);
        $this->assertTrue($changed['ok']);
        $this->assertTrue($changed['changed']);
        $this->assertSame(1, $changed['elements']);
        $this->assertSame('Suggestion: add the photo.', $changed['findings'][0]['text']);

        $refused = $ask->read(['summary' => 'I could not.', 'data' => ['changed' => true, 'refused' => true, 'reason' => 'The card that came back could not be read, so nothing changed.']]);
        $this->assertFalse($refused['ok']);
        $this->assertFalse($refused['changed'], 'a refused card never counts as a change');
        $this->assertSame('The card that came back could not be read, so nothing changed.', $refused['reason']);
    }
}

/** A product's card ask, as far as the outcome goes. */
class CardSubjectTestAsk extends CardConsoleAsk
{
    protected function budget(): int
    {
        return 48000;
    }

    /** @return array<string, mixed> */
    public function read(array $result): array
    {
        return $this->outcomeOf([], $result);
    }
}
