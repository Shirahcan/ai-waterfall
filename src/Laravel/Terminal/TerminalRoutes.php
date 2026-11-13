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

    /** The route default that names a mount's own subject class (1.72.0). */
    public const SUBJECT = 'terminal_subject';

    /**
     * Mount the console's routes under `$prefix`. With no subject they serve the bound `TerminalSubject` (one console
     * surface per product, as before). Since 1.72.0 a product with SEVERAL console surfaces in different areas mounts
     * each with its own subject class and a name (`as`), so two mounts never share a route name:
     *
     *   TerminalRoutes::mount('console');                                          // assistant.terminal.*
     *   TerminalRoutes::mount('console', EmailTemplateConsoleSubject::class, 'email'); // assistant.terminal.email.*
     *
     * @param  class-string<TerminalSubject>|null  $subject
     */
    public static function mount(string $prefix = 'console', ?string $subject = null, ?string $as = null): void
    {
        $p = trim($prefix, '/');
        $p = ($p === '' ? '' : $p.'/').'{kind}/{id}';
        $where = ['kind' => self::KIND, 'id' => self::ID, 'session' => self::UUID];
        $c = TerminalController::class;
        $names = 'assistant.terminal.'.($as !== null && $as !== '' ? trim($as, '.').'.' : '');

        // verb, path, action, per-minute limit, name
        $routes = [
            ['get', '', 'show', 60, 'show'],
            ['get', '/sessions', 'index', 60, 'sessions.index'],
            ['post', '/sessions', 'start', 30, 'sessions.start'],
            ['get', '/sessions/{session}', 'open', 60, 'sessions.open'],
            ['post', '/sessions/{session}/compact', 'compact', 10, 'sessions.compact'],
            ['post', '/sessions/{session}/recap', 'recap', 20, 'sessions.recap'],
            ['post', '/sessions/{session}/rewind', 'rewind', 30, 'sessions.rewind'],
            ['put', '/brief', 'brief', 30, 'brief'],
            // 1.64.0: the person's specialization choice for this subject (a guide, none, or back to the subject's own).
            ['put', '/specialization', 'specialization', 30, 'specialization'],
            // 1.65.0: the template this subject follows inside its guide (a template, none, or back to the assistant's pick).
            ['put', '/template', 'template', 30, 'template'],
            // 1.62.0: what a session has cost (/cost), and the person's own commands (saved asks).
            ['get', '/sessions/{session}/usage', 'usage', 30, 'sessions.usage'],
            ['get', '/commands', 'commands', 60, 'commands'],
            ['put', '/commands', 'saveCommands', 30, 'commands.save'],
        ];

        foreach ($routes as [$verb, $path, $action, $limit, $name]) {
            $route = Route::{$verb}($p.$path, [$c, $action])->where($where)->middleware("throttle:{$limit},1")->name($names.$name);

            if ($subject !== null) {
                $route->defaults(self::SUBJECT, $subject);
            }
        }
    }
}
