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
    ) {}

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
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
