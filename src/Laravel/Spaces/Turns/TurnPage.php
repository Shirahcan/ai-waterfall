<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Turns;

/**
 * Where the person is when they ask (1.61): the page the shared panel (ai-ui `AssistantSpacePanel`) sends
 * with every turn, as `page_context` and `client_timezone`.
 *
 * ⚠ THE KIT READS IT FOR EVERY PRODUCT, BECAUSE ONE PRODUCT DROPPED IT. Mira's runner validated only the
 * message, so `validated()` threw the page away and her engine was told every page was "/": she told an
 * admin new candidates "appear on your dashboard at /." (MployNow, 2026-11-09). The kit's turn route now
 * validates these fields itself and hands them to every runner's `run()` in `$data`, and a runner reads
 * them through {@see self::from()}, so a product can no longer lose the page by forgetting a rule.
 */
final class TurnPage
{
    /** The panel's fields, validated by the kit's turn route before any runner sees the turn. */
    public const RULES = [
        'page_context' => ['nullable', 'array'],
        'page_context.route' => ['nullable', 'string', 'max:255'],
        'page_context.entity_type' => ['nullable', 'string', 'max:40'],
        'page_context.entity_id' => ['nullable', 'string', 'max:64'],
        'client_timezone' => ['nullable', 'string', 'max:64'],
    ];

    public function __construct(
        /** The path of the page the person is on, e.g. "/admin/dashboard". "/" only when the panel sent none. */
        public readonly string $route = '/',
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        /** The browser's zone when it is a real one (owner 2026-11-01: every time in the browser's zone). */
        public readonly ?string $timezone = null,
    ) {}

    /**
     * The page, from a turn's data as the kit hands it to `run()`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        $page = is_array($data['page_context'] ?? null) ? $data['page_context'] : [];
        $route = trim((string) ($page['route'] ?? ''));
        $zone = $data['client_timezone'] ?? null;

        return new self(
            // A path, never a full URL or a query string: the model repeats what it is given.
            route: str_starts_with($route, '/') ? ((string) strtok($route, '?#') ?: '/') : '/',
            entityType: self::text($page['entity_type'] ?? null),
            entityId: self::text($page['entity_id'] ?? null),
            timezone: is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : null,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
