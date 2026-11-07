<?php

namespace Shirahcan\AiClient\Laravel\Conversations\Threads;

/** One of the person's conversations, as the list shows it. The ai-service id stays on the server. */
final class ThreadRow
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $audience,
        public readonly ?string $lastMessageAt,
        public readonly ?string $createdAt,
        public readonly ?string $serviceId,
    ) {}

    /** @return array<string, mixed> the wire shape (ai-ui `AssistantThreadRow`) */
    public function toArray(bool $pinned): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'audience' => $this->audience,
            'last_message_at' => $this->lastMessageAt,
            'created_at' => $this->createdAt,
            'pinned' => $pinned,
        ];
    }
}
