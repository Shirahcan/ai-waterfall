<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * A named place a product's assistant works (the chat, a site builder, an email editor, a case brief).
 *
 * The BASE assistant (identity, who is asking, the product's knowledge, the rules true everywhere) is
 * composed by ai-service for every call that names a surface; a surface only ADDS its own skill: its
 * template (spec, output contract), its tools, its facts. Register it in `ai-client.assistant.surfaces`
 * and `php artisan ai:assistant-sync` lists it on the assistant's record, where the console shows it.
 *
 * Modes: `conversation` (a person reads the assistant talking: its full voice), `grounded` (it writes
 * content or JSON a person reads: identity, knowledge and rules; its voice only in the words it says to
 * the person), `silent` (extractions, classifiers, vectors: nothing added).
 */
interface Surface
{
    public const CONVERSATION = 'conversation';

    public const GROUNDED = 'grounded';

    public const SILENT = 'silent';

    /** Lowercase, stable: the key the service, the console and the UI know it by (`site_builder`). */
    public function key(): string;

    /** One of the three modes above. */
    public function mode(): string;

    /** How the assistant names the place to itself ("the site builder"). */
    public function label(): string;

    /** One or two sentences: what the assistant does here. Shown on the console too. */
    public function description(): string;
}
