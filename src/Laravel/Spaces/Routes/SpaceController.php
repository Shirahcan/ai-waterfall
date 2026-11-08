<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Laravel\Spaces\Spaces;
use Shirahcan\AiClient\Laravel\Spaces\SpaceSettings;
use Throwable;

/**
 * What every space route does before and after its own work (1.57): the switch, the person's space,
 * and turning ai-service's answer into the product-facing envelope `{success, message, data}` that
 * ai-ui reads.
 *
 * ⚠ THE SPACE COMES FROM THE SESSION ONLY. `Spaces::current()` asks the product's `SpaceOwner`; no
 * route reads a space, owner or person reference from the request.
 *
 * ⚠ ERRORS KEEP THEIR REAL REASON. A refusal from ai-service ("You can pin up to 10 conversations.")
 * reaches the person as the service wrote it, with its status; an outage is a 503 that says so. A
 * product's error surface (Porter for Portify) shows that message, never a generic one.
 */
abstract class SpaceController extends Controller
{
    public function __construct(
        protected readonly AiClient $ai,
        protected readonly Spaces $spaces,
        protected readonly SpaceSettings $settings,
    ) {}

    /**
     * Run `$work` with the person's space id, answering 404 when the panel is off or there is no
     * assistant for this person, 503 when ai-service cannot be reached.
     *
     * @param  Closure(string): array<string, mixed>  $work
     */
    protected function inSpace(Request $request, Closure $work): JsonResponse
    {
        if (! $this->settings->enabled()) {
            return $this->fail('Not found', 404);
        }

        try {
            $space = $this->spaces->current($request);
            if ($space === null) {
                return $this->fail('Not found', 404);
            }

            return $this->ok($work($space));
        } catch (AiRequestRefusedException $e) {
            return $this->fail($e->getMessage(), $e->status >= 400 && $e->status < 500 ? $e->status : 422);
        } catch (AiUnavailableException $e) {
            report($e);

            return $this->fail('The assistant is not reachable right now. Try again in a moment.', 503);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('That did not work. Try again in a moment.', 500);
        }
    }

    /** @param array<string, mixed> $data */
    protected function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Success', 'data' => $data]);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /**
     * A thread in the shape ai-ui's conversation list reads (the same fields `ThreadRow` gave it), with
     * the ai-service id: it is safe to show, because every id outside the person's space is a 404.
     *
     * @param  array<string, mixed>  $c
     * @return array{id: string, title: ?string, audience: ?string, last_message_at: ?string, created_at: ?string, pinned: bool}
     */
    protected function row(array $c): array
    {
        return [
            'id' => (string) $c['id'],
            'title' => isset($c['title']) && $c['title'] !== '' ? (string) $c['title'] : null,
            'audience' => $c['audience'] ?? null,
            'last_message_at' => $c['last_turn_at'] ?? $c['created_at'] ?? null,
            'created_at' => $c['created_at'] ?? null,
            'pinned' => ($c['pinned_at'] ?? null) !== null,
        ];
    }
}
