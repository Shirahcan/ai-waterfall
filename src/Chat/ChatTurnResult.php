<?php

namespace Shirahcan\AiClient\Chat;

/**
 * A finished chat turn, read off the `final` event.
 *
 * `cards` are the proposals and tables the product's own tools produced, in the
 * order they were made, as `{type, payload}`. The product renders them; nothing
 * in a card has been executed.
 */
final class ChatTurnResult
{
    /**
     * @param  list<array{type: string, payload: array<string, mixed>}>  $cards
     * @param  list<array{name: string, ok: bool}>  $toolCalls
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public readonly string $text,
        public readonly array $cards = [],
        public readonly array $toolCalls = [],
        public readonly array $usage = [],
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $route = null,
        public readonly bool $degraded = false,
        public readonly ?string $streamId = null,
    ) {}

    /** @param  array<string, mixed>  $final */
    public static function fromFinal(array $final, ?string $streamId = null): self
    {
        $cards = [];
        foreach ((array) ($final['cards'] ?? []) as $card) {
            if (is_array($card) && is_string($card['type'] ?? null)) {
                $cards[] = ['type' => $card['type'], 'payload' => (array) ($card['payload'] ?? [])];
            }
        }

        return new self(
            text: (string) ($final['text'] ?? ''),
            cards: $cards,
            toolCalls: array_values(array_filter((array) ($final['tool_calls'] ?? []), 'is_array')),
            usage: array_map('intval', (array) ($final['usage'] ?? [])),
            provider: isset($final['provider']) ? (string) $final['provider'] : null,
            model: isset($final['model']) ? (string) $final['model'] : null,
            route: isset($final['route']) ? (string) $final['route'] : null,
            degraded: (bool) ($final['degraded'] ?? false),
            streamId: $streamId,
        );
    }
}
