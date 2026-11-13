<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Closure;

/**
 * What a console run did, step by step (1.66.0, moved from Portify's BuildLog for the shared console, C1a):
 * the lines the console draws as the assistant works ("Reading the page", "Opening vanhack.com", "Writing
 * the FAQ", "Fixing a detail the check found"), and its thinking as it streams.
 *
 * The product supplies two things and nothing else:
 *   - WHERE progress is kept while the run is live (`$save`, e.g. its background run's `result`), so a
 *     poll from the console reads it;
 *   - HOW its document's parts are named (`$phrase`, e.g. a PML `box` is "a section"). Without one, a part
 *     is "the <name>" with underscores read as spaces.
 *
 * ⚠ STEPS ARE ADD-ONLY: a step enters once and never changes, so the console appends what it has not seen
 * and never redraws a line. The thinking still being written is the one thing replaced on every look
 * (`thinking`); it becomes a `think` step once the run moves on.
 *
 * ⚠ EVERY PROGRESS WRITE GOES THROUGH HERE. Progress overwritten whole on every write would drop the steps
 * each time; `progress()` carries them with whatever the run publishes about the document so far.
 */
class RunLog
{
    /** Steps kept per run (the console's session caps at the same). */
    public const MAX = 60;

    /** Thinking is kept whole up to this cap and folded by the console, never truncated mid-word. */
    public const THINKING_MAX = 20000;

    /** A thinking stream arrives a few words at a time: saved at most this often (seconds). */
    private const SAVE_EVERY = 0.35;

    /** @var Closure(array<string, mixed>): void */
    private readonly Closure $save;

    /** @var (Closure(string): string)|null */
    private readonly ?Closure $phrase;

    private float $savedAt = 0.0;

    private ?float $thinkingSince = null;

    /** @var list<array{seq: int, kind: string, text: string, detail: ?string, ms: ?int}> */
    private array $steps = [];

    private ?float $openedAt = null;

    private string $thinking = '';

    /** @var array<string, mixed> */
    private array $last = [];

    /** The part being written, and how many were finished when it started: a new line for each part. */
    private ?string $writing = null;

    /**
     * @param  callable(array{steps: list<array<string, mixed>>, thinking: ?string}&array<string, mixed>): void  $save
     * @param  (callable(string): string)|null  $phrase
     */
    public function __construct(callable $save, ?callable $phrase = null)
    {
        $this->save = Closure::fromCallable($save);
        $this->phrase = $phrase === null ? null : Closure::fromCallable($phrase);
    }

    public function step(string $kind, string $text, ?string $detail = null): void
    {
        $this->endThinking();
        $this->addStep($kind, $text, $detail);
        $this->save();
    }

    /** The run started writing a part: one "Writing the FAQ" line per part, never repeated. */
    public function writing(?string $name, int $finished = 0): void
    {
        $key = $name === null ? null : $name.'#'.$finished;

        if ($key === null || $key === $this->writing) {
            return;
        }

        $this->writing = $key;
        $this->step('write', 'Writing '.$this->phrase($name));
    }

    /** The thinking so far, replaced on every look until the run moves on. */
    public function thinking(string $soFar): void
    {
        // The step before ends when the thinking starts, so its time is its own.
        if ($this->thinking === '' && trim($soFar) !== '') {
            $this->close();
            $this->thinkingSince = microtime(true);
        }

        $this->thinking = $soFar;

        if (microtime(true) - $this->savedAt >= self::SAVE_EVERY) {
            $this->save();
        }
    }

    /**
     * What the run publishes about the document so far (its own keys, kept beside the steps). A
     * `writing` key names the part being written, and `elements` the parts finished.
     *
     * @param  array<string, mixed>  $progress
     */
    public function progress(array $progress): void
    {
        $this->last = $progress;
        $name = $progress['writing'] ?? null;
        $finished = is_array($progress['elements'] ?? null) ? count($progress['elements']) : 0;

        if (is_string($name) && $name.'#'.$finished !== $this->writing) {
            $this->writing($name, $finished);

            return;
        }

        $this->save();
    }

    /** @return list<array{kind: string, text: string, detail: ?string, ms: ?int}> every step, the last one closed */
    public function finish(): array
    {
        $this->endThinking();
        $this->close();
        $this->save();

        return array_map(fn (array $s) => ['kind' => $s['kind'], 'text' => $s['text'], 'detail' => $s['detail'], 'ms' => $s['ms']], $this->steps);
    }

    /** How a person names the part being written: the product's phrase, else "the <name>". */
    public function phrase(string $name): string
    {
        return $this->phrase !== null ? ($this->phrase)($name) : 'the '.str_replace('_', ' ', $name);
    }

    private function endThinking(): void
    {
        $text = trim($this->thinking);
        $this->thinking = '';

        if ($text !== '') {
            $since = $this->thinkingSince;
            $this->addStep('think', 'Thinking', self::tail($text));

            // Timed from its first word, not from the step it followed.
            if ($since !== null) {
                $this->openedAt = $since;
            }
        }

        $this->thinkingSince = null;
    }

    /** The thinking whole up to the cap; past it, its latest part, cut at a word. */
    private static function tail(string $text): string
    {
        if (mb_strlen($text) <= self::THINKING_MAX) {
            return $text;
        }

        $cut = mb_substr($text, -self::THINKING_MAX + 1);
        $space = mb_strpos($cut, ' ');

        return '…'.ltrim($space === false ? $cut : mb_substr($cut, $space));
    }

    private function addStep(string $kind, string $text, ?string $detail): void
    {
        $this->close();

        if (count($this->steps) >= self::MAX) {
            return;
        }

        $this->steps[] = ['seq' => count($this->steps), 'kind' => $kind, 'text' => $text, 'detail' => $detail, 'ms' => null];
        $this->openedAt = microtime(true);
    }

    /** The open step's time, measured once. */
    private function close(): void
    {
        $last = count($this->steps) - 1;

        if ($last >= 0 && $this->steps[$last]['ms'] === null && $this->openedAt !== null) {
            $this->steps[$last]['ms'] = (int) round((microtime(true) - $this->openedAt) * 1000);
        }
    }

    private function save(): void
    {
        $this->savedAt = microtime(true);

        ($this->save)([
            ...$this->last,
            'steps' => $this->steps,
            'thinking' => trim($this->thinking) === '' ? null : $this->thinking,
        ]);
    }
}
