<?php

namespace Shirahcan\AiClient\Assistant;

use InvalidArgumentException;

/**
 * The tools one assistant may reach for, and what the model is told about them.
 *
 * ⚠ ONE CATALOGUE PER AUDIENCE IS THE WRONG SHAPE, AND THIS IS WHY. The
 * tempting design is a client catalogue and a consultant catalogue; then every
 * new tool has to be added to the right ones, and the first time somebody
 * forgets, a consultant quietly loses a capability nobody notices for weeks.
 * Instead there is ONE catalogue and every tool answers `authorize()` for the
 * viewer in front of it, so the rule lives next to the thing it governs and a
 * new tool cannot be added to the wrong list.
 */
final class ToolCatalog
{
    /** @var array<string, ToolContract> */
    private array $tools = [];

    /** @param array<int, ToolContract> $tools */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->add($tool);
        }
    }

    public function add(ToolContract $tool): self
    {
        $name = $tool->name();

        if (! preg_match('/^[a-z][a-z0-9_]{1,47}$/', $name)) {
            /*
             * ⚠ REFUSED LOUDLY AT REGISTRATION, because the alternative is a
             * model that silently never calls the tool: a name with a space, a
             * capital or a dot is one the model will mangle when it echoes it
             * back, and the harness will then fail to find it. A boot-time
             * exception is far cheaper than an assistant that is quietly one
             * capability short.
             */
            throw new InvalidArgumentException(
                "Tool name [{$name}] must be snake_case, 2-48 chars, starting with a letter."
            );
        }

        if (isset($this->tools[$name])) {
            // Two tools with one name means the model's choice is ambiguous and
            // whichever registered last silently wins.
            throw new InvalidArgumentException("Tool [{$name}] is registered twice.");
        }

        $this->tools[$name] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function find(string $name): ?ToolContract
    {
        return $this->tools[$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * What the model is told, as a compact list.
     *
     * ⚠ EVERY TOOL IS DESCRIBED TO THE MODEL, INCLUDING ONES THIS VIEWER MAY
     * NOT USE, and that is deliberate rather than an oversight. A model that
     * cannot see a tool cannot say "you would need X for that", so a client
     * asking for something consultant-only would get a confused non-answer
     * instead of a straight one. The refusal happens at EXECUTION, where it is
     * enforceable - describing a tool grants nothing.
     *
     * @return array<int, array{name: string, description: string, arguments: array<string, mixed>}>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->tools as $name => $tool) {
            $described[] = [
                'name'        => $name,
                'description' => $tool->description(),
                'arguments'   => $tool->schema(),
            ];
        }

        return $described;
    }
}
