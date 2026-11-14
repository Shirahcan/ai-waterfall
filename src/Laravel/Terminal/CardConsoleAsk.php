<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

/**
 * One ask in a share card's console (1.73.0, shared program E4c), the same for every product: what a card Build's
 * result MEANS. The product's worker returns pml-kit's `CardBuild` result in `data`, plus the "what changed" it
 * described:
 *
 *     {summary: reply, data: {changed, refused, reason, checks, notes, changes?}}
 *
 * The product extends this with its subject, its budget and its Stop (the parts only it knows).
 *
 * ⚠ A REFUSAL IS NOT A FAILURE. A card the checks or the policy refused changed nothing and says why; the session
 * records it as an answer the person reads, and the next ask sees it, so the same change is not tried again blind.
 */
abstract class CardConsoleAsk extends ConsoleAsk
{
    /**
     * @param  array<string, mixed>  $console
     * @param  array<string, mixed>  $result
     */
    protected function outcomeOf(array $console, array $result): array
    {
        $data = (array) ($result['data'] ?? []);
        $ok = ! ($data['refused'] ?? false);
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : null;
        $findings = array_values(array_filter((array) ($data['checks'] ?? []), 'is_array'));

        foreach ((array) ($data['notes'] ?? []) as $note) {
            if (is_string($note) && trim($note) !== '') {
                $findings[] = ['code' => 'note', 'text' => trim($note)];
            }
        }

        return [
            'ok' => $ok,
            'changed' => $ok && (bool) ($data['changed'] ?? false),
            'reply' => (string) ($result['summary'] ?? ''),
            'findings' => $findings,
            'changes' => $changes,
            'elements' => $changes !== null && ($changes['total'] ?? 0) > 0 ? (int) $changes['total'] : null,
            'reason' => (string) ($data['reason'] ?? ''),
        ];
    }
}
