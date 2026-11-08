<?php

namespace Shirahcan\AiClient\Laravel\Spaces;

use Illuminate\Http\Request;

/** The fallback: each turn as ai-service holds it, with `content` for the words (1.57). */
final class NeutralSpaceMessages implements SpaceMessages
{
    public function present(Request $request, array $conversation, array $turns): array
    {
        return array_map(fn (array $t) => [
            'id' => (string) $t['id'],
            'ai_turn_id' => (string) $t['id'],
            'conversation_id' => (string) ($conversation['id'] ?? ''),
            'role' => (string) $t['role'],
            'content' => $t['body'] ?? null,
            'structured' => $t['structured'] ?? null,
            'card_state' => $t['state'] ?? null,
            'created_at' => $t['created_at'] ?? null,
        ], $turns);
    }
}
