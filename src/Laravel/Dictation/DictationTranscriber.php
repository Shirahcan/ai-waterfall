<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Shirahcan\AiClient\AiClient;

/**
 * One spoken phrase in, its words out, for every product's every surface (moved out of Portify 2026-11-07).
 *
 * ⚠ THE AUDIO IS NEVER STORED. It is read from the upload's temp file and handed to ai-service; PHP deletes
 * the temp file when the request ends. Only the text comes back.
 *
 * ⚠ THE DAILY ALLOWANCE IS PER PERSON, from {@see DictationSettings} (a product binds a DB-backed one so an
 * admin changes the minutes without a deploy). One person dictating all day must not eat the PHP workers or
 * the paid rung.
 *
 * WHICH AI JUSTIFICATION: a user acted (they pressed the microphone and spoke).
 */
class DictationTranscriber
{
    public const DEFAULT_DAILY_MINUTES = 60;

    public function __construct(private AiClient $ai, private DictationSettings $settings) {}

    /**
     * The usage ledger's task: `<product>.dictation.<surface>`, so AI usage shows where the minutes go.
     * The surface is the product's own word for where the person was typing ("porter", "mira", "messaging").
     */
    public static function task(string $surface): string
    {
        // `portify.dictation.porter` as before the move: the app's own name when nothing more specific is set.
        $product = (string) (config('ai-client.dictation.product') ?: config('ai-client.tools.product')
            ?: \Illuminate\Support\Str::slug((string) config('app.name', 'product'), '_') ?: 'product');
        $surface = preg_match('/^[a-z][a-z0-9_]{0,23}$/', $surface) === 1 ? $surface : 'form';

        return mb_substr($product.'.dictation.'.$surface, 0, 64);
    }

    public function dailySeconds(): int
    {
        return $this->settings->dailyMinutes() * 60;
    }

    public function secondsUsedToday(Authenticatable $user): int
    {
        return (int) Cache::get($this->counterKey($user), 0);
    }

    public function secondsLeftToday(Authenticatable $user): int
    {
        return max(0, $this->dailySeconds() - $this->secondsUsedToday($user));
    }

    /**
     * @param  list<string>  $terms  names and words the person may say (a client's name, a role title)
     * @return array{text: string, seconds_left_today: int}
     *
     * @throws DictationAllowanceUsed when today's minutes are gone
     * @throws \Shirahcan\AiClient\Exceptions\AllProvidersFailedException|\Shirahcan\AiClient\Exceptions\AiUnavailableException
     */
    public function transcribe(Authenticatable $user, UploadedFile $audio, string $surface, ?string $language = null, array $terms = []): array
    {
        $bytes = (string) file_get_contents($audio->getRealPath());
        $seconds = self::seconds($bytes);

        if ($this->secondsUsedToday($user) + $seconds > $this->dailySeconds()) {
            throw new DictationAllowanceUsed($this->settings->dailyMinutes());
        }

        $result = $this->ai->transcribe(
            $bytes,
            $audio->getClientOriginalName() ?: 'phrase.wav',
            self::task($surface),
            $language,
            self::prompt($terms),
        );

        $this->spend($user, $seconds);

        return [
            'text' => trim((string) ($result['text'] ?? '')),
            'seconds_left_today' => $this->secondsLeftToday($user),
        ];
    }

    /**
     * "Name, Term, ..." - spelling help for the provider.
     *
     * ⚠ NAMES AND TERMS ONLY, NEVER THE WORDS ALREADY WRITTEN. Measured 2026-11-05: handed the previous
     * phrase as context, Gemini wrote it out again at the start of the next one, so a steady talker's
     * message doubled itself. Whisper is known for the same echo on short audio.
     *
     * @param  array<int, mixed>  $terms
     */
    public static function prompt(array $terms): ?string
    {
        $clean = collect($terms)
            ->filter(fn ($t) => is_string($t))
            ->map(fn (string $t) => trim((string) preg_replace('/\s+/', ' ', $t)))
            ->filter(fn (string $t) => $t !== '' && mb_strlen($t) <= 60)
            ->unique()
            ->take(40)
            ->implode(', ');

        return $clean === '' ? null : mb_substr($clean, 0, 1000);
    }

    /**
     * Seconds of speech in the upload: exact for the PCM WAV the shared recorder sends, otherwise estimated
     * from the size at a generous 4 KB/s so an unknown format is never counted as free.
     */
    public static function seconds(string $bytes): int
    {
        if (strlen($bytes) >= 44 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
            $byteRate = unpack('V', substr($bytes, 28, 4))[1] ?? 0;
            if ($byteRate > 0) {
                return (int) ceil(max(0, strlen($bytes) - 44) / $byteRate);
            }
        }

        return (int) ceil(strlen($bytes) / 4000);
    }

    private function spend(Authenticatable $user, int $seconds): void
    {
        $key = $this->counterKey($user);
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key, $seconds);
    }

    /**
     * ⚠ THE SAME KEY PORTIFY USED, so moving Porter onto the kit keeps everybody's minutes for today.
     */
    private function counterKey(Authenticatable $user): string
    {
        return 'dictation:seconds:'.$user->getAuthIdentifier().':'.now()->format('Y-m-d');
    }
}
