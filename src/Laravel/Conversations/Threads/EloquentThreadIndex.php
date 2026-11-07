<?php

namespace Shirahcan\AiClient\Laravel\Conversations\Threads;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see ThreadIndex} for a product whose conversation model has the estate's columns: `id`, `user_id`, `title`,
 * `audience`, `last_message_at`, `created_at`, `ai_conversation_id` (Portify's and MployNow's both do).
 *
 *   final class MiraThreadIndex extends EloquentThreadIndex
 *   {
 *       protected function model(): string { return AssistantConversation::class; }
 *       public function enabled(): bool { return LiveGate::enabled('ai.chat_time_and_threads.enabled', ...); }
 *   }
 *
 * A model whose title is HELD in ai-service (the kit's `HeldInAiService` trait) renames through its own save,
 * so the rename reaches the service with no extra code here.
 */
abstract class EloquentThreadIndex implements ThreadIndex
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** The owner key ai-service files the person's conversations under. Default: their id. */
    public function ownerRef(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }

    public function page(Authenticatable $user, ?string $before, int $limit): array
    {
        $query = $this->own($user)
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id');

        $cursor = self::cursor($before);
        if ($cursor !== null) {
            [$at, $id] = $cursor;
            $query->where(function (Builder $q) use ($at, $id) {
                $q->whereRaw('COALESCE(last_message_at, created_at) < ?', [$at])
                    ->orWhere(function (Builder $same) use ($at, $id) {
                        $same->whereRaw('COALESCE(last_message_at, created_at) = ?', [$at])->where('id', '<', $id);
                    });
            });
        }

        // ⚠ `ai_conversation_id` IN THE SELECT, or a model holding its title in ai-service hydrates nothing.
        $models = $query->limit($limit + 1)->get($this->columns());
        $more = $models->count() > $limit;
        $models = $models->take($limit)->values();

        $last = $models->last();
        $next = $more && $last !== null
            ? base64_encode(($last->last_message_at ?? $last->created_at)?->format('Y-m-d H:i:s').'|'.$last->getKey())
            : null;

        return ['rows' => $models->map(fn (Model $m) => $this->row($m))->all(), 'next' => $next];
    }

    public function byServiceIds(Authenticatable $user, array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $out = [];
        foreach ($this->own($user)->whereIn('ai_conversation_id', $serviceIds)->get($this->columns()) as $m) {
            $out[(string) $m->ai_conversation_id] = $this->row($m);
        }

        return $out;
    }

    public function find(Authenticatable $user, string $id): ?ThreadRow
    {
        $m = $this->own($user)->find($id);

        return $m === null ? null : $this->row($m);
    }

    public function rename(Authenticatable $user, string $id, string $title): ?ThreadRow
    {
        $m = $this->own($user)->find($id);
        if ($m === null) {
            return null;
        }
        $m->title = $title;
        $m->save();

        return $this->row($m);
    }

    /** @return Builder<Model> */
    protected function own(Authenticatable $user): Builder
    {
        $class = $this->model();

        return $class::query()->where('user_id', (string) $user->getAuthIdentifier());
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return ['id', 'title', 'audience', 'last_message_at', 'created_at', 'ai_conversation_id'];
    }

    protected function row(Model $m): ThreadRow
    {
        return new ThreadRow(
            id: (string) $m->getKey(),
            title: $m->title !== null ? (string) $m->title : null,
            audience: $m->audience !== null ? (string) $m->audience : null,
            lastMessageAt: $m->last_message_at?->toIso8601String(),
            createdAt: $m->created_at?->toIso8601String(),
            serviceId: $m->ai_conversation_id !== null && $m->ai_conversation_id !== '' ? (string) $m->ai_conversation_id : null,
        );
    }

    /** @return array{0: string, 1: string}|null */
    private static function cursor(?string $before): ?array
    {
        if ($before === null || $before === '') {
            return null;
        }
        $decoded = base64_decode($before, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }
        [$at, $id] = explode('|', $decoded, 2);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) === 1 && $id !== '' ? [$at, $id] : null;
    }
}
