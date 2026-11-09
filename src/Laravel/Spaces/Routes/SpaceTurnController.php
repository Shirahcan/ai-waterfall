<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Shirahcan\AiClient\Exceptions\AiRequestRefusedException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnEvents;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnPage;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnRefused;
use Shirahcan\AiClient\Laravel\Spaces\Turns\TurnRunner;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The chat turn, streamed (1.57, plan S2c, owner decision D2). The transport is the kit's; what the turn
 * does is the product's `TurnRunner`.
 *
 * ⚠ THE THREAD IS CHECKED BEFORE A BYTE IS STREAMED. A `conversation_id` outside the person's space is
 * a plain 404 here, so no product runner is ever handed another person's thread.
 *
 * ⚠ ONE STREAMED REQUEST HOLDS ONE PHP WORKER for the length of the turn (Portify production has 10).
 * That is why Stop must stop the provider (`cancel`), and why the stream sends nothing it does not need.
 */
final class SpaceTurnController extends SpaceController
{
    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        if (! $this->settings->enabled() || ! app()->bound(TurnRunner::class)) {
            return $this->fail('Not found', 404);
        }

        $common = $request->validate([
            'conversation_id' => ['nullable', 'uuid'],
            'turn_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        /** @var TurnRunner $runner */
        $runner = app(TurnRunner::class);
        $data = $runner->validate($request);
        // ⚠ The panel's page reaches EVERY runner (1.61, TurnPage): Mira's dropped it and answered "at /".
        // A runner's own validated values win; it reads them with TurnPage::from($data).
        $data += $request->validate(TurnPage::RULES);

        try {
            $space = $this->spaces->current($request);
            if ($space === null) {
                return $this->fail('Not found', 404);
            }

            $conversationId = $common['conversation_id'] ?? null;
            if ($conversationId !== null) {
                $this->ai->spaces()->conversation($space, $conversationId, ['limit' => 1]);
            }
        } catch (AiRequestRefusedException $e) {
            return $this->fail($e->getMessage(), $e->status === 404 ? 404 : 422);
        } catch (AiUnavailableException $e) {
            report($e);

            return $this->fail('The assistant is not reachable right now. Try again in a moment.', 503);
        }

        $turnId = (string) ($common['turn_id'] ?? Str::uuid());
        $events = app(TurnEvents::class);

        return response()->stream(function () use ($request, $runner, $data, $space, $conversationId, $turnId, $events) {
            // A person who hangs up stops the work on the next write, instead of it running on, billed.
            ignore_user_abort(false);

            $stages = $runner->openingStages();
            $last = (string) array_pop($stages);
            foreach ($stages as $stage) {
                $events->stage((string) $stage);
            }
            // The id Stop resolves, published BEFORE the work, so a Stop one tick later stops something real.
            $events->stage($last, $turnId);

            try {
                $result = $runner->run($request, $data, $conversationId, $turnId, $events);
                if ($result === null) {
                    return; // stopped: nothing half-finished may reach the thread
                }

                try {
                    $this->ai->spaces()->reopenNext($space, $result->conversationId);
                } catch (Throwable $e) {
                    report($e); // reopening it next time is a convenience; the answer still goes out
                }

                $events->message($result->conversationId, $result->message);
            } catch (TurnRefused $e) {
                $events->error($e->getMessage());
            } catch (Throwable $e) {
                report($e);
                $events->error($runner->failureMessage());
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // nginx and LiteSpeed buffer a proxied response unless told not to; the stream would arrive whole.
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** Stop a turn for real. Always 200: the person has already been told it stopped. */
    public function cancel(Request $request, string $turnId): JsonResponse
    {
        if (! $this->settings->enabled() || ! app()->bound(TurnRunner::class)) {
            return $this->fail('Not found', 404);
        }

        try {
            $stopped = app(TurnRunner::class)->cancel($request, $turnId);
        } catch (Throwable $e) {
            report($e);
            $stopped = false;
        }

        return $this->ok(['cancelled' => $stopped]);
    }
}
