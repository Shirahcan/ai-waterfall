<?php

namespace Shirahcan\AiClient;

/**
 * How this product's assistant speaks: read it, publish a new version, roll back.
 *
 * ⚠ THIS PRODUCT'S VOICES, AND ONLY THIS PRODUCT'S. The service derives the
 * product from the trust key, never from the request, so there is deliberately
 * no `$product` argument here - it would be a lie. Portify cannot read, let
 * alone rewrite, the finance assistant's voice.
 *
 * ⚠ AND THIS IS WHAT MAKES STORING A PERSONA IN THE SERVICE LEGITIMATE. Moving
 * a voice out of code and into a service nobody can edit would make it LESS
 * manageable than the prompt it replaced - a regression dressed as
 * consolidation. These methods are how a product's own admin screen reaches it;
 * the service is loopback-only and can never serve a browser itself.
 */
class AiPersonas
{
    public function __construct(private AiClient $client) {}

    /**
     * Every voice this product owns, newest version first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return (array) ($this->client->getJson('api/v1/personas')['personas'] ?? []);
    }

    /**
     * Publish a NEW VERSION of a voice and make it live.
     *
     * ⚠ A NEW VERSION, NOT AN EDIT. A persona change alters what every answer
     * from this product sounds like, and "it got worse last Tuesday" is
     * unanswerable without the previous text. A voice is tuned by trial, so
     * every save stays recoverable and `rollBackTo()` is a real option.
     *
     * @param  string  $key  Which assistant, e.g. `porter`. A product may have
     *   more than one: a public-site voice is not the in-app voice.
     * @param  string|null  $audience  Null means every audience this key serves.
     *   ⚠ Set it when the voice genuinely differs by role. Role-blind persona is
     *   the recurring defect: a practising consultant was once told "I'm
     *   software, not a person, and not your consultant" - correct for a client,
     *   wrong for the professional who IS the consultant.
     *
     * @return array<string, mixed>
     */
    public function publish(
        string $key,
        string $label,
        string $voice,
        ?string $audience = null,
        ?string $author = null,
    ): array {
        return (array) ($this->client->postJson('api/v1/personas', array_filter([
            'key'      => $key,
            'label'    => $label,
            'voice'    => $voice,
            'audience' => $audience,
            'author'   => $author,
        ], fn ($v) => $v !== null))['persona'] ?? []);
    }

    /**
     * Make an earlier version live again.
     *
     * The service records this as a new version rather than resurrecting the
     * old row, so the text you rolled away from stays readable - "what did we
     * try that was worse" is usually the next question.
     *
     * @return array<string, mixed>
     */
    public function rollBackTo(int $id, ?string $author = null): array
    {
        return (array) ($this->client->postJson(
            "api/v1/personas/{$id}/activate",
            array_filter(['author' => $author], fn ($v) => $v !== null),
        )['persona'] ?? []);
    }

    /**
     * Stop using a voice.
     *
     * ⚠ The assistant does NOT stop working: the service falls back to the
     * product's configured suffix, so the cost of switching a voice off is a
     * plainer answer rather than a refused one.
     */
    public function deactivate(int $id): bool
    {
        return (bool) ($this->client->deleteJson("api/v1/personas/{$id}")['deactivated'] ?? false);
    }
}
