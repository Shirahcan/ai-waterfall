<?php

namespace Shirahcan\AiClient\Laravel\Console;

use Illuminate\Http\Request;

/**
 * Who made a console change, as the audit trail records it.
 *
 * Defaults to the signed-in user's email. A product whose user model names people differently
 * sets `ai-client.console.actor` to an invokable class: `fn (Request $r): string`.
 */
final class ConsoleActor
{
    public static function name(Request $request): string
    {
        $resolver = config('ai-client.console.actor');
        if (is_string($resolver) && $resolver !== '' && class_exists($resolver)) {
            return mb_substr((string) app($resolver)($request), 0, 64);
        }

        $user = $request->user();
        $name = $user->email ?? $user->name ?? ($user?->getAuthIdentifier() !== null ? 'user '.$user->getAuthIdentifier() : 'unknown');

        // The narrowest actor field upstream is a persona's `author` (64).
        return mb_substr((string) $name, 0, 64);
    }
}
