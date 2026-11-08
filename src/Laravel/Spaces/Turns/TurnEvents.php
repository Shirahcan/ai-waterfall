<?php

namespace Shirahcan\AiClient\Laravel\Spaces\Turns;

/**
 * The events a turn streams (1.57): the vocabulary ai-ui's `useAssistantStream` parses and both
 * products already emitted by hand (`stage`, `token`, `reset`, `message`, `error`). One writer, so the
 * format and the flushing cannot drift between products.
 */
class TurnEvents
{
    public const STAGE = 'stage';

    public const TOKEN = 'token';

    public const RESET = 'reset';

    public const MESSAGE = 'message';

    public const ERROR = 'error';

    /** Words of the answer as they are written. */
    public function token(string $text): void
    {
        $this->emit(self::TOKEN, ['text' => $text]);
    }

    /** Throw away what was streamed so far (a tool call replaced the draft). */
    public function reset(): void
    {
        $this->emit(self::RESET, []);
    }

    /** What the assistant is doing now ("Looking at the case"). */
    public function stage(string $label, ?string $turnId = null): void
    {
        $this->emit(self::STAGE, $turnId === null ? ['label' => $label] : ['label' => $label, 'turn_id' => $turnId]);
    }

    /** @param array<string, mixed> $message */
    public function message(string $conversationId, array $message): void
    {
        $this->emit(self::MESSAGE, ['conversation_id' => $conversationId, 'message' => $message]);
    }

    public function error(string $message): void
    {
        $this->emit(self::ERROR, ['message' => $message]);
    }

    /** @param array<string, mixed> $data */
    protected function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }
}
