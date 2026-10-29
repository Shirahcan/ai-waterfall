<?php

namespace Shirahcan\AiClient\Laravel\Eval;

/**
 * How a product asks its assistant one eval question: the ONLY product code behind `ai:eval`.
 *
 * The product decides who asks (its eval user for the item's audience), from which page, and
 * through its real chat path, so the eval measures what a person would get.
 */
interface EvalDriver
{
    /**
     * Ask one item and return the reply as the panel would show it.
     *
     * @param  array<string, mixed>  $item  one entry of the eval set (`id`, `audience`, `prompt`, ...)
     * @param  array<string, mixed>  $set   the whole set (its `users` map, anything else the product keeps there)
     * @return array{content: string, kind?: ?string, error?: string}|null  null = this item cannot run here (say why in `error`)
     */
    public function answer(array $item, array $set): ?array;
}
