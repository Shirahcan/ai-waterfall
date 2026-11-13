<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * Where a product keeps a console's rewind points (1.67.0, shared console C1b). The design is the product's own
 * data, so the product stores it; the kit only asks for one before an ask that may change it.
 *
 * ⚠ RESTORING NEVER WRITES THE DESIGN HERE. The point is handed back and the product saves it through its own
 * validated save, so its checks, its undo and its merge rules all still apply.
 */
interface CheckpointStore
{
    /**
     * Take a rewind point of `$design` (the subject as it is before the ask) and answer its id, or null when the
     * product keeps none for this subject.
     *
     * @param  string  $kind  the console's subject kind (as in the console's routes)
     * @param  array<int|string, mixed>  $design
     */
    public function rewindPoint(string $kind, string $id, array $design, ?string $sessionId, ?string $userId): ?string;
}
