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
    /** The card ai-service's engine puts the answer's suggested next steps in (its `FollowUps`, 2026-11-03). */
    public const FOLLOW_UPS_CARD = 'follow_ups';

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
        /**
         * Tools still working when the turn ended, as `[{ticket, label}]`. The product's
         * job resumes each with {@see \Shirahcan\AiClient\AiClient::continueTurn()}.
         *
         * @var list<array{ticket: string, label: string}>
         */
        public readonly array $deferred = [],
    ) {}

    /**
     * The next steps this answer suggests, each a message the person can send as is (1.61). The shared panel
     * (ai-ui) draws them as buttons under the latest answer when the product's message carries them as
     * `meta.follow_ups`; {@see self::followUpsMeta()} puts them there.
     *
     * ⚠ ONE READER FOR EVERY PRODUCT. ai-service's engine has written follow-ups for every assistant since
     * 2026-11-03, but each product had to fish them out of `cards` itself: Portify did, Mira's composer never
     * did, so Mira showed none (owner 2026-11-09: "supposed to be shared infra ... available to every assistant").
     *
     * @return list<string>
     */
    public function followUps(): array
    {
        foreach ($this->cards as $card) {
            if ($card['type'] === self::FOLLOW_UPS_CARD) {
                return array_values(array_unique(array_filter(
                    array_map(static fn ($item): string => is_scalar($item) ? trim((string) $item) : '', (array) ($card['payload']['items'] ?? [])),
                    static fn (string $item): bool => $item !== '',
                )));
            }
        }

        return [];
    }

    /**
     * A message's `meta` with this answer's follow-ups added as `follow_ups`, the key the shared panel reads.
     * Unchanged (null stays null) when the answer suggests none.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null
     */
    public function followUpsMeta(?array $meta = null): ?array
    {
        $followUps = $this->followUps();

        return $followUps === [] ? $meta : [...($meta ?? []), 'follow_ups' => $followUps];
    }

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
            deferred: array_values(array_map(
                static fn (array $d) => ['ticket' => (string) $d['ticket'], 'label' => (string) ($d['label'] ?? '')],
                array_filter((array) ($final['deferred'] ?? []), static fn ($d) => is_array($d) && is_string($d['ticket'] ?? null)),
            )),
        );
    }
}
