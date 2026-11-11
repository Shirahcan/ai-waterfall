<?php

namespace Shirahcan\AiClient\Laravel\Console;

use Illuminate\Support\Facades\Route;

/**
 * Site management's AI console, mounted by a product in ONE line inside its admin auth group:
 *
 *   Route::middleware(['auth:api', 'can:manage-ai'])->group(fn () => ConsoleRoutes::mount('admin/ai-console'));
 *
 * The browser (`@shirahcan/ai-ui/console`) calls `{prefix}/{path}`; the kit forwards only the
 * paths in {@see TABLE} to ai-service with the product's own key, and the estate rows only when
 * the product holds the admin key. The product writes no controller.
 *
 * ⚠ A WHITELIST, NEVER A PASS-THROUGH. ai-service also serves generation, transcripts and
 * memory; a proxy that forwarded "anything under /v1" would hand every signed-in admin a
 * door to read any conversation by id. Each row here is a decision.
 *
 * ⚠ THE ACTOR IS THE PRODUCT'S, NEVER THE BROWSER'S. Every write records who made it
 * (`by`, `updated_by`, `set_by`...); the kit overwrites that field with the signed-in user,
 * so a request body cannot claim to be somebody else.
 */
final class ConsoleRoutes
{
    /**
     * [method, browser path pattern, upstream path, admin key (true | false | PREFER_ADMIN), actor field or null].
     * `{x}` segments are copied to the upstream path by name.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool|string, 4: ?string}>
     */
    public const TABLE = [
        // This product: what it may do, its switch and cap.
        /*
         * ⚠ ASKED WITH THE ADMIN KEY WHEN THE PRODUCT HOLDS ONE. The service decides `is_admin`
         * from the key it is shown; asked with the product key, a product that does hold the
         * estate admin key would be told it does not, and its estate controls would never appear.
         */
        ['GET',    'capabilities',                        'api/v1/console/capabilities',  self::PREFER_ADMIN, null],
        ['GET',    'state',                               'api/v1/console/state',         false, null],
        ['POST',   'pause',                               'api/v1/console/pause',         false, 'by'],
        ['POST',   'resume',                              'api/v1/console/resume',        false, 'by'],
        // "Ask the assistant" beside a prompt or a voice: a suggestion, never a save (1.38).
        ['POST',   'assist',                              'api/v1/console/assist',        false, null],
        ['GET',    'credentials/status',                  'api/v1/credentials/status',    false, null],

        // Usage and trends (filterable by product; the estate total is visible to every product).
        ['GET',    'usage',                               'api/v1/usage',                 false, null],
        ['GET',    'usage/calls',                         'api/v1/usage/calls',           false, null],

        // Prompt templates (own; shared ones read-only here).
        ['GET',    'tasks',                               'api/v1/tasks',                 false, null],
        ['GET',    'tasks/{key}',                         'api/v1/tasks/{key}',           false, null],
        ['PATCH',  'tasks/{key}',                         'api/v1/tasks/{key}',           false, 'updated_by'],
        ['POST',   'tasks/{key}/versions/{version}/restore', 'api/v1/tasks/{key}/versions/{version}/restore', false, 'updated_by'],
        // Back to the template file's words (undo every console edit) (1.38).
        ['POST',   'tasks/{key}/revert',                  'api/v1/tasks/{key}/revert',    false, 'updated_by'],

        // Voice.
        ['GET',    'personas',                            'api/v1/personas',              false, null],
        ['POST',   'personas',                            'api/v1/personas',              false, 'author'],
        ['POST',   'personas/{id}/activate',              'api/v1/personas/{id}/activate', false, null],
        ['DELETE', 'personas/{id}',                       'api/v1/personas/{id}',         false, null],
        // The assistant itself (1.56): name, look, voice key, surfaces; its identity rows are personas above.
        ['GET',    'assistants',                          'api/v1/assistants',            false, null],
        ['GET',    'assistants/{key}',                    'api/v1/assistants/{key}',      false, null],
        ['PUT',    'assistants/{key}',                    'api/v1/assistants/{key}',      false, 'updated_by'],
        // Specializations (1.64): named guides for one kind of output; the vocabulary is the product's own
        // (published by `ai:specializations-sync`, read-only here); a draft is a suggestion, never a save.
        ['GET',    'specializations',                     'api/v1/specializations',       false, null],
        ['POST',   'specializations/draft',               'api/v1/specializations/draft', false, null],
        ['GET',    'specializations/{key}',               'api/v1/specializations/{key}', false, null],
        ['PUT',    'specializations/{key}',               'api/v1/specializations/{key}', false, 'updated_by'],
        ['DELETE', 'specializations/{key}',               'api/v1/specializations/{key}', false, null],
        ['GET',    'specializations-vocabulary',          'api/v1/specializations-vocabulary', false, null],

        // Knowledge (read-only: the product's files are the authority) and feedback.
        ['GET',    'knowledge',                           'api/v1/knowledge/entries',     false, null],
        ['GET',    'knowledge/{slug}',                    'api/v1/knowledge/entries/{slug}', false, null],
        ['GET',    'feedback',                            'api/v1/conversations/feedback', false, null],
        ['GET',    'feedback/stats',                      'api/v1/conversations/feedback/stats', false, null],
        // What the assistant could not do, triaged by admins (1.40).
        ['GET',    'gaps',                                'api/v1/gaps',                  false, null],
        ['GET',    'gaps/stats',                          'api/v1/gaps/stats',            false, null],
        ['PATCH',  'gaps/{id}',                           'api/v1/gaps/{id}',             false, 'by'],
        ['GET',    'conversations/stats',                 'api/v1/conversations/stats',   false, null],

        // Estate-wide (admin key only).
        ['GET',    'estate',                              'api/v1/admin/estate',          true, null],
        // The products the picker offers (1.38).
        ['GET',    'products',                            'api/v1/admin/products',        true, null],
        ['PATCH',  'shared-tasks/{key}',                  'api/v1/admin/shared-tasks/{key}', true, 'updated_by'],
        ['POST',   'shared-tasks/{key}/versions/{version}/restore', 'api/v1/admin/shared-tasks/{key}/versions/{version}/restore', true, 'updated_by'],
        ['GET',    'vault/credentials',                   'api/v1/admin/credentials',     true, null],
        ['POST',   'vault/credentials',                   'api/v1/admin/credentials',     true, null],
        ['PATCH',  'vault/credentials/{id}',              'api/v1/admin/credentials/{id}', true, null],
        ['POST',   'vault/credentials/{id}/sensitive',    'api/v1/admin/credentials/{id}/sensitive', true, 'decided_by'],
        ['GET',    'vault/budgets',                       'api/v1/admin/budgets',         true, null],
        ['PUT',    'vault/budgets',                       'api/v1/admin/budgets',         true, null],
        ['GET',    'vault/kill',                          'api/v1/admin/kill',            true, null],
        ['POST',   'vault/kill',                          'api/v1/admin/kill',            true, 'by'],
        ['POST',   'vault/kill/resume',                   'api/v1/admin/kill/resume',     true, 'by'],
        ['GET',    'vault/retention',                     'api/v1/admin/retention',       true, null],
        ['PUT',    'vault/retention',                     'api/v1/admin/retention',       true, 'set_by'],
        ['GET',    'vault/task-routes',                   'api/v1/admin/task-routes',     true, null],
        ['PUT',    'vault/task-routes',                   'api/v1/admin/task-routes',     true, 'set_by'],
        ['POST',   'vault/task-routes/reset',             'api/v1/admin/task-routes/reset', true, 'set_by'],
        ['GET',    'vault/rates',                         'api/v1/admin/rates',           true, null],
        ['POST',   'vault/rates',                         'api/v1/admin/rates',           true, 'set_by'],
        ['DELETE', 'vault/rates/{id}',                    'api/v1/admin/rates/{id}',      true, null],
        ['GET',    'vault/tool-hosts',                    'api/v1/admin/tool-hosts',      true, null],
        ['PUT',    'vault/tool-hosts',                    'api/v1/admin/tool-hosts',      true, 'set_by'],
        ['GET',    'vault/trust-keys',                    'api/v1/admin/trust-keys',      true, null],
        ['POST',   'vault/trust-keys',                    'api/v1/admin/trust-keys',      true, 'by'],
        ['DELETE', 'vault/trust-keys/{id}',               'api/v1/admin/trust-keys/{id}', true, null],
    ];

