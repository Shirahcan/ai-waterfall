<?php

namespace Shirahcan\AiClient\Laravel\Conversations\Threads;

use Illuminate\Support\Facades\Route;

/**
 * The conversation list's routes, mounted by the product INSIDE its signed-in API group, BEFORE any
 * `{prefix}/conversations/{id}` route (`threads` is not a conversation id):
 *
 *   ThreadRoutes::mount('assistant');   // GET assistant/threads, GET assistant/threads/search, PATCH assistant/threads/{id}
 *
 * Bind {@see ThreadIndex} to the product's own (usually an {@see EloquentThreadIndex}). The browser half is
 * ai-ui's `useAssistantThreads`.
 */
final class ThreadRoutes
{
    public static function mount(string $prefix = 'assistant'): void
    {
        $prefix = trim($prefix, '/');

        Route::get($prefix.'/threads', [ThreadsController::class, 'index'])
            ->middleware('throttle:60,1')->name('assistant.threads.index');
        Route::get($prefix.'/threads/search', [ThreadsController::class, 'search'])
            ->middleware('throttle:30,1')->name('assistant.threads.search');
        Route::patch($prefix.'/threads/{id}', [ThreadsController::class, 'update'])
            ->where('id', '[A-Za-z0-9_-]{1,64}')
            ->middleware('throttle:30,1')->name('assistant.threads.update');
    }
}
