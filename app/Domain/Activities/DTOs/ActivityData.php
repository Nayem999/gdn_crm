<?php

namespace App\Domain\Activities\DTOs;

use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityType;
use Illuminate\Support\Carbon;

/**
 * The fields a create or update carries, already validated.
 *
 * `status`, `completed_at` and `completion_notes` are deliberately absent: the
 * complete, reopen and cancel actions own them, so no form and no payload can
 * declare something done — the same rule DealData follows for stage.
 *
 * The related record arrives as a **module key**, never a class name, and is
 * resolved through ActivityRelations. A payload that could set `related_type`
 * would be a payload naming a class.
 */
readonly class ActivityData
{
    public function __construct(
        public string $subject,
        public string $dueAt,
        public ActivityType $type = ActivityType::Task,
        public ActivityPriority $priority = ActivityPriority::Normal,
        public ?string $description = null,
        public bool $allDay = false,
        public ?int $durationMinutes = null,
        public ?string $location = null,
        public ?int $reminderMinutesBefore = null,
        public ?string $recurrenceFrequency = null,
        public int $recurrenceInterval = 1,
        public ?string $recurrenceUntil = null,
        public ?int $recurrenceCount = null,
        public ?string $relatedModule = null,
        public ?int $relatedId = null,
        public ?int $ownerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $text = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (string) $attributes[$key],
        };

        $number = fn (string $key): ?int => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (int) $attributes[$key],
        };

        return new self(
            subject: trim((string) ($attributes['subject'] ?? '')),
            dueAt: (string) ($attributes['due_at'] ?? ''),
            type: ActivityType::tryFrom((string) ($attributes['type'] ?? '')) ?? ActivityType::Task,
            priority: ActivityPriority::tryFrom((int) ($attributes['priority'] ?? 0)) ?? ActivityPriority::Normal,
            description: $text('description'),
            allDay: (bool) ($attributes['all_day'] ?? false),
            durationMinutes: $number('duration_minutes'),
            location: $text('location'),
            reminderMinutesBefore: $number('reminder_minutes_before'),
            recurrenceFrequency: $text('recurrence_frequency'),
            recurrenceInterval: max(1, $number('recurrence_interval') ?? 1),
            recurrenceUntil: $text('recurrence_until'),
            recurrenceCount: $number('recurrence_count'),
            relatedModule: $text('related_module'),
            relatedId: $number('related_id'),
            ownerId: $number('owner_id'),
        );
    }

    /**
     * When the activity is due, normalised.
     *
     * An all-day activity is pinned to the start of its day so two of them on
     * the same date sort together rather than by whatever time the form
     * happened to submit.
     */
    public function due(): Carbon
    {
        $due = Carbon::parse($this->dueAt);

        return $this->allDay ? $due->startOfDay() : $due;
    }

    /**
     * The columns a create or update writes. `type` and `priority` come from
     * enums, so an unknown value became the default long before this.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type->value,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'due_at' => $this->due(),
            'all_day' => $this->allDay,
            // Only where the kind of activity makes it meaningful, so a task
            // cannot carry a room booking nobody will ever look at.
            'duration_minutes' => $this->type->hasDuration() ? $this->durationMinutes : null,
            'location' => $this->type->hasLocation() ? $this->location : null,
            'reminder_minutes_before' => $this->reminderMinutesBefore,
        ];
    }

    /**
     * The recurrence columns, separated because an occurrence never carries a
     * rule of its own.
     *
     * @return array<string, mixed>
     */
    public function recurrenceAttributes(): array
    {
        if ($this->recurrenceFrequency === null) {
            return [
                'recurrence_frequency' => null,
                'recurrence_interval' => 1,
                'recurrence_until' => null,
                'recurrence_count' => null,
            ];
        }

        return [
            'recurrence_frequency' => $this->recurrenceFrequency,
            'recurrence_interval' => max(1, $this->recurrenceInterval),
            // An end date and a repeat count are two ways of saying the same
            // thing, so the form offers one or the other; a count wins when
            // somehow both arrive, because it is the more specific.
            'recurrence_until' => $this->recurrenceCount === null ? $this->recurrenceUntil : null,
            'recurrence_count' => $this->recurrenceCount,
        ];
    }
}
