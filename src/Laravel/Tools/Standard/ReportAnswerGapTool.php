<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/**
 * The shared `report_answer_gap`: the model says, in a typed field, that it could not answer (or
 * could answer only part of) what the person asked. It returns an `answer_gap` card the product
 * reads off the turn ({@see AnswerGap}) and never renders, so the miss reaches the product's
 * knowledge-gap backlog.
 *
 * ⚠ IT EXISTS BECAUSE "DID A TOOL RUN" IS NOT "WAS IT ANSWERED". Measured on Portify 2026-10-30:
 * once chat ran in the engine, a turn that called any tool was recorded as grounded, so "I couldn't
 * find a breakdown by role" was filed as a good answer and the gap miner found nothing for days.
 * Only the model knows it fell short, so it has to say so where code can read it.
 */
final class ReportAnswerGapTool implements StandardTool
{
    public const NAME = 'report_answer_gap';

    public const CARD = 'answer_gap';

    public const NONE = 'none';

    public const PARTIAL = 'partial';

    public const MAX_MISSING = 300;

    public function name(): string
    {
        return self::NAME;
    }

    public function definitionFor(string $audience): ?array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Call this BEFORE your reply whenever you cannot fully answer the question: the data, the feature '
                .'or the knowledge is not available to you, or you can give only part of what was asked (for example a total '
                .'but not the breakdown they wanted). It records the gap so it gets fixed; the user does not see it. '
                .'Do not call it for a question you answered in full, or for a request you declined on purpose.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'coverage' => ['type' => 'string', 'enum' => [self::NONE, self::PARTIAL],
                    'description' => '"none" when you could not answer at all, "partial" when you answered only part of it.'],
                'missing'  => ['type' => 'string',
                    'description' => 'What you could not provide, in a few words, e.g. "user counts broken down by role".'],
            ], 'required' => ['coverage', 'missing']],
        ];
    }

    public function run(array $input, ToolActor $actor): array
    {
        $coverage = ($input['coverage'] ?? null) === self::PARTIAL ? self::PARTIAL : self::NONE;
        $missing = mb_substr(trim((string) ($input['missing'] ?? '')), 0, self::MAX_MISSING);

        return [
            'ok'     => true,
            'result' => ['recorded' => true,
                'say' => 'Recorded. Now answer what you can and say plainly, in one sentence, what you could not provide. '
                    .'Do not mention that anything was recorded.'],
            'card'   => ['type' => self::CARD, 'payload' => ['coverage' => $coverage, 'missing' => $missing]],
        ];
    }
}
