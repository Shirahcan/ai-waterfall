<?php

namespace Shirahcan\AiClient\Laravel\Conversations;

use Illuminate\Database\Eloquent\Model;

/**
 * A product's message model whose words live in ai-service (P21d).
 *
 * ⚠ READ FROM THE SERVICE, ONCE PER PAGE. Every collection of rows (a thread's history, a
 * responder's last few turns, a report's window) is hydrated with one corpus read by turn id, so
 * no reader can see a local copy of what was said. A product adopts this by naming a
 * `HeldTurnSubject`; nothing about its queries changes.
 *
 * ⚠ AN OUTAGE IS FLAGGED, NOT HIDDEN. When the service cannot be reached the rows keep no words
 * (the subject's hydrate(null)) and `aiTurnUnavailable()` is true, so a history endpoint can say
 * "earlier messages can't be loaded right now" rather than render an empty conversation.
 */
trait HeldTurnInAiService
{
    private bool $aiTurnUnavailable = false;

    abstract protected static function aiTurnSubject(): string;

    public static function aiHeldTurnSubject(): HeldTurnSubject
    {
        return app(static::aiTurnSubject());
    }

    public function newCollection(array $models = [])
    {
        if ($models !== []) {
            static::hydrateHeldTurns($models);
        }

        return parent::newCollection($models);
    }

    /** @param array<int, Model> $models */
    public static function hydrateHeldTurns(array $models): void
    {
        $subject = static::aiHeldTurnSubject();

        if (! $subject->enabled()) {
            return;
        }

        $column = $subject->column();
        $ids = [];

        foreach ($models as $model) {
            if ($model instanceof self && $model->exists) {
                $id = (string) ($model->getAttributes()[$column] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        if ($ids === []) {
            return;
        }

        $loader = app(HeldTurns::class);
        $turns = $loader->load($ids);
        $unavailable = $loader->isUnavailable();

        foreach ($models as $model) {
            if (! $model instanceof self || ! $model->exists || $model->isDirty()) {
                continue;
            }

            $id = (string) ($model->getAttributes()[$column] ?? '');
            if ($id === '') {
                continue;
            }

            $model->aiTurnUnavailable = $unavailable;
            $model->setRawAttributes(
                array_merge($model->getAttributes(), $subject->hydrate($unavailable ? null : ($turns[$id] ?? null), $model)),
                true,
            );
        }
    }

    /** True when this row's words could not be read from the service this request. */
    public function aiTurnUnavailable(): bool
    {
        return $this->aiTurnUnavailable;
    }
}
