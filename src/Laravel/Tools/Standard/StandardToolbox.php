<?php

namespace Shirahcan\AiClient\Laravel\Tools\Standard;

use Shirahcan\AiClient\Laravel\Tools\ToolActor;

/**
 * The shared tools a product offers, composed into its catalog:
 *
 *   definitions(): array_merge($mine, $this->standard->definitions($audience))
 *   run():         $this->standard->handles($name) ? $this->standard->run($name, $input, $actor) : ...
 *
 * A product builds it with the adapters it supports (`new StandardToolbox(new SetReminderTool($adapter))`).
 */
final class StandardToolbox
{
    /** @var array<string, StandardTool> */
    private array $tools = [];

    public function __construct(StandardTool ...$tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<array<string, mixed>> */
    public function definitions(string $audience): array
    {
        return array_values(array_filter(array_map(static fn (StandardTool $t) => $t->definitionFor($audience), $this->tools)));
    }

    public function handles(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** @param  array<string, mixed>  $input */
    public function run(string $name, array $input, ToolActor $actor): array
    {
        return isset($this->tools[$name])
            ? $this->tools[$name]->run($input, $actor)
            : ['ok' => false, 'error' => 'No such tool.'];
    }
}
