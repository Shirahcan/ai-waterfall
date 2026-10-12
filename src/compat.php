<?php

/*
|--------------------------------------------------------------------------
| `Shirahcan\AiWaterfall\*` keeps working, for ONE release
|--------------------------------------------------------------------------
|
| ⚠ WHY A SHIM RATHER THAN A COORDINATED CUTOVER. Four products import these
| class names across roughly seventy call sites, and they deploy independently.
| A hard rename means every one of them has to land in the same window as the
| package release, and the first that does not is a fatal `Class not found` on a
| live box. The shim makes the rename a sequence of small, independently
| revertible commits instead of one appointment nobody can keep.
|
| ⚠ IT IS AN ALIAS, NOT A SUBCLASS. `class_alias` makes the old name the SAME
| class, so a product that catches `Shirahcan\AiWaterfall\Exceptions\
| AiUnavailableException` still catches the exception the new code throws.
| Declaring `class OldName extends NewName` would NOT do that: the thrown object
| would be the parent, the old catch block would never match, and every
| product's careful degrade path would silently stop running at exactly the
| moment it was needed.
|
| ⚠ THE INTERFACES AND EXCEPTIONS MATTER MORE THAN THE CLIENT. A product that
| still type-hints the old client gets an immediate, obvious error. A product
| whose `catch` no longer matches gets a 500 in front of a user, three weeks
| later, in a path nobody tests because it only runs during an outage.
|
| REMOVAL: this file goes when every product's `composer.lock` shows
| `shirahcan/ai-client` and no source file references `AiWaterfall`. That is a
| grep, and it is phase P12's job - not an open-ended "eventually".
*/

$aliases = [
    // The client itself.
    \Shirahcan\AiClient\AiClient::class => 'Shirahcan\AiWaterfall\AiWaterfallClient',
    \Shirahcan\AiClient\FakeAiClient::class => 'Shirahcan\AiWaterfall\FakeAiWaterfall',

    // Value objects that cross the boundary into product code.
    \Shirahcan\AiClient\AiResult::class => 'Shirahcan\AiWaterfall\AiResult',
    \Shirahcan\AiClient\AiEmbedding::class => 'Shirahcan\AiWaterfall\AiEmbedding',
    \Shirahcan\AiClient\AiModelStatus::class => 'Shirahcan\AiWaterfall\AiModelStatus',
    \Shirahcan\AiClient\AiUsageReport::class => 'Shirahcan\AiWaterfall\AiUsageReport',
    \Shirahcan\AiClient\AiStreamEvent::class => 'Shirahcan\AiWaterfall\AiStreamEvent',

    /*
     * ⚠ THE LOAD-BEARING FOUR. Every product branches on these, and the whole
     * point of their being typed is that the remedies differ: degrade, raise a
     * cap, call a human, or report an outage. An alias that missed one would
     * turn that product's most careful code path into an unhandled 500.
     */
    \Shirahcan\AiClient\Exceptions\AllProvidersFailedException::class
        => 'Shirahcan\AiWaterfall\Exceptions\AllProvidersFailedException',
    \Shirahcan\AiClient\Exceptions\AiOverBudgetException::class
        => 'Shirahcan\AiWaterfall\Exceptions\AiOverBudgetException',
    \Shirahcan\AiClient\Exceptions\AiUnavailableException::class
        => 'Shirahcan\AiWaterfall\Exceptions\AiUnavailableException',
    \Shirahcan\AiClient\Exceptions\NoCompliantCredentialException::class
        => 'Shirahcan\AiWaterfall\Exceptions\NoCompliantCredentialException',
];

/*
 * ⚠ `AiAdmin`, `FakeAiAdmin` AND `AdminNotConfiguredException` ARE NOT ALIASED,
 * DELIBERATELY. They arrived in 1.2, after the rename, so no product has ever
 * imported them under the old namespace. An alias for a name that never existed
 * is not compatibility - it is a second spelling for new code to drift onto, and
 * it would still be here long after `compat.php` was deleted.
 */

foreach ($aliases as $current => $legacy) {
    if (! class_exists($legacy, false)) {
        class_alias($current, $legacy);
    }
}
