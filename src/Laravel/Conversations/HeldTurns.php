<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\ConversationTurn;
use Shirahcan\AiClient\CorpusFilter;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * The turns a request has read, by id (P21d).
 *
 * ⚠ SCOPED, LIKE `HeldConversations`, and for the same reason: a copy that outlived the request
 * would be a second store of what was said. One page of a product's message rows costs one read.
 *
 * ⚠ "UNAVAILABLE" IS NOT "ABSENT". When the service cannot be reached the loader says so, and the
 * caller decides how that looks; it never pretends the turns do not exist.
 */
final class HeldTurns
{
    /** The service's own cap on ids per corpus read. */
    private const CHUNK = 200;

    /** @var array<string, ConversationTurn|null> */
    private array $turns = [];

    private bool $unavailable = false;

    private bool $reported = false;

    /**
     * @param  list<string>  $ids
     * @return array<string, ConversationTurn|null>
     */
    public function load(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($id) => $id !== '')));
        $missing = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->turns)));

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            try {
                $page = app(AiClient::class)->conversations()->turns(new CorpusFilter(turnIds: $chunk), count($chunk));
            } catch (AiUnavailableException $e) {
                if (! $this->reported) {
                    $this->reported = true;
                    report($e);
                }
                $this->unavailable = true;

                return [];
            }

            $this->unavailable = false;
            $found = [];
            foreach ($page->turns as $turn) {
                $found[$turn->id] = $turn;
            }
            foreach ($chunk as $id) {
                $this->turns[$id] = $found[$id] ?? null;
            }
        }

        return array_intersect_key($this->turns, array_flip($ids));
    }

    public function forget(?string $id): void
    {
        if ($id !== null && $id !== '') {
            unset($this->turns[$id]);
        }
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }
}
