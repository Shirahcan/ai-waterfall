<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

use Illuminate\Support\Facades\Route;

/**
 * Live dictation's route, mounted by the product INSIDE its signed-in API group:
 *
 *   Route::middleware('auth:...')->group(fn () => DictationRoutes::mount());   // POST ai/dictation
 *
 * The gate and the daily minutes come from {@see DictationSettings}; bind a DB-backed one so both change
 * without a deploy. The frontend is ai-ui's composer `dictation` prop, whose `transcribe` posts the phrase
 * here as multipart `audio` + `surface` (+ `language`, `terms[]`).
 */
final class DictationRoutes
{
    /** 40 phrases a minute per person: one every 1.5 s, more than anyone speaks. Defined by the kit when absent. */
    public const LIMITER = 'ai-dictation';

    public static function mount(string $path = 'ai/dictation'): void
    {
        Route::post(trim($path, '/'), DictationController::class)
            ->middleware('throttle:'.self::LIMITER)
            ->name('ai.dictation.store');
    }
}
