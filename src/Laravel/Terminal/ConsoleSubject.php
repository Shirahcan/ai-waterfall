<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * Everything a console needs from what it works on (1.71.0, shared console C3): the subject's ref, label and
 * sizing ({@see TerminalSubject}), plus how its changes are described. A host that offers guides and templates
 * also implements {@see TemplatedSubject}.
 *
 * ⚠ A REF IS `{kind}:{id}` AND NOTHING KEYS ON THE DOCUMENT ALONE, so one document can be several subjects (a post
 * and its share card, `growth.post:card`), each with its own sessions, rewind points and changes. New kinds are
 * namespaced by their owner (`growth.post`, `site.page`); existing refs keep their names, because live sessions
 * and briefs are keyed by them.
 */
interface ConsoleSubject extends TerminalSubject
{
    /** The describer for this subject's document language. */
    public function changes(string $ref): ChangeDescriber;
}
