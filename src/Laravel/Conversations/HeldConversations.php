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
 * ⚠ A SERVICE THAT CANNOT ANSWER IS RECORDED, REPORTED ONCE, AND NEVER INVENTED INTO AN ANSWER.
 * Hydrating a list to empty titles and zero turns would not be a degraded view, it would be a
 * LIE about the data - a person reading "0 messages" on a thread they used yesterday has been
 * told something false. So this loader does not do that.
 *
 * ⚠ BUT IT NO LONGER THROWS, AND THE REASON IS A BLAST RADIUS THAT WAS MEASURED RATHER THAN
 * ARGUED. It used to rethrow, copying `HeldBookings`. Hydration runs inside `newCollection()`,
 * so the throw came out of EVERY Eloquent query that returned a held model - including the ones
 * that never touch a held attribute. Measured in Portify: with ai-service unreachable,
 * `AssistantConversation::find($id)` threw, which meant the authorisation check that reads
 * `user_id` threw, the guest prune threw, and - worst of the three - the ERASURE SWEEP threw, so
 * a person asking to be forgotten would have had nothing erased in either store. An outage in
 * the thing that holds a title must not break the thing that holds identity.
 *
 * So: the failure is recorded, `isUnavailable()` says so, and the trait leaves each row's own
 * attributes alone rather than overwriting them with nothing. Nothing is invented and nothing is
 * claimed; a product that wants to tell the person renders from `aiConversationFactsAreCurrent()`.
 *
 * ⚠ AND A WRITE STILL THROWS. `sendHeldChangesToAiService()` is untouched: a rename the service
 * never heard about must fail loudly, because the alternative is the silently-lost write that
 * `Meeting` already shipped over its booking.
 *
 * ⚠ `HeldBookings` IN calendar-client STILL RETHROWS, and has the same shape. Not changed from
 * here: it is another package, live in production for weeks, and its own verification.
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
     * Whether the last attempt to ask the service failed.
     *
     * ⚠ NOT STICKY ACROSS A RECOVERY. It is set on a failure and cleared on the next successful
     * read, because a request that loads a list, fails, and then loads it again after the
     * service comes back should get real values the second time - and because a flag that stayed
     * true for the rest of the request would make a product render "unavailable" over data it
     * actually has.
     */
    private bool $unavailable = false;

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

                /*
                 * ⚠ NOTHING IS RECORDED FOR THESE IDS, which is the difference between "not
                 * held" and "not asked". Writing nulls here would make the next read in the
                 * same request answer "erased" from memory, with no further call and no way
                 * back - so one unreachable moment would read as a permanent erasure for the
                 * rest of the request.
                 */
                $this->unavailable = true;

                return [];
            }

            $this->unavailable = false;

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

    /**
     * Whether the service could not be asked.
     *
     * ⚠ THIS IS A DIFFERENT QUESTION FROM "IS THIS THREAD STILL HELD", and conflating them is
     * the defect. `get()` returning null means the service ANSWERED and does not hold that
     * thread - retention erased it, or somebody asked for it to go, which is a state to render.
     * This means the service did not answer at all, and the honest rendering is "we cannot show
     * this right now", never "this has been erased".
     */
    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }

    /** Whether this request has already asked about an id (held or not). */
    public function knows(string $id): bool
    {
        return array_key_exists($id, $this->conversations);
    }
}
