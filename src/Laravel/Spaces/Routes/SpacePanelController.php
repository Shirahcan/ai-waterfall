<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Routes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The panel's own state in the space (1.57): the conversation to reopen, the up-arrow ring of past
 * questions, a vote on an answer, and what became of a confirm card. Each was a separate per-product
 * route before (Portify's active-conversation, input-history, feedback and card-state).
 */
final class SpacePanelController extends SpaceController
{
    /**
     * The reasons a thumbs-down may carry: ai-service's `TurnFeedbackController::REASONS`, the same five
     * ai-ui's rating control offers (`ASSISTANT_FEEDBACK_REASONS`). The service refuses any other.
     */
    private const REASONS = ['wrong_topic', 'outdated_info', 'didnt_understand', 'missing_detail', 'unhelpful_other'];

    public function active(Request $request): JsonResponse
    {
        return $this->inSpace($request, fn (string $space) => [
            'conversation_id' => $this->ai->spaces()->state($space)['active_conversation_id'],
        ]);
    }

    /** Remember (or, with null, forget) which conversation the panel reopens. */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate(['conversation_id' => ['present', 'nullable', 'uuid']]);

        return $this->inSpace($request, fn (string $space) => [
            'conversation_id' => $this->ai->spaces()->reopenNext($space, $data['conversation_id'])['active_conversation_id'],
        ]);
    }

    /**
     * Suggested questions for the screen the person is on, from the product's knowledge for their role.
     * A failure is an empty list: suggestions are a convenience, never worth an error at someone.
     */
    public function prompts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);

        return $this->inSpace($request, function (string $space) use ($data) {
            try {
                return ['prompts' => $this->ai->spaces()->prompts($space, $data['route'] ?? null, (int) ($data['limit'] ?? 4))];
            } catch (\Throwable $e) {
                report($e);

                return ['prompts' => []];
            }
        });
    }

    /** The person's own questions in this space, newest first, once each. */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->inSpace($request, fn (string $space) => [
            'history' => $this->ai->spaces()->inputHistory($space, (int) ($data['limit'] ?? 50)),
        ]);
    }

    /** A thumbs up or down on one answer; the voter is the space's owner (set by ai-service). */
    public function feedback(Request $request, string $id, string $turn): JsonResponse
    {
        $data = $request->validate([
            'sentiment' => ['required', Rule::in(['positive', 'negative'])],
            'reason_code' => ['nullable', 'prohibited_if:sentiment,positive', Rule::in(self::REASONS)],
            'reason_text' => ['nullable', 'string', 'max:500'],
            'client_ref' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->inSpace($request, function (string $space) use ($id, $turn, $data) {
            $this->ai->spaces()->vote($space, $id, $turn, array_filter($data, fn ($v) => $v !== null));

            return ['recorded' => true];
        });
    }

    /** What became of a confirm card (done, dismissed...), so a reload shows the same outcome. */
    public function cardState(Request $request, string $id, string $turn): JsonResponse
    {
        $data = $request->validate(['state' => ['present', 'nullable', 'array']]);

        return $this->inSpace($request, fn (string $space) => [
            'state' => $this->ai->spaces()->turnState($space, $id, $turn, $data['state'])['turn']['state'] ?? null,
        ]);
    }
}
