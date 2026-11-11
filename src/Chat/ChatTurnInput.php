<?php

namespace Shirahcan\AiClient\Chat;

/**
 * One chat turn a product hands to ai-service's chat engine.
 *
 * ⚠ IDS ARE OPAQUE TO THE SERVICE. `memoryRef` and `toolToken` go over the wire
 * and come back untouched; the service never reads a product's user ids. The
 * product decides, from its own token, who is asking and what they may see.
 */
final class ChatTurnInput
{
    /**
     * @param  list<array{mime: string, filename: string, data_base64: string}>  $attachments
     *         files on THIS turn (PDF, PNG, JPEG, WebP). A turn carrying any is sent as sensitive:
     *         only credentials cleared for client documents read it.
     */
    public function __construct(
        public readonly string $message,
        public readonly string $audience,
        public readonly string $pageRoute = '/',
        public readonly ?string $knowledgeAudience = null,
        public readonly ?string $userName = null,
        public readonly ?string $timezone = null,
        public readonly ?string $memoryRef = null,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?string $pageSummary = null,
        public readonly ?string $persona = null,
        public readonly ?string $toolToken = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $routeKey = null,
        public readonly array $attachments = [],
        // The product's gap-report gate (1.40): ai-service records what this turn could not do and
        // offers the model report_gap. Off unless the product turns it on.
        public readonly bool $reportGaps = false,
        // The product's self-review gate (1.41): an ADMIN's assistant is offered review_conversation, to read
        // its own product's past turns and what their tools did. ai-service refuses it for anyone else.
        public readonly bool $selfReview = false,
        // Which history the model reads (1.63): `window` (ai-service's latest messages, the default) or
        // `thread` (the WHOLE thread to a budget, older turns folded into a running summary after the answer).
        // The product's own gate chooses; an older ai-service ignores it.
        public readonly string $context = self::CONTEXT_WINDOW,
    ) {}

    public const CONTEXT_WINDOW = 'window';

    public const CONTEXT_THREAD = 'thread';

    /** @return array<string, mixed> the `POST /v1/chat/turns` body */
    public function toWire(): array
    {
        $drop = static fn ($v) => $v !== null && $v !== '';

        return array_filter([
            'message'         => $this->message,
            'conversation_id' => $this->conversationId,
            'user'            => array_filter([
                'audience'           => $this->audience,
                'knowledge_audience' => $this->knowledgeAudience,
                'name'               => $this->userName,
                'timezone'           => $this->timezone,
                'memory_ref'         => $this->memoryRef,
            ], $drop),
            'page'            => array_filter([
                'route'       => $this->pageRoute,
                'entity_type' => $this->entityType,
                'entity_id'   => $this->entityId,
                'summary'     => $this->pageSummary,
            ], $drop),
            'persona'         => $this->persona,
            'tool_token'      => $this->toolToken,
            'route_key'       => $this->routeKey,
            'attachments'     => array_values($this->attachments),
            'report_gaps'     => $this->reportGaps ? true : null,
            'self_review'     => $this->selfReview ? true : null,
            'context'         => $this->context === self::CONTEXT_THREAD ? self::CONTEXT_THREAD : null,
        ],static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
