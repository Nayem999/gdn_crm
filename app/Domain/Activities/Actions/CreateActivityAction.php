<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\ActivityMergeData;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Activities\Models\Activity;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use RuntimeException;

class CreateActivityAction
{
    public function __construct(
        private readonly GenerateRecurringActivitiesAction $generateOccurrences,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws RuntimeException when the record it is about is not one this
     *                          person can reach
     */
    public function __invoke(ActivityData $data, User $actor): Activity
    {
        $attributes = $data->toAttributes();

        // An activity always has an owner: unassigned work is how "my tasks"
        // quietly loses things, and the visibility scope reads this column.
        $attributes['owner_id'] = $data->ownerId ?? $actor->id;
        $attributes['created_by_id'] = $actor->id;
        $attributes = [...$attributes, ...$data->recurrenceAttributes()];
        $attributes = [...$attributes, ...$this->relatedAttributes($data, $actor)];

        $activity = new Activity;
        $activity->forceFill($attributes)->save();
        $activity->refresh();

        // Materialised now rather than waiting for the nightly sweep, so
        // somebody who just set up a weekly call can see the next few.
        if ($activity->isSeriesMaster()) {
            ($this->generateOccurrences)($activity);
        }

        $this->announce($activity, $actor);

        return $activity;
    }

    /**
     * The record this activity is about, resolved from a module key.
     *
     * Nothing from a request names `related_type`: the key is matched against
     * ActivityRelations, and the record is loaded through the viewer's own
     * access scope, so an id belonging to somebody else's account cannot be
     * attached by guessing it.
     *
     * @return array<string, mixed>
     */
    private function relatedAttributes(ActivityData $data, User $actor): array
    {
        if ($data->relatedModule === null || $data->relatedId === null) {
            return ['related_type' => null, 'related_id' => null];
        }

        if (! ActivityRelations::has($data->relatedModule)) {
            throw new RuntimeException('That is not a record an activity can be about.');
        }

        $record = ActivityRelations::resolve($data->relatedModule, $data->relatedId, $actor);

        if ($record === null) {
            throw new RuntimeException('That record is not one you can work with.');
        }

        return [
            'related_type' => $record->getMorphClass(),
            'related_id' => $record->getKey(),
        ];
    }

    /**
     * Tell the owner, unless the owner is the person who just created it — the
     * engine drops an actor's own notifications anyway, and saying so here
     * keeps the intent visible.
     */
    private function announce(Activity $activity, User $actor): void
    {
        $owner = $activity->owner;

        if ($owner === null || $owner->id === $actor->id) {
            return;
        }

        $this->notifier->send(
            'activity.assigned',
            [Recipient::user($owner, RecipientType::AssignedAgent)],
            ActivityMergeData::for($activity),
            $actor,
            route('activities.edit', $activity->id),
        );
    }
}
