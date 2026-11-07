<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

/** The default: `ai-client.dictation.enabled` and `ai-client.dictation.daily_minutes`. */
final class ConfigDictationSettings implements DictationSettings
{
    public function enabled(): bool
    {
        return (bool) config('ai-client.dictation.enabled', false);
    }

    public function dailyMinutes(): int
    {
        $minutes = config('ai-client.dictation.daily_minutes', DictationTranscriber::DEFAULT_DAILY_MINUTES);

        return max(0, (int) (is_numeric($minutes) ? $minutes : DictationTranscriber::DEFAULT_DAILY_MINUTES));
    }
}
