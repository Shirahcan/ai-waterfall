<?php

namespace Shirahcan\AiClient\Laravel\Console;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shirahcan\AiClient\AiClient;
use Shirahcan\AiClient\Exceptions\AdminNotConfiguredException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;

/**
 * Forwards one whitelisted console call to ai-service (see {@see ConsoleRoutes}).
 *
 * The service's own status and message come back unchanged, so a 422 about a template field
 * lands beside that field in the console. Only two answers are the kit's: the product has no
 * admin key (403, said plainly), and the service is unreachable (503, reported).
 */
final class ConsoleProxyController
{
    public function __invoke(Request $request, AiClient $client): JsonResponse
    {
        $row = (int) $request->route()->defaults['_ai_console_row'];
        [$method, , , $admin, $actorField] = ConsoleRoutes::TABLE[$row];
        $asAdmin = $admin === ConsoleRoutes::PREFER_ADMIN ? $client->canAdminister() : (bool) $admin;
        /*
         * 1.38: `?as_product=mploynow` reads or edits ANOTHER product's prompts, voice, knowledge
         * or feedback. Only the estate admin key may (the service refuses a product key that
         * names another product), so the row goes with the admin key, or is refused here as an
         * estate row would be when the product holds none.
         */
        $actsFor = $request->query(ConsoleRoutes::AS_PRODUCT);
        if (is_string($actsFor) && $actsFor !== '') {
            $asAdmin = true;
        }

        $params = array_filter($request->route()->parameters(), 'is_string');
        $path = ConsoleRoutes::upstream($row, $params);

        $body = $request->json()->all();
        if ($actorField !== null) {
            $body[$actorField] = ConsoleActor::name($request);
        }

        try {
            $result = $client->forward($method, $path, $request->query(), $body, $asAdmin);
        } catch (AdminNotConfiguredException) {
            return new JsonResponse([
                'error'   => 'admin_not_configured',
                'message' => 'This product does not hold the estate admin key, so estate-wide settings are read-only here.',
            ], 403);
        } catch (AiUnavailableException $e) {
            report($e);

            return new JsonResponse(['error' => 'ai_unreachable', 'message' => 'The AI service did not answer. Try again in a minute.'], 503);
        }

        $status = $result['status'];
        if ($status >= 500) {
            // The service's own 5xx text is not for a browser; it is reported so the product's
            // error surface (Portify: platform_errors) has a row to point at.
            report(new \RuntimeException("ai-console: {$method} {$path} answered {$status}"));

            return new JsonResponse(['error' => 'ai_error', 'message' => 'The AI service failed to answer that. It has been reported.'], 502);
        }

        return new JsonResponse($result['body'], $status);
    }
}
