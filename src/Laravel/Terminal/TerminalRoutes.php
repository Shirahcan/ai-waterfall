<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Illuminate\Support\Facades\Route;

/**
 * A console for any studio surface, in one call (1.58.0, Porter console).
 *
 * Mount it inside the product's authenticated group (and its own gate), bind a `TerminalSubject`, and
 * the console's sessions, brief, context size, `/compact` and rewind work for every subject kind that
 * binding accepts:
 *
 *   Route::middleware(['auth', 'gate:...'])->group(fn () => TerminalRoutes::mount('website/console'));
 *   $this->app->bind(TerminalSubject::class, MyStudioSubject::class);
 *
 * The ask itself is the product's own route (a page Build, a component compose): it passes the
 * session id, reads `SessionWindow` for its prompt and records its turn with `TerminalSessions`.
 */
final class TerminalRoutes
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const KIND = '[a-z][a-z0-9_]{0,40}';

    private const ID = '[A-Za-z0-9_-]{1,64}';

    public static function mount(string $prefix = 'console'): void
    {
        $p = trim($prefix, '/');
        $p = ($p === '' ? '' : $p.'/').'{kind}/{id}';
        $where = ['kind' => self::KIND, 'id' => self::ID, 'session' => self::UUID];
        $c = TerminalController::class;

        Route::get($p, [$c, 'show'])->where($where)->middleware('throttle:60,1')->name('assistant.terminal.show');
        Route::get($p.'/sessions', [$c, 'index'])->where($where)->middleware('throttle:60,1')->name('assistant.terminal.sessions.index');
        Route::post($p.'/sessions', [$c, 'start'])->where($where)->middleware('throttle:30,1')->name('assistant.terminal.sessions.start');
        Route::get($p.'/sessions/{session}', [$c, 'open'])->where($where)->middleware('throttle:60,1')->name('assistant.terminal.sessions.open');
        Route::post($p.'/sessions/{session}/compact', [$c, 'compact'])->where($where)->middleware('throttle:10,1')->name('assistant.terminal.sessions.compact');
        Route::post($p.'/sessions/{session}/rewind', [$c, 'rewind'])->where($where)->middleware('throttle:30,1')->name('assistant.terminal.sessions.rewind');
        Route::put($p.'/brief', [$c, 'brief'])->where($where)->middleware('throttle:30,1')->name('assistant.terminal.brief');
    }
}
