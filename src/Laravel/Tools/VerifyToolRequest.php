<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Closure;
use Illuminate\Http\Request;
use Shirahcan\AiClient\Chat\ToolRequestVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only ai-service may call a product's chat tools, and only for the actor it was handed a
 * token for.
 *
 * Two checks, both required:
 *   1. the request is signed by ai-service (HMAC of `timestamp.body` with the shared secret,
 *      within 60 seconds), and
 *   2. a tool CALL carries an unexpired {@see ToolToken} whose actor still exists; the actor
 *      is put on the request for {@see ToolHostController}. The manifest needs no token: it
 *      lists tools, it reads nothing about anyone.
 *
 * ⚠ AN UNSET SECRET REFUSES EVERYTHING. It is a deployment credential
 * (`ai-client.tools.secret`, the same value as the tool host row in ai-service's vault), and
 * a box without it must not serve tools to whoever asks.
 */
class VerifyToolRequest
{
    public const ACTOR = 'ai_tools.actor';

    public function __construct(private ToolCatalog $catalog) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ToolRequestVerifier::verify(
            $request->header('X-Ai-Timestamp'),
            $request->header('X-Ai-Signature'),
            (string) $request->getContent(),
            (string) config('ai-client.tools.secret', ''),
        )) {
            return response()->json(['success' => false, 'message' => 'This request is not signed by the AI service.'], 401);
        }

        if ($request->route('name') !== null) {
            $actor = ToolToken::open($request->header('X-Ai-Tool-Token'));

            if ($actor === null || (! $actor->isGuest() && ! $this->catalog->actorExists($actor))) {
                return response()->json(['success' => false, 'message' => 'This tool token is missing or has expired.'], 401);
            }

            $request->attributes->set(self::ACTOR, $actor);
        }

        return $next($request);
    }
}
