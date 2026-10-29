<?php

namespace Shirahcan\AiClient\Laravel\Tools;

use Illuminate\Support\Facades\Route;

/**
 * The tool host's routes, registered by the product inside its API routes file:
 *
 *   ToolRoutes::host();                                   // ai-tools/manifest + ai-tools/{name}
 *   Route::middleware('auth:...')->group(fn () => ToolRoutes::chatEvents('assistant/conversations'));
 *
 * ⚠ `host()` RIDES NO USER AUTH, on purpose: the caller is ai-service, proven by the
 * signature, and the user comes from the token. `chatEvents()` is the opposite: it is called
 * by a browser, so it goes inside the product's own auth group.
 */
final class ToolRoutes
{
    /** The throttle the kit defines when the product has not ({@see \Shirahcan\AiClient\AiClientServiceProvider}). */
    public const LIMITER = 'ai-tools';

    public static function host(string $prefix = 'ai-tools'): void
    {
        Route::prefix($prefix)->middleware([VerifyToolRequest::class, 'throttle:'.self::LIMITER])->group(function () {
            Route::get('manifest', [ToolHostController::class, 'manifest'])->name('ai-tools.manifest');
            Route::post('{name}', [ToolHostController::class, 'call'])
                ->where('name', '[a-zA-Z0-9_-]{1,64}')->name('ai-tools.call');
        });
    }

    public static function chatEvents(string $conversationsPrefix, int $perMinute = 30): void
    {
        Route::get(trim($conversationsPrefix, '/').'/{id}/events', ChatEventsController::class)
            ->where('id', '[A-Za-z0-9_-]{1,64}')
            ->middleware('throttle:'.$perMinute.',1')
            ->name('ai-chat.events');
    }
}
