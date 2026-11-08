<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Illuminate\Http\Request;

/**
 * What a product's console works ON (1.58.0, Porter console): a page, a component, an email template.
 * The product binds one implementation; every console route resolves its subject through it FIRST.
 *
 * ⚠ THE PRODUCT DECIDES WHO MAY OPEN WHAT. `ref()` returns null for anything the signed-in person may
 * not edit, and the routes answer 404, so a person never learns that another subject exists. The kit
 * never builds a ref from a browser's words on its own.
 */
interface TerminalSubject
{
    /**
     * The subject's stable ref (`site_page:9f2c...`), or null when this person may not work on it.
     *
     * @param  string  $kind  the route's kind segment (`site_page`, `studio_component`)
     * @param  string  $id  the route's id segment
     */
    public function ref(Request $request, string $kind, string $id): ?string;

    /** How a person names the subject in a console header ("Home page", "Pricing table"). */
    public function label(string $ref): string;

    /**
     * How much of the context the subject itself takes on the next ask, in characters (a page's source,
     * a component's design), and the budget the subject's asks run against, in tokens. The product reads
     * both from its own data and its route config, never a constant in the browser.
     *
     * @return array{design_chars: int, budget_tokens: int}
     */
    public function sizing(string $ref): array;
}
