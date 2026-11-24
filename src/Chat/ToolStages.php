<?php

namespace Shirahcan\AiClient\Chat;

/**
 * The status line a chat panel shows while a SHARED tool runs: ai-service's built-in tools and the kit's data tools.
 *
 * ⚠ ONE COPY, NOT ONE PER PRODUCT. These labels lived in Portify's `EngineChatTurn::STAGES` (2026-11-24), so every other
 * product on the shared stack would have copied them and the copies would drift, the way the case-title chain did.
 * A product keeps only the labels of its OWN tools and merges these under them.
 *
 * The assistant is speaking, so a label is in the first person ("Checking my answer"), never "Porter is checking".
 */
final class ToolStages
{
    /** Shown while a tool with no label of its own runs. */
    public const FALLBACK = 'Looking that up';

    /** @var array<string, string> */
    public const DEFAULTS = [
        // ai-service's built-in tools.
        'search_knowledge'       => 'Checking what I know',
        'recall_memory'          => 'Remembering what you told me',
        'remember'               => 'Noting that for next time',
        'read_this_conversation' => 'Reading back through our conversation',
        'review_conversation'    => 'Reading my earlier answers',
        'report_gap'             => 'Noting what I could not do',
        'check_answer'           => 'Checking my answer',
        // The kit's data tools (DataTools).
        'search_data_schema'     => 'Working out where to look',
        'describe_data_entity'   => 'Checking what I can read',
        'list_data_values'       => 'Checking the possible values',
        'run_data_query'         => 'Looking up your records',
        'search_records'         => 'Finding it',
    ];

    /**
     * The shared labels with the product's own on top (a product may also re-word a shared one).
     *
     * @param  array<string, string>  $productLabels
     * @return array<string, string>
     */
    public static function merge(array $productLabels): array
    {
        return array_merge(self::DEFAULTS, $productLabels);
    }

    /** The label for one tool, from a merged map, or the fallback. */
    public static function label(string $tool, ?array $labels = null): string
    {
        return ($labels ?? self::DEFAULTS)[$tool] ?? self::FALLBACK;
    }
}
