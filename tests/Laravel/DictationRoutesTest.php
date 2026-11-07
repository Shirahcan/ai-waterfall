<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Shirahcan\AiClient\FakeAiClient;
use Shirahcan\AiClient\Laravel\Dictation\DictationRoutes;
use Shirahcan\AiClient\Laravel\Dictation\DictationSettings;
use Shirahcan\AiClient\Laravel\Dictation\DictationTranscriber;

/**
 * Live dictation in the kit (1.51): one route every product mounts, the same answer shape, the same
 * per-person minutes, and the product's own switch.
 */
class DictationRoutesTest extends TestCase
{
    private FakeAiClient $ai;

    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.name', 'MployNow');
        $app['config']->set('ai-client.trust_key', 'trust');
        $app['config']->set('ai-client.dictation.enabled', true);
        $app['config']->set('ai-client.dictation.daily_minutes', 1);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineRoutes($router): void
    {
        DictationRoutes::mount();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->actingAs(new GenericUser(['id' => 42]));
    }

    /** A 16 kHz mono 16-bit PCM WAV of the given length: 32,000 bytes a second after a 44-byte header. */
    private function wav(int $seconds): UploadedFile
    {
        $data = str_repeat("\0", 32000 * $seconds);
        $header = 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'.'fmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($data));

        return UploadedFile::fake()->createWithContent('phrase.wav', $header.$data)->mimeType('audio/wav');
    }

    public function test_a_phrase_comes_back_as_words_in_the_estates_shape_with_the_products_task(): void
    {
        $this->ai->queueTranscription('Shortlist Amara for the welder role');

        $this->post('/ai/dictation', ['audio' => $this->wav(3), 'surface' => 'mira', 'terms' => ['Amara Okafor', 'Senior Welder']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.text', 'Shortlist Amara for the welder role')
            ->assertJsonPath('data.seconds_left_today', 57);

        $this->assertSame('mploynow.dictation.mira', $this->ai->transcribed[0]['task']);
        $this->assertSame('Amara Okafor, Senior Welder', $this->ai->transcribed[0]['prompt']);
    }

    public function test_the_minutes_run_out_quietly_and_the_switch_hides_the_route(): void
    {
        $this->ai->queueTranscription('one');
        $this->post('/ai/dictation', ['audio' => $this->wav(50), 'surface' => 'mira'])->assertOk();

        // 50 s used of 60: another 20 s is refused before any provider is paid.
        $this->post('/ai/dictation', ['audio' => $this->wav(20), 'surface' => 'mira'])
            ->assertStatus(429)->assertJsonPath('success', false);
        $this->assertCount(1, $this->ai->transcribed);

        $this->app->instance(DictationSettings::class, new class implements DictationSettings
        {
            public function enabled(): bool { return false; }

            public function dailyMinutes(): int { return 60; }

            public function surfaces(): array { return []; }
        });
        $this->post('/ai/dictation', ['audio' => $this->wav(1), 'surface' => 'mira'])->assertStatus(404);
    }

    public function test_no_provider_is_a_503_that_says_to_type_and_bad_input_is_a_422(): void
    {
        $this->ai->queueTranscriptionFailure(new AllProvidersFailedException('none', []));
        $this->post('/ai/dictation', ['audio' => $this->wav(1), 'surface' => 'mira'])
            ->assertStatus(503)->assertJsonPath('success', false);

        $this->postJson('/ai/dictation', ['surface' => 'Not A Surface'])->assertStatus(422);
    }

    public function test_a_product_that_names_its_surfaces_refuses_any_other(): void
    {
        $this->app['config']->set('ai-client.dictation.surfaces', ['mira', 'messaging']);
        $this->ai->queueTranscription('ok');

        $this->post('/ai/dictation', ['audio' => $this->wav(1), 'surface' => 'somewhere'])->assertStatus(302);
        $this->postJson('/ai/dictation', ['audio' => $this->wav(1), 'surface' => 'somewhere'])->assertStatus(422);
        $this->post('/ai/dictation', ['audio' => $this->wav(1), 'surface' => 'mira'])->assertOk();
    }

    public function test_terms_never_carry_the_words_already_written_and_a_wav_is_timed_exactly(): void
    {
        $this->assertNull(DictationTranscriber::prompt([]));
        $this->assertSame('Ada, Bo', DictationTranscriber::prompt(['Ada', ' Bo ', 'Ada', str_repeat('x', 61)]));
        $this->assertSame(3, DictationTranscriber::seconds((string) $this->wav(3)->getContent()));
    }
}
