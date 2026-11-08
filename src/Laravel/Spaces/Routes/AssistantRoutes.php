<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Illuminate\Support\Facades\Route;

/**
 * The ONE route module a product mounts for its panel's data (1.57, Portify plan
 * assistant-spaces-2026-11-08 S2b). Owner 2026-11-08: "each product should embed/integrate the panel
 * the same way, providing the necessary specifics".
 *
 * Mount it inside the product's AUTHENTICATED group (the product's session is the only proof of who
 * the person is); bind `SpaceOwner` (who they are to the assistant) and `SpaceSettings` (the gate):
 *
 *     Route::prefix('assistant')->group(fn () => AssistantRoutes::mount('space'));
 *
 * Routes (names `assistant.space.*`): threads list/search/rename-pin, a conversation read/erase, the
 * conversation to reopen, the up-arrow ring, memory list/correct/forget/forget-all, a vote, and a
 * confirm card's outcome. The chat turn itself (stream, cancel) is added by S2c.
 */
final class AssistantRoutes
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public static function mount(string $prefix = 'space'): void
    {
        $p = trim($prefix, '/');
        $p = $p === '' ? '' : $p.'/';

        Route::get($p.'threads', [SpaceThreadsController::class, 'index'])->middleware('throttle:60,1')->name('assistant.space.threads.index');
        Route::get($p.'threads/search', [SpaceThreadsController::class, 'search'])->middleware('throttle:30,1')->name('assistant.space.threads.search');
        Route::patch($p.'threads/{id}', [SpaceThreadsController::class, 'update'])->where('id', self::UUID)
            ->middleware('throttle:30,1')->name('assistant.space.threads.update');

        Route::get($p.'conversations/{id}', [SpaceThreadsController::class, 'show'])->where('id', self::UUID)
            ->middleware('throttle:60,1')->name('assistant.space.conversations.show');
        Route::delete($p.'conversations/{id}', [SpaceThreadsController::class, 'destroy'])->where('id', self::UUID)
            ->middleware('throttle:30,1')->name('assistant.space.conversations.destroy');
        Route::post($p.'conversations/{id}/turns/{turn}/feedback', [SpacePanelController::class, 'feedback'])
            ->where(['id' => self::UUID, 'turn' => self::UUID])->middleware('throttle:30,1')->name('assistant.space.feedback');
        Route::patch($p.'conversations/{id}/turns/{turn}/card-state', [SpacePanelController::class, 'cardState'])
            ->where(['id' => self::UUID, 'turn' => self::UUID])->middleware('throttle:30,1')->name('assistant.space.card-state');

        Route::get($p.'active-conversation', [SpacePanelController::class, 'active'])->middleware('throttle:60,1')->name('assistant.space.active');
        Route::put($p.'active-conversation', [SpacePanelController::class, 'reopen'])->middleware('throttle:60,1')->name('assistant.space.reopen');
        Route::get($p.'input-history', [SpacePanelController::class, 'history'])->middleware('throttle:60,1')->name('assistant.space.history');

        Route::get($p.'memory', [SpaceMemoryController::class, 'index'])->middleware('throttle:60,1')->name('assistant.space.memory.index');
        Route::delete($p.'memory', [SpaceMemoryController::class, 'clear'])->middleware('throttle:10,1')->name('assistant.space.memory.clear');
        Route::put($p.'memory/{key}', [SpaceMemoryController::class, 'put'])->where('key', '[a-z0-9][a-z0-9_.-]{0,63}')
            ->middleware('throttle:30,1')->name('assistant.space.memory.put');
        Route::delete($p.'memory/{key}', [SpaceMemoryController::class, 'forget'])->where('key', '[a-z0-9][a-z0-9_.-]{0,63}')
            ->middleware('throttle:30,1')->name('assistant.space.memory.forget');
    }
}
