<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\AiClient\AiClient;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A thread's late answers, pushed into the open panel (`GET .../conversations/{id}/events?after=N`).
 *
 * `@shirahcan/ai-ui` opens this only while one of the thread's answers is still working; the
 * product's job publishes the finished message with `AiClient::publishChatEvent()`. This is
 * the proxy: the product's own auth (its route middleware) and {@see ChatChannelGuard}, then
 * ai-service's channel stream relayed frame by frame. ai-service is loopback-only.
 *
 * ⚠ PUSHED, NOT POLLED: one held line per waiting panel, `WAIT_SECONDS`, then `idle {after}`
 * and the panel reconnects from that cursor. ⚠ A dead ai-service holds the line a few seconds
 * before ending, so a panel can never re-dial it in a tight loop.
 */
class ChatEventsController extends Controller
{
    /** Under the ~100s a Cloudflare-fronted line survives. */
    public const WAIT_SECONDS = 50;

    public function __construct(private ChatChannelGuard $guard) {}

    public function __invoke(Request $request, string $id, AiClient $ai): StreamedResponse|JsonResponse
    {
        if (! $this->guard->enabled()) {
            return response()->json(['success' => false, 'message' => 'Live updates are not enabled.'], 404);
        }

        $channel = $this->guard->channelFor($request, $id);
        if ($channel === null) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        $after = max(0, (int) $request->query('after', 0));

        return response()->stream(function () use ($ai, $channel, $after) {
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0 && $limit < self::WAIT_SECONDS + 30) {
                @set_time_limit(self::WAIT_SECONDS + 30);
            }

            try {
                $after = $ai->chatEvents($channel, $after, function (int $eventId, string $type, array $data) {
                    self::emit($type, $data + ['event_id' => $eventId]);
                }, self::WAIT_SECONDS);
            } catch (Throwable $e) {
                report($e);
                sleep(5);
            }

            self::emit('idle', ['after' => $after]);
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            // nginx and LiteSpeed buffer proxied responses unless told not to.
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
