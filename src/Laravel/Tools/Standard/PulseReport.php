<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

/**
 * What a {@see PulseProvider} returns: the lines, plus anything the answer must say about them.
 *
 * `candidates` is set instead of lines when the person named matched several people (or none), so the
 * model asks which one rather than guessing.
 */
final class PulseReport
{
    /**
     * @param  list<PulseLine>  $lines
     * @param  list<string>  $notes
     * @param  list<array<string, mixed>>|null  $candidates
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $notes = [],
        public readonly ?string $subject = null,
        public readonly ?array $candidates = null,
    ) {}
}
