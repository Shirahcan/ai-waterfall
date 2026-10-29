<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * The tools ai-service's chat engine calls back: `GET ai-tools/manifest?audience=` and
 * `POST ai-tools/{name}`, for any product, behind {@see VerifyToolRequest}.
 *
 * ⚠ THE BODY IS ai-service's MACHINE CONTRACT, not a product's `{success, data}` envelope:
 * `tools` for the manifest, `ok / result / card / error` for a call. A tool that throws is
 * reported and answered as a tool error the model can read, never a raw exception.
 */
class ToolHostController extends Controller
{
    /** An audience is the PRODUCT's own role name (candidate, recruiter, guest...), checked for shape only. */
    public const AUDIENCE_PATTERN = '/^[a-z][a-z_]{1,31}$/';

    /** What a slow tool (a data agent) may take; ai-service caps a tool's wait at 90s too. */
    public const CALL_SECONDS = 90;

    public function __construct(private ToolCatalog $catalog) {}

    public function manifest(Request $request): JsonResponse
    {
        $data = $request->validate(['audience' => ['required', 'string', 'regex:'.self::AUDIENCE_PATTERN]]);

        return response()->json(['tools' => array_values($this->catalog->definitions($data['audience']))]);
    }

    public function call(Request $request, string $name): JsonResponse
    {
        $actor = $request->attributes->get(VerifyToolRequest::ACTOR);
        $input = $request->input('input', []);

        if (! $actor instanceof ToolActor) {
            return response()->json(['ok' => false, 'error' => 'This tool call names nobody.'], 401);
        }
        if (! is_array($input)) {
            return response()->json(['ok' => false, 'error' => 'A tool input is a JSON object.'], 422);
        }

        // Only RAISE an existing limit: under the CLI (tests, artisan) there is none, and
        // setting one there would put a fuse on the whole process.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < self::CALL_SECONDS) {
            @set_time_limit(self::CALL_SECONDS);
        }

        try {
            return response()->json($this->catalog->run($name, $input, $actor));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'That tool failed while reading the information. Answer without it, and say so.'], 500);
        }
    }
}
