<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Illuminate\Database\Eloquent\Model;
use Shirahcan\AiClient\Conversation;

/**
 * A model whose conversation FACTS live in ai-service only.
 *
 * The product's row is a handle: its own id, its links to its own things (the user, the case),
 * and the service's conversation id. The title, how many turns there are and when the last one
 * landed are the SERVICE'S, read on load and written there first.
 *
 * ⚠ ONLY THE SUMMARY SCALARS ARE HELD - NEVER THE TURNS. This is the one place the conversation
 * store differs from the booking store it is modelled on, and the difference is structural: a
 * booking's held facts are a handful of scalars on one row, so hydrating them is free, while a
 * conversation's are a LIST that grows. Hydrating turns for every row of a list view would be
 * N requests of M turns each. So a collection hydrates `title`, `turn_count` and `last_turn_at`
 * in ONE call, and the turns are fetched only for the thread actually on screen.
 *
 * ⚠ A ROW WHOSE THREAD THE SERVICE NO LONGER HOLDS IS A REAL STATE, AND IT WILL HAPPEN. The
 * retention window erases threads on a schedule, and an erasure erases on request. `hydrate()`
 * is handed a null for those, and what it returns is what such a row LOOKS like to a person -
 * which is a decision the product has to make rather than inherit. Leaving them looking normal
 * is the defect: the person opens a conversation in their list and finds nothing in it.
 */
interface HeldConversationSubject
{
    /** The column on the product's row that holds the service's conversation id. */
    public function column(): string;

    /**
     * The model attributes the service holds.
     *
     * ⚠ THESE ARE NEVER WRITTEN TO THE PRODUCT'S TABLE while the row is held. Keep the columns
     * (a row from before the cutover still needs them) but stop treating them as yours.
     *
     * @return list<string>
     */
    public function heldAttributes(): array;

    /**
     * The held attributes a product may CHANGE, mapped to the service's own field names.
     *
     * ⚠ EVERYTHING ELSE IS DERIVED AND WRITING IT IS A BUG, NOT A PREFERENCE. `turn_count` and
     * `last_turn_at` are counted from the turns by the service; a product that set them would
     * be stating a second, disagreeing version of a fact it does not own. The trait THROWS on
     * that rather than dropping it, because a dropped write is the defect `Meeting` already
     * shipped over its booking - the rename went nowhere and nothing said so.
     *
     * Usually: `['title' => 'title']`.
     *
     * @return array<string, string>  model attribute => service field (title|audience|subject_ref)
     */
    public function writeThrough(): array;

    /**
     * The held attributes' values for a record, from its conversation, in the model's own terms.
     *
     * `$c` is null when the service no longer holds the thread. Say what such a record is -
     * usually that it has been erased, and that the product should stop listing it.
     *
     * @return array<string, mixed>
     */
    public function hydrate(?Conversation $c, Model $m): array;
}
