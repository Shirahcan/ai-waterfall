<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Throwable;

/**
 * The shared `find_record`: "tell me everything about Maria Garcia", "who is Acme Ltd", "find the
 * Garcia file". The model passes the name as written; the product's {@see RecordFinder} searches its
 * own data in the actor's scope; this shapes the result the same way for every product.
 *
 * ⚠ WHY IT IS A STANDARD TOOL. Portify's assistant told an admin a listed user did not exist
 * (2026-10-30): its data tools answer questions about SETS of records and it had searched FIELD
 * NAMES for her. Every product has people and records a user names; none should ship without a way
 * to look one up, so the lookup and its wording rules live here once.
 *
 * ⚠ EVERY RECORD COMES WITH A READY-MADE MARKDOWN LINK. Handed bare urls, the model wrote the names
 * as plain text; handed `[Name](/url)` it links reliably.
 */
final class FindRecordTool implements StandardTool
{
    public const NAME = 'find_record';

    public const MAX_QUERY = 120;

    public function __construct(private RecordFinder $finder) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definitionFor(string $audience): ?array
    {
        // A visitor with no account can look nobody up.
        if ($audience === 'public' || $audience === 'guest' || ! $this->finder->offersLookupTo($audience)) {
            return null;
        }

        return [
            'name'        => self::NAME,
            'description' => 'Find a specific person, business or record by the name, email, title or reference the user gives, '
                .'and read it: who they are, their account state and their related records, each with its link. Use it for '
                .'"tell me about Maria Garcia", "who is X", "find the Garcia file". Search with the name as given before ever '
                .'saying someone does not exist. Results are limited to what this user may see. Every record comes with a '
                .'ready-made markdown `link`: write the record AS that link.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'The name, email, title or reference exactly as the user wrote it.'],
            ], 'required' => ['query']],
        ];
    }

    public function run(array $input, ToolActor $actor): array
    {
        if ($actor->isGuest()) {
            return ['ok' => false, 'error' => 'Looking people up is not available to a visitor.'];
        }

        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY) {
            return ['ok' => false, 'error' => 'Give the name, email or reference to look up (under 120 characters).'];
        }

        try {
            $found = $this->finder->find($query, $actor);
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // Outside a Laravel app there is nowhere to report to; the error below still goes back.
            }

            return ['ok' => false, 'error' => 'The lookup failed. Say you could not look that up right now; never say the record does not exist.'];
        }

        $matches = self::linked($found['matches'] ?? []);
        $profiles = self::linked($found['profiles'] ?? []);

        if ($matches === [] && $profiles === []) {
            return ['ok' => true, 'result' => ['found' => false,
                'say' => "Nothing this user can see matches \"{$query}\". Say so plainly and ask for another detail (an email, "
                    .'a case title); never claim the person does not exist anywhere.']];
        }

        return ['ok' => true, 'result' => ['found' => true, 'matches' => $matches, 'profiles' => $profiles,
            'say' => 'Name each record by its `link`, and state only what these rows show. Where a list is marked as a window '
                .'of a longer one, say so.']];
    }

    /**
     * Adds `link` => "[title](url)" to every array carrying a url (or a person's `page`), at any depth.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int|string, mixed>
     */
    private static function linked(array $rows): array
    {
        foreach ($rows as $k => $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = $row['url'] ?? $row['page'] ?? null;
            $label = $row['title'] ?? $row['name'] ?? null;
            if (is_string($url) && $url !== '' && is_string($label) && $label !== '' && ! isset($row['link'])) {
                $row['link'] = '['.str_replace(['[', ']'], ['(', ')'], $label).']('.$url.')';
            }
            $rows[$k] = self::linked($row);
        }

        return $rows;
    }
}
