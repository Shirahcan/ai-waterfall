<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * What one assistant turn produced, and what it did on the way.
 *
 * ⚠ THE TRAIL IS NOT DEBUG OUTPUT. A product has to be able to show a user
 * "I looked at your case and your last payment" and an operator "this turn ran
 * four steps and was refused one tool", and both of those are the same record.
 * An assistant that cannot say what it did is one nobody can trust with an
 * action.
 */
final class AssistantAnswer
{
    /**
     * @param  array<int, array{name: string, arguments: array<string, mixed>, outcome: string, error?: string}>  $trail
     *   outcome is one of: ran, refused, unknown_tool, failed
     * @param  array<int, array<string, mixed>>  $messages  the conversation as the
     *   model finally saw it, so a caller can persist a transcript that matches
     *   what was actually reasoned over rather than a reconstruction of it.
     */
    public function __construct(
        public readonly string $text,
        public readonly array $trail = [],
        public readonly int $steps = 0,
        /**
         * ⚠ TRUE WHEN THE LOOP RAN OUT OF STEPS OR TIME RATHER THAN FINISHING.
         * The text is then the model's last words, which may be mid-thought, and
         * a caller that treats it as a finished answer is quoting a sentence the
         * assistant had not decided to say. Surface it or retry; do not ignore
         * it.
         */
        public readonly bool $exhausted = false,
        public readonly array $messages = [],
        /** Which provider answered the final step, for the usage trail. */
        public readonly ?string $provider = null,
    ) {}

    /** @return array<int, string> names of tools that actually ran */
    public function toolsUsed(): array
    {
        return array_values(array_unique(array_map(
            fn (array $t) => $t['name'],
            array_filter($this->trail, fn (array $t) => $t['outcome'] === 'ran'),
        )));
    }

    /**
     * Tools the viewer was not allowed to use.
     *
     * ⚠ WORTH SURFACING TO AN OPERATOR, NOT TO THE USER. A refusal here usually
     * means the model reached for a capability this audience does not have,
     * which is either correct (and the answer explains the limit) or a sign the
     * persona is describing something the viewer cannot do.
     *
     * @return array<int, string>
     */
    public function refusedTools(): array
    {
        return array_values(array_unique(array_map(
            fn (array $t) => $t['name'],
            array_filter($this->trail, fn (array $t) => $t['outcome'] === 'refused'),
        )));
    }

    public function usedAnyTool(): bool
    {
        return $this->toolsUsed() !== [];
    }
}
