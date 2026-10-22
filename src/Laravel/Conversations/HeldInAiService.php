<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Conversation;

/**
 * For a model whose conversation facts ai-service holds. Use it on the model and name the
 * subject:
 *
 *     use HeldInAiService;
 *     protected static function aiConversationSubject(): string { return PorterThreadSubject::class; }
 *
 * - **Loading**: every collection the model builds is hydrated from its conversations in ONE
 *   call, so the attributes ARE the service's values and `getOriginal()`, `isDirty()` and
 *   `wasChanged()` still mean what they always meant.
 * - **Saving**: a held attribute the product may change is sent to the service FIRST and a
 *   refusal means nothing changed. Held attributes are never written to the product's table.
 * - A row **without** a conversation id (a fixture, or a record from before the cutover) keeps
 *   its own columns: nothing else holds them.
 *
 * ⚠ ONLY THE SUMMARY SCALARS ARE HELD, NEVER THE TURNS, and that is the one way this differs
 * from `HeldInCalendarService`. A conversation's held facts are a LIST that grows, so hydrating
 * turns per row would be N requests of M turns. Read turns with
 * `$ai->conversations()->read($id)` for the ONE thread on screen.
 *
 * ⚠ A WRITE TO A DERIVED HELD ATTRIBUTE THROWS, IT IS NOT DROPPED. `Meeting` over its booking
 * shipped the other behaviour: the product wrote to a column nothing read, no error was raised
 * anywhere, and the change simply vanished. A loud failure at the save is cheaper than a
 * silently lost rename found by a user.
 *
 * ⚠ `cursor()` and raw `DB::table()` reads BYPASS THIS, because no collection is built. Read
 * such a model through Eloquent collections only - and when you sweep a product for raw reads,
 * that is what you are looking for.
 */
trait HeldInAiService
{
    /** @return class-string<HeldConversationSubject> */
    abstract protected static function aiConversationSubject(): string;

    public static function aiHeldSubject(): HeldConversationSubject
    {
        return app(static::aiConversationSubject());
    }

    /**
     * ⚠ THE WRITE-THROUGH, AND IT RUNS BEFORE THE ROW IS WRITTEN. The service is the authority,
     * so it decides first; if it refuses, the exception propagates and the product's row is
     * never touched.
     */
    public static function bootHeldInAiService(): void
    {
        static::saving(function (Model $model): void {
            if ($model instanceof self) {
                $model->sendHeldChangesToAiService();
            }
        });

        /*
         * ⚠ RE-HYDRATE AFTER AN INSERT, OR THE MODEL IN HAND LIES. A row created from an array
         * carries whatever held values that array happened to contain - a copied payload, a
         * fixture - and those are stripped from the INSERT but stay in memory. Without this the
         * object the caller is holding says `title = 'whatever I passed'` while the service says
         * something else, and the next read of the same object disagrees with the next read from
         * the database.
         */
        static::created(function (Model $model): void {
            if ($model instanceof self && $model->isHeldInAiService()) {
                $model->rereadHeldAttributesAfterInsert();
            }
        });
    }

    public function newCollection(array $models = [])
    {
        if ($models !== []) {
            static::hydrateHeldConversations($models);
        }

        return parent::newCollection($models);
    }

