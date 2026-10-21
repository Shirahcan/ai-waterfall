<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Conversation;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * The conversations this REQUEST has read, so a list and then one of its rows costs one call.
 *
 * ⚠ REQUEST-SCOPED, AND NEVER A CACHE THAT OUTLIVES THE REQUEST. The service is the authority;
 * a value kept between requests would be a second, ageing copy of a fact somebody else can
 * change, and the whole point of moving the transcript was to stop having two.
 *
 * ⚠ A SERVICE THAT CANNOT ANSWER IS AN ERROR, REPORTED ONCE, AND NEVER A STALE FALLBACK. This
 * is the same decision `HeldBookings` made in calendar-client, for the same reason: hydrating a
 * list to empty titles and zero turns would not be a degraded view, it would be a LIE about the
 * data - and a person reading "0 messages" on a thread they used yesterday has been told
 * something false. A product that wants to survive the outage catches this at the page and says
 * the history is unavailable.
 *
 * ⚠ A MISSING ID IS NOT AN ERROR. `null` means the service does not hold that thread: retention
 * erased it, or somebody asked for it to go. That is a state to render, and it is why
 * `HeldConversationSubject::hydrate()` is handed a nullable.
 */
final class HeldConversations
{
    /** @var array<string, Conversation|null> conversation id => conversation (null = not held) */
    private array $conversations = [];

    private bool $reported = false;

    /**
     * Fetch every id not already known, in ONE call.
     *
     * @param  list<string>  $ids
     * @return array<string, Conversation|null>
     */
    public function load(array $ids): array
    {
        $missing = array_values(array_unique(array_filter(
            $ids,
            fn ($id) => (string) $id !== '' && ! array_key_exists((string) $id, $this->conversations),
        )));

        if ($missing !== []) {
            try {
                $found = app(AiClient::class)->conversations()->byIds($missing);
            } catch (AiUnavailableException $e) {
                /*
                 * Reported once per request: a list of twenty rows that all fail would
                 * otherwise be twenty identical reports, and the twentieth tells nobody
                 * anything the first did not.
                 */
                if (! $this->reported) {
                    $this->reported = true;
                    report($e);
                }

                throw $e;
            }

            foreach ($missing as $id) {
                $this->conversations[(string) $id] = $found[(string) $id] ?? null;
            }
        }

        return array_intersect_key($this->conversations, array_flip(array_map('strval', $ids)));
    }

    public function get(string $id): ?Conversation
    {
        return $this->load([$id])[$id] ?? null;
    }

    /** A conversation the service just returned (a write's answer, a list row). */
    public function put(Conversation $conversation): void
    {
        $this->conversations[$conversation->id] = $conversation;
    }

    /** After a change whose answer was not kept: the next read asks again. */
    public function forget(?string $id): void
    {
        if ($id !== null && $id !== '') {
            unset($this->conversations[$id]);
        }
    }

    /** Whether this request has already asked about an id (held or not). */
    public function knows(string $id): bool
    {
        return array_key_exists($id, $this->conversations);
    }
}
