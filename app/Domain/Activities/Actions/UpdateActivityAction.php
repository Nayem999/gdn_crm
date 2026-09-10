<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\ActivityMergeData;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use RuntimeException;

class UpdateActivityAction
{
    /**
     * The recurrence columns, kept in one place because changing any of them
     * means the occurrences already on the calendar are wrong.
     */
    private const RECURRENCE_COLUMNS = [
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_until',
        'recurrence_count',
    ];

    public function __construct(
        private readonly GenerateRecurringActivitiesAction $generateOccurrences,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws RuntimeException when the record it is about is not one this
     *                          person can reach
     */
    public function __invoke(Activity $activity, ActivityData $data, User $actor): Activity
    {
        $previousOwnerId = $activity->owner_id;

        $attributes = $data->toAttributes();
        $attributes['owner_id'] = $data->ownerId ?? $activity->owner_id;
        $attributes = [...$attributes, ...$this->relatedAttributes($data, $actor)];

        // An occurrence never carries a rule of its own — editing one edits that
        // appointment, not the series — so the recurrence columns are only
        // written on a row that is not itself an occurrence.
        if (! $activity->isOccurrence()) {
            $attributes = [...$attributes, ...$data->recurrenceAttributes()];
        }

        $activity->forceFill($attributes)->save();
        $activity->refresh();

        $this->resyncOccurrences($activity);
        $this->announceReassignment($activity, $previousOwnerId, $actor);

        return $activity;
    }

    /**
     * Re-materialise the series when its rule moved.
     *
     * The future is regenerated from scratch and the past is left alone:
     * occurrences that have already happened, or that somebody has completed or
     * cancelled, are a record of what took place and a changed rule does not
     * reach back and rewrite them.
     *
     * The future ones are **force-deleted**, not soft-deleted, and that is the
     * subtle part. The generator treats a soft-deleted occurrence as a
     * tombstone — an appointment somebody removed on purpose, which the nightly
     * sweep must not put back. Soft-deleting here would make the same promise
     * about rows nobody ever touched, so changing weekly to monthly and back
     * again would silently lose every weekly appointment for good.
     */
    private function resyncOccurrences(Activity $activity): void
    {
        if ($activity->isOccurrence()) {
            return;
        }

        if (! $activity->wasChanged(self::RECURRENCE_COLUMNS) && ! $activity->wasChanged('due_at')) {
            // The generator is idempotent, so it is safe to run anyway — and
            // worth running, because a later due date may have opened room in
            // the window.
            ($this->generateOccurrences)($activity);

            return;
        }

        $activity->occurrences()
            ->where('status', ActivityStatus::Open->value)
            ->where('due_at', '>=', now())
            ->get()
            ->each(fn (Activity $occurrence) => $occurrence->forceDelete());

        if ($activity->isSeriesMaster()) {
            ($this->generateOccurrences)($activity);
        }
    }

    /**
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

    private function announceReassignment(Activity $activity, int $previousOwnerId, User $actor): void
    {
        $owner = $activity->owner;

        if ($owner === null || $owner->id === $previousOwnerId || $owner->id === $actor->id) {
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
