<?php

namespace Shirahcan\AiClient\Assistant;

/**
 * One thing an assistant may do in a product, and who may do it.
 *
 * ⚠ THE TOOL RUNS IN THE PRODUCT'S PROCESS, NEVER IN ai-service, AND THAT IS
 * THE CENTRAL SECURITY DECISION OF THIS WHOLE DESIGN. Letting the service drive
 * the loop and call back into each product is the tidier diagram and the wrong
 * trade: it would give the box that holds every AI provider key in the estate
 * the ability to act AS A USER inside every product, taking the blast radius of
 * a compromise from "the AI keys" to "every product, as anybody". This estate
 * has already had a root compromise (2026-04-19 to 07-17). It would also give
 * each product's permission model two enforcers, and two enforcers of one rule
 * is the duplicate-derivation defect with an authorisation bypass attached.
 *
 * So the service decides WHAT to do; the product decides whether this viewer may
 * do it, and then does it.
 *
 * ⚠ `authorize()` IS SEPARATE FROM `run()` AND IS CALLED ON EVERY STEP, NOT
 * ONCE PER TURN. A tool loop can revisit the same tool with different
 * arguments - "now read case 9f3e" after "read case 1a2b" - and a check made
 * once against the first arguments is not a check at all. It also takes the
 * ARGUMENTS, because almost every real rule here is about them: this viewer may
 * read THEIR cases, not any case.
 *
 * ⚠ `run()` MUST NOT RE-CHECK PERMISSION INSTEAD OF `authorize()`. Both is fine;
 * only in `run()` is not, because the harness tells the model "you may not do
 * that" from `authorize()` alone - a refusal raised inside `run()` arrives as a
 * tool error, which the model will cheerfully try to work around.
 */
interface ToolContract
{
    /**
     * The stable, snake_case name the model will use.
     *
     * ⚠ IT IS PART OF THE PROMPT, SO RENAMING ONE IS A BEHAVIOUR CHANGE, not a
     * refactor: a conversation mid-flight, a cached plan, or a stored transcript
     * can still name the old one.
     */
    public function name(): string;

    /**
     * What this tool does, written FOR THE MODEL.
     *
     * ⚠ One sentence, in the product's own nouns, saying what it returns and
     * when to reach for it. This is the entire basis on which the model decides;
     * a vague description produces a tool that is either never used or used for
     * everything.
     */
    public function description(): string;

    /**
     * JSON Schema for the arguments.
     *
     * ⚠ NAME EVERY REQUIRED ARGUMENT AND DESCRIBE IT. An argument the model has
     * to guess the meaning of is an argument it will guess wrong, and the
     * failure arrives as a plausible-looking call to a real tool.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * May THIS viewer run this tool with THESE arguments, right now?
     *
     * ⚠ FALSE IS NOT AN ERROR, IT IS AN ANSWER. The harness tells the model the
     * tool was refused and lets it answer without it, which is what makes an
     * assistant usable by a client and a consultant from one catalogue.
     *
     * @param  mixed  $viewer  whatever the product authenticates with
     * @param  array<string, mixed>  $args
     */
    public function authorize(mixed $viewer, array $args): bool;

    /**
     * Do it, and return something JSON-serialisable.
     *
     * ⚠ RETURN DATA, NOT PROSE. The result goes back to the model as the next
     * step's evidence; a sentence invites it to quote your sentence, while rows
     * invite it to answer from them. Keep it small: every result is re-sent on
     * every later step of the same turn.
     *
     * @param  array<string, mixed>  $args
     */
    public function run(array $args): mixed;
}
