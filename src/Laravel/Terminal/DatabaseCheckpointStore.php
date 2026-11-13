<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The kit's default rewind-point store (1.71.0, shared console C3), for a SINGLE-PRODUCT host that has no store of
 * its own. Its table comes from a PUBLISHED migration (`php artisan vendor:publish --tag=ai-client-console`), so a
 * product that keeps its own store never gets the table.
 *
 * ⚠ NOT FOR AN ESTATE HOST. The table has no product column, so a host acting for several products (`as_product`)
 * would mix their designs in one table. Such a host, and any service that owns its documents (growth-service),
 * implements {@see CheckpointStore} itself so a document's designs live with the document.
 *
 * Who took a point is the actor pair (ref and name), never free text. Only the newest {@see KEEP} points per subject
 * are kept; dropping a deleted document's points is the host's call ({@see forget()}).
 */
class DatabaseCheckpointStore implements CheckpointStore
{
    public const TABLE = 'console_checkpoints';

    /** Points kept per subject; older ones are dropped as new ones are taken. */
    public const KEEP = 100;

    public function rewindPoint(string $kind, string $id, array $design, ?string $sessionId, ?string $userId): ?string
    {
        $pointId = (string) Str::uuid();
        $user = auth()->user();
        $name = $user !== null && is_string($user->name ?? null) ? $user->name : null;

        DB::transaction(function () use ($pointId, $kind, $id, $design, $sessionId, $userId, $name) {
            DB::table(self::TABLE)->insert([
                'id' => $pointId,
                'subject_kind' => $kind,
                'subject_id' => $id,
                'session_id' => $sessionId,
                'design' => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by_ref' => $userId,
                'created_by_name' => $userId === null ? null : $name,
                'created_at' => now(),
            ]);

            $keep = DB::table(self::TABLE)->where('subject_kind', $kind)->where('subject_id', $id)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::KEEP)->pluck('id');

            DB::table(self::TABLE)->where('subject_kind', $kind)->where('subject_id', $id)->whereNotIn('id', $keep)->delete();
        });

        return $pointId;
    }

    public function point(string $kind, string $id, string $pointId): ?array
    {
        $design = DB::table(self::TABLE)->where('id', $pointId)->where('subject_kind', $kind)->where('subject_id', $id)->value('design');

        if (! is_string($design)) {
            return null;
        }

        $decoded = json_decode($design, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Drop every point of a subject (the host calls this when the document is deleted). */
    public function forget(string $kind, string $id): int
    {
        return DB::table(self::TABLE)->where('subject_kind', $kind)->where('subject_id', $id)->delete();
    }
}
