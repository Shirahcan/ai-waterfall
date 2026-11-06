<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;
use Shirahcan\AiClient\Time\TimeWindowRefused;
use Shirahcan\AiClient\Time\TimeWindowResolver;
use Throwable;

/**
 * `platform_pulse`: what happened in ANY period across everything the asker may see, in one call
 * (Portify plan porter-stats-reach 03a). The same shape as `find_record`: the kit owns the tool, its
 * wording and the period grammar; each product supplies a {@see PulseProvider}.
 *
 * ⚠ IT EXISTS BECAUSE A DATA QUESTION ANSWERS ONE THING. "What happened in the last 4 days?" needs
 * twenty-odd counts across a dozen record types and several services; left to a model, the answer is
 * whichever five it thought of. The provider declares the lines, the kit resolves the period, and the
 * model only narrates figures it was handed.
 */
final class PulseTool implements StandardTool
{
    public const NAME = 'platform_pulse';

    private const RULES = 'Report these figures exactly as given and say the window\'s label. A line with could_not_read was NOT '
        .'read: say so and never treat it as zero. A line with as_of_now is the current state, not a count for the period. '
        .'Never add, subtract, average or estimate; a change is already computed. Lead with what moved most or matters most, '
        .'group by area, and keep it short.';

    public function __construct(private PulseProvider $provider, private ?TimeWindowResolver $resolver = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definitionFor(string $audience): ?array
    {
        if ($audience === 'public' || $audience === 'guest' || ! $this->provider->offersPulseTo($audience)) {
            return null;
        }

        $domains = $this->provider->domains($audience);

        return [
            'name' => self::NAME,
            'description' => 'What happened in a period across everything this user may see ('.implode(', ', $domains).'), counted '
                .'by the platform, with the change against the period before when asked. Use it for "what happened today", "stats for '
                .'the last 13 hours", "how did this week go", "fun stats", "give me a report", and for one person across every area '
                .'("fun stats for Ivie"). For one exact figure or a breakdown by a field, use the data query instead. Pass the '
                .'period EXACTLY as the user said it; never compute dates.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'window' => ['description' => 'The period as said: {"last": "25m"|"13h"|"4d"|"2w"|"3mo"}, {"calendar": "today"|'
                        .'"yesterday"|"this_week"|"last_week"|"this_month"|"last_month"|"this_quarter"|"this_year"}, '
                        .'{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"} or {"since": "YYYY-MM-DD"}. No period given: {"last": "7d"}, and say so.'],
                    'focus' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $domains],
                        'description' => 'Only these areas. Leave out for everything.'],
                    'person' => ['type' => 'string', 'description' => 'One person, as the user named them, for their figures across every area.'],
                    'compare' => ['type' => 'boolean', 'description' => 'Add the change against the period just before.'],
                ],
            ],
        ];
    }

    public function run(array $input, ToolActor $actor): array
    {
        if ($actor->isGuest() || ! $this->provider->offersPulseTo($actor->audience)) {
            return ['ok' => false, 'error' => 'The pulse is not available here.'];
        }

        $spec = $input['window'] ?? ['last' => '7d'];
        $defaulted = ! isset($input['window']);
        try {
            $window = ($this->resolver ?? new TimeWindowResolver())->resolve(
                is_array($spec) || is_string($spec) ? $spec : [],
                $actor->timezone ?? 'UTC',
                function_exists('now') ? now() : null,
            );
        } catch (TimeWindowRefused $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $known = $this->provider->domains($actor->audience);
        $focus = array_values(array_intersect(array_map('strval', (array) ($input['focus'] ?? [])), $known));
        $person = isset($input['person']) && trim((string) $input['person']) !== '' ? trim((string) $input['person']) : null;
        $compare = (bool) ($input['compare'] ?? false);

        try {
            $report = $this->provider->pulse($window, $actor, $focus, $person, $compare);
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // Outside a Laravel app there is nowhere to report to.
            }

            return ['ok' => false, 'error' => 'The pulse could not be read just now. Say so; never say nothing happened.'];
        }

        if ($report->candidates !== null) {
            return ['ok' => true, 'result' => [
                'person_found' => false,
                'candidates' => $report->candidates,
                'say' => $report->candidates === []
                    ? "Nobody this user can see matches \"{$person}\". Ask for another detail; never say they do not exist."
                    : 'Several people match. Ask which one, naming them; never pick one yourself.',
            ]];
        }

        $byDomain = [];
        foreach ($report->lines as $line) {
            $byDomain[$line->domain][] = $line->toArray();
        }

        return ['ok' => true, 'result' => array_filter([
            'window' => $window->toArray(),
            'window_note' => $defaulted ? 'No period was given, so this is the last 7 days: say so.' : null,
            'compared_with' => $compare ? $window->previous()->label() : null,
            'person' => $report->subject,
            'areas' => $byDomain,
            'notes' => $report->notes !== [] ? $report->notes : null,
            'say' => self::RULES,
        ], static fn ($v) => $v !== null)];
    }
}