    /**
     * Fill each record's held attributes from its conversation (ONE call for all of them). Also
     * the way to re-read records after a conversation changed outside this request.
     *
     * @param  array<int, Model>  $models
     */
    public static function hydrateHeldConversations(array $models): void
    {
        $subject = static::aiHeldSubject();
        $column = $subject->column();

        $ids = [];

        foreach ($models as $model) {
            if ($model instanceof self && $model->exists) {
                $id = (string) ($model->getAttributes()[$column] ?? '');

                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        if ($ids === []) {
            return;
        }

        $loader = app(HeldConversations::class);
        $conversations = $loader->load(array_keys($ids));

        /*
         * ⚠ AN UNREACHABLE SERVICE LEAVES EVERY ROW ALONE, rather than hydrating the whole list
         * to "erased". The product's own columns are whatever it last wrote - stale during a
         * soak, empty after a contraction - and either is a more honest answer than telling
         * somebody their conversation is gone because a loopback call timed out. Ask
         * `aiConversationFactsAreCurrent()` to say so on the page.
         */
        if ($loader->isUnavailable()) {
            return;
        }

        foreach ($models as $model) {
            if (! $model instanceof self || ! $model->exists) {
                continue;
            }

            $id = (string) ($model->getAttributes()[$column] ?? '');

            /*
             * ⚠ A DIRTY MODEL IS LEFT ALONE. Overwriting attributes somebody has already
             * changed in this request would discard their edit and, worse, make it look as
             * though they never made one - `isDirty()` would go quiet.
             */
            if ($id === '' || $model->isDirty()) {
                continue;
            }

            $model->setRawAttributes(
                array_merge($model->getAttributes(), $subject->hydrate($conversations[$id] ?? null, $model)),
                true,
            );
        }
    }

    /**
     * Replace the held attributes with the service's values, right after this row was inserted.
     *
     * ⚠ IT CANNOT GO THROUGH `hydrateHeldConversations()`, AND THE REASON IS AN ELOQUENT DETAIL
     * WORTH WRITING DOWN. That method deliberately skips a DIRTY model, so a collection load
     * never discards an edit somebody has in flight - but on an insert, Eloquent fires `created`
     * BEFORE `finishSave()` calls `syncOriginal()`, so the model is still dirty and the skip
     * would fire on the one path that most needs the re-read.
     *
     * ⚠ AND IT SYNCS NOTHING. Passing `$sync = true` here would mark the model clean a moment
     * before `finishSave()` checks `isDirty()` to decide whether to `touchOwners()`, so a parent
     * model that should have been touched silently would not be.
     */
    protected function rereadHeldAttributesAfterInsert(): void
    {
        $subject = static::aiHeldSubject();
        $id = (string) ($this->getAttributes()[$subject->column()] ?? '');

        if ($id === '') {
            return;
        }

        $loader = app(HeldConversations::class);
        $conversation = $loader->get($id);

        // Unreachable: keep what this instance already holds rather than claiming an erasure.
        if ($loader->isUnavailable()) {
            return;
        }

        $this->setRawAttributes(array_merge($this->getAttributes(), $subject->hydrate($conversation, $this)), false);
    }

    /** True once the record is held by a conversation (its held columns are no longer its own). */
    public function isHeldInAiService(): bool
    {
        return (string) ($this->getAttributes()[static::aiHeldSubject()->column()] ?? '') !== '';
    }

    /**
     * Whether the service STILL holds this record's thread.
     *
     * ⚠ THIS IS A DIFFERENT QUESTION FROM `isHeldInAiService()`, AND CONFUSING THEM IS THE
     * DEFECT. The first asks whether the row points at a thread; this asks whether that thread
     * is still there. Retention erases threads on a schedule and an erasure erases on request,
     * so a row can point at nothing - and a product that lists it anyway shows somebody a
     * conversation with no messages in it. One authority for the predicate, read from the
     * request-scoped loader, so asking it per row in a list costs nothing extra.
     */
    public function aiConversationStillHeld(): bool
    {
        $id = (string) ($this->getAttributes()[static::aiHeldSubject()->column()] ?? '');

        if ($id === '') {
            return false;
        }

        $loader = app(HeldConversations::class);
        $conversation = $loader->get($id);

        /*
         * ⚠ AN OUTAGE IS NOT AN ERASURE, and answering false here would make a product hide
         * every conversation it has whenever ai-service hiccupped - and, far worse on a surface
         * that offers to clean up, treat "unreachable" as licence to delete the row. When the
         * service could not be asked, the honest answer to "is this still held" is yes: nothing
         * has told us otherwise.
         */
        if ($loader->isUnavailable()) {
            return true;
        }

        return $conversation !== null;
    }

    /**
     * Whether the held facts on this instance are the service's current answer.
     *
     * ⚠ THIS IS WHAT A PAGE SHOULD RENDER FROM DURING AN OUTAGE. False means the attributes are
     * the product's own last-written values rather than the authority's, so a surface can say
     * "we cannot show the history right now" instead of presenting a stale title as current or
     * an absent one as erased.
     */
    public function aiConversationFactsAreCurrent(): bool
    {
        return ! $this->isHeldInAiService() || ! app(HeldConversations::class)->isUnavailable();
    }

    /** The conversation itself, from the request's one read, or null if it is not held. */
    public function aiConversation(): ?Conversation
    {
        $id = (string) ($this->getAttributes()[static::aiHeldSubject()->column()] ?? '');

        return $id === '' ? null : app(HeldConversations::class)->get($id);
    }

    /**
     * Send the held attributes the product has changed to the service, and keep the answer.
     *
     * ⚠ A DERIVED HELD ATTRIBUTE BEING DIRTY IS A BUG AND IS RAISED AS ONE. `turn_count` and
     * `last_turn_at` are counted from the turns by the service; a product setting them is
     * stating a second, disagreeing version of a fact it does not own. Dropping it quietly is
     * what `Meeting` did, and the rename went nowhere.
     */
    protected function sendHeldChangesToAiService(): void
    {
        if (! $this->isHeldInAiService()) {
            return;
        }

        /*
         * ⚠ AN INSERT IS NOT A CHANGE TO THE THREAD, and treating it as one was wrong twice
         * over. On a new model EVERY attribute is dirty, so a row created from an array with a
         * `turn_count` in it would raise as though somebody had deliberately overwritten a
         * derived fact - and a `title` in that array would be PATCHED over the title the
         * service already holds, letting a copied payload rename a thread. The attributes are
         * stripped from the INSERT by `getAttributesForInsert()` and then re-read from the
         * service by the `created` listener, so nothing is lost and nothing is invented.
         */
        if (! $this->exists) {
            return;
        }

        $subject = static::aiHeldSubject();
        $writable = $subject->writeThrough();
        $dirty = $this->getDirty();

        $derived = array_diff(
            array_intersect(array_keys($dirty), $subject->heldAttributes()),
            array_keys($writable),
        );

        if ($derived !== []) {
            throw new RuntimeException(sprintf(
                '%s: [%s] is held by ai-service and derived from the turns, so it cannot be written here. '
                .'Append a turn with $ai->conversations()->append() and let the service count.',
                static::class,
                implode(', ', $derived),
            ));
        }

        $changes = [];

        foreach ($writable as $attribute => $field) {
            if (array_key_exists($attribute, $dirty)) {
                $changes[$field] = $dirty[$attribute];
            }
        }

        if ($changes === []) {
            return;
        }

        $id = (string) $this->getAttributes()[$subject->column()];

        // The service decides first. A refusal throws, and this row is never written.
        $conversation = app(AiClient::class)->conversations()->update($id, $changes);

        // Keep the answer, so a read later in this request does not ask again - and does not
        // get the pre-change value from a loader that had already cached it.
        app(HeldConversations::class)->put($conversation);
    }

    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();

        if (! $this->isHeldInAiService()) {
            return $dirty;
        }

        $own = array_diff_key($dirty, array_flip(static::aiHeldSubject()->heldAttributes()));

        /*
         * Only held facts changed: still a write, so the model's `updated` listeners hear about
         * it and `wasChanged()` answers. Without this a rename would fire no events at all, and
         * every observer a product hangs off this model would go quiet for exactly the change
         * the user made.
         */
        if ($own === [] && $dirty !== []) {
            return [$this->getKeyName() => $this->getKey()];
        }

        return $own;
    }

    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();

        return $this->isHeldInAiService()
            ? array_diff_key($attributes, array_flip(static::aiHeldSubject()->heldAttributes()))
            : $attributes;
    }
}
