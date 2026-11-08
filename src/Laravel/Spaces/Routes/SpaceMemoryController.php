<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What {assistant} knows about you" (1.57): the person reads, corrects and forgets what the assistant
 * remembered in this space. The same facts the chat's remember/recall tools use (ai-service keeps them
 * under the space and mirrors them to the person's own rows).
 *
 * A fact's value is shown as words: ai-service stores the chat's facts as `{v, label}`, and an older or
 * hand-written fact as a plain value; both read the same here.
 */
final class SpaceMemoryController extends SpaceController
{
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    public function index(Request $request): JsonResponse
    {
        return $this->inSpace($request, fn (string $space) => [
            'memory' => array_map(fn (array $m) => $this->fact($m), $this->ai->spaces()->memory($space)),
        ]);
    }

    /** Correct one fact. A person's own edit is authoritative, so it becomes `explicit`. */
    public function put(Request $request, string $key): JsonResponse
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return $this->fail('That is not a remembered fact.', 404);
        }

        $data = $request->validate([
            'value' => ['required', 'string', 'min:1', 'max:500'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->inSpace($request, function (string $space) use ($key, $data) {
            $saved = $this->ai->spaces()->remember($space, $key, [
                'v' => trim((string) $data['value']),
                'label' => trim((string) ($data['label'] ?? '')) ?: $key,
                'confidence' => null,
            ], 'explicit');

            return ['fact' => $this->fact($saved + ['key' => $key])];
        });
    }

    public function forget(Request $request, string $key): JsonResponse
    {
        return $this->inSpace($request, fn (string $space) => ['forgotten' => $this->ai->spaces()->forget($space, $key)]);
    }

    public function clear(Request $request): JsonResponse
    {
        return $this->inSpace($request, fn (string $space) => ['forgotten' => $this->ai->spaces()->forgetAll($space)]);
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array{key: string, label: string, value: string, source: string, updated_at: ?string}
     */
    private function fact(array $m): array
    {
        $value = $m['value'] ?? null;
        $words = is_array($value) ? ($value['v'] ?? json_encode($value)) : $value;

        return [
            'key' => (string) $m['key'],
            'label' => is_array($value) && ($value['label'] ?? '') !== '' ? (string) $value['label'] : (string) $m['key'],
            'value' => is_scalar($words) ? (string) $words : '',
            'source' => (string) ($m['source'] ?? 'explicit'),
            'updated_at' => $m['updated_at'] ?? null,
        ];
    }
}