    /** The query parameter naming another product to act for (admin key only, 1.38). */
    public const AS_PRODUCT = 'as_product';

    /** The row uses the admin key when the product holds one, else the product key. */
    public const PREFER_ADMIN = 'prefer';

    /** Path segments a route parameter may hold: never a slash-walk or a query. */
    public const SEGMENT = '[A-Za-z0-9_.:-]{1,96}';

    public static function mount(string $prefix = 'ai-console'): void
    {
        Route::prefix(trim($prefix, '/'))->group(function () {
            foreach (self::TABLE as $i => [$method, $path]) {
                $params = [];
                preg_match_all('/\{(\w+)\}/', $path, $m);
                foreach ($m[1] as $name) {
                    $params[$name] = $name === 'slug' ? '[A-Za-z0-9_.:\/-]{1,160}' : self::SEGMENT;
                }

                Route::match([$method], $path, ConsoleProxyController::class)
                    ->where($params)
                    ->defaults('_ai_console_row', $i)
                    ->name('ai-console.'.$i);
            }
        });
    }

    /**
     * The upstream path for one table row and its route parameters.
     *
     * @param  array<string, string>  $params
     */
    public static function upstream(int $row, array $params): string
    {
        $path = self::TABLE[$row][2];
        foreach ($params as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }

        // A knowledge slug may contain slashes (`consultant/payments/instalments`): keep them as path.
        return str_replace('%2F', '/', $path);
    }
}
