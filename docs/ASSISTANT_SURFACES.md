# One base assistant, many surfaces (ai-client 1.56)

Owner, 2026-11-08: "the vibe-coding Porter should be an extension of the Porter in the chat - as a
general rule: that should be the base Porter everywhere (persona + voice + full knowledge of the
platform + etc) so Porter doesn't sound different in places" and "This will also apply to other
assistants".

## The shape

| Piece | Where it lives | Who edits it |
|---|---|---|
| The assistant (name, product name, avatar, greetings, voice key, surfaces) | ai-service `ai_assistants`, read by name | the product's admins on the AI console; created once by `ai:assistant-sync` |
| Identity per audience (who it is, what the product is and is NOT) | ai-service persona rows, key `identity` | admins, versioned with rollback |
| Voice per audience, house rules | persona rows (the voice key, `house`) | admins, as today |
| Knowledge | the product's corpus, synced to ai-service | the product's repo (`knowledge-sync`) |
| Rules true everywhere (never invent, house style, plain words) | ai-service `AssistantFrame` | ai-service code |
| A surface's skill (spec, output contract, facts) | the product's task template for that surface | the product's repo / console |

ai-service composes identity + who is asking + knowledge for the ask + the rules (+ the voice) ahead of
the surface's template. A call that names no surface is unchanged. The chat uses the same identity and
rules (`ChatTurnFrame`), so a question asked in the chat and in a builder gets the same assistant.

## Adopting it in a product

1. `.env`: `AI_ASSISTANT=porter` (and, once, `AI_ASSISTANT_NAME=Porter`, `AI_ASSISTANT_PRODUCT_NAME=Portify`,
   `AI_ASSISTANT_VOICE=porter`).
2. A class per surface implementing `Shirahcan\AiClient\Assistant\Surface` (`key`, `mode`, `label`,
   `description`), listed in `config('ai-client.assistant.surfaces')`. Modes: `conversation` (full voice),
   `grounded` (content or JSON a person reads; the voice only for the words said to the person),
   `silent` (extractions; nothing added, no block sent).
3. `php artisan ai:assistant-sync` after each deploy: creates the record once, refreshes the surfaces,
   never overwrites an admin's edit.
4. Publish the identity rows (`$ai->personas()->publish('identity', $audience, ...)`) from a product
   command once; admins own them after that.
5. Call through the surface: `$ai->surface($builderSurface, $audience, $theAsk)->runTask('sites.pml.edit', $vars)`.
   The response carries `frame` (assistant, mode, identity version, knowledge slugs) for the call log.
6. Remove the template's own "You are X" preamble and restated rules: only the surface's skill stays.
7. UI: `@shirahcan/ai-ui` `<AssistantSurface>` renders the ONE shell (the chat panel is one more use of
   it); the record's name, avatar and greeting are data, colours are the product's theme. The UI is never
   differentiated per assistant.

## Tests

`FakeAiClient::assistantCall()` returns the block the last call ran on (through clones);
`publishAssistant()` / `assistantRecord()` work against the fake's in-memory record.

## Products

| Product | Assistant | Status |
|---|---|---|
| Portify | Porter | adopting first (site builder) |
| MployNow | Mira | designed; adopt after Portify (owner 2026-11-02 pause) |
| Finance | Reeve | designed; adopt later |
| Studendly | its assistant | designed; adopt later |
