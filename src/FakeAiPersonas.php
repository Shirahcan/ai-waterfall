<?php

namespace Shirahcan\AiClient;

/**
 * The persona surface of {@see FakeAiClient}.
 *
 * ⚠ A REAL AiPersonas SUBCLASS, not a lookalike. Products type-hint
 * `AiPersonas`, so a separate class with the same methods would fail every one
 * of those signatures - and only inside the product, long after this package's
 * own tests had gone green.
 */
class FakeAiPersonas extends AiPersonas
{
    /** @param array<int, array<string, mixed>> $personas */
    public function __construct(private FakeAiClient $fake, private array $personas = [])
    {
        parent::__construct($fake);
    }

    public function all(): array
    {
        return $this->personas;
    }

    public function publish(
        string $key,
        string $label,
        string $voice,
        ?string $audience = null,
        ?string $author = null,
    ): array {
        /*
         * The fake VERSIONS too, because the versioning is the part a product
         * is most likely to get wrong: a screen that assumes publish() edits in
         * place would show the wrong history, and a fake that returned version
         * 1 every time would let that ship.
         */
        $version = 1 + count(array_filter(
            $this->personas,
            fn (array $p) => ($p['key'] ?? null) === $key && ($p['audience'] ?? null) === $audience,
        ));

        $row = [
            'id' => count($this->personas) + 1,
            'key' => $key, 'audience' => $audience, 'label' => $label,
            'voice' => $voice, 'version' => $version, 'is_active' => true,
            'updated_by' => $author,
        ];

        $this->personas[] = $row;

        return $row;
    }

    public function rollBackTo(int $id, ?string $author = null): array
    {
        foreach ($this->personas as $p) {
            if (($p['id'] ?? null) === $id) {
                return $this->publish(
                    (string) $p['key'], (string) $p['label'], (string) $p['voice'],
                    $p['audience'] ?? null, $author,
                );
            }
        }

        return [];
    }

    public function deactivate(int $id): bool
    {
        return true;
    }
}
