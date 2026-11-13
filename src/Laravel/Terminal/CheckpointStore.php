<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * Where a product keeps a console's rewind points (1.67.0, shared console C1b; `point()` 1.71.0, C3). The design is
 * the product's own data, so the product stores it; the kit only asks for one before an ask that may change it,
 * and reads one back for Rewind.
 *
 * ⚠ RESTORING NEVER WRITES THE DESIGN HERE. The point is handed back and the product saves it through its own
 * validated save, WITH the document's revision, so a Rewind made while someone else saved answers a conflict
 * instead of silently undoing their edit.
 *
 * ⚠ Retention is the store's job: the newest points per subject, and a deleted document drops its points.
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

    /**
     * The design a rewind point holds, or null when it is not this subject's (or is gone).
     *
     * @return array<int|string, mixed>|null
     */
    public function point(string $kind, string $id, string $pointId): ?array;
}
