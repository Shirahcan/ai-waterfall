<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

/**
 * Whether dictation is on, and how many minutes a person may dictate a day: the two facts a product owns.
 *
 * The kit binds {@see ConfigDictationSettings} (config `ai-client.dictation.*`). A product whose switches are
 * DB-backed binds its own, so its admin can flip the gate and change the minutes without a deploy:
 *
 *   $this->app->singleton(DictationSettings::class, MyDictationSettings::class);
 */
interface DictationSettings
{
    /** Off, the route answers 404 and the microphone is not offered. */
    public function enabled(): bool;

    /** Minutes of speech per person per day. 0 means none. */
    public function dailyMinutes(): int;
}
