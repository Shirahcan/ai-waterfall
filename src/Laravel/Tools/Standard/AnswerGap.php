<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

/**
 * Reads the `answer_gap` card {@see ReportAnswerGapTool} leaves on a turn, and removes it from the
 * cards a product renders.
 */
final class AnswerGap
{
    /**
     * The gap the model reported on this turn, or null when it answered in full.
     *
     * @param  list<array{type: string, payload: array<string, mixed>}>  $cards
     * @return array{coverage: string, missing: string}|null
     */
    public static function find(array $cards): ?array
    {
        $found = null;
        foreach ($cards as $card) {
            if (($card['type'] ?? null) !== ReportAnswerGapTool::CARD) {
                continue;
            }
            $payload = (array) ($card['payload'] ?? []);
            $coverage = ($payload['coverage'] ?? null) === ReportAnswerGapTool::PARTIAL
                ? ReportAnswerGapTool::PARTIAL : ReportAnswerGapTool::NONE;
            // "none" outranks "partial" when the model reported twice.
            if ($found === null || $coverage === ReportAnswerGapTool::NONE) {
                $found = ['coverage' => $coverage, 'missing' => (string) ($payload['missing'] ?? '')];
            }
        }

        return $found;
    }

    /**
     * The cards without the gap card, for the product to render.
     *
     * @param  list<array{type: string, payload: array<string, mixed>}>  $cards
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public static function strip(array $cards): array
    {
        return array_values(array_filter($cards, static fn ($c) => ($c['type'] ?? null) !== ReportAnswerGapTool::CARD));
    }
}
