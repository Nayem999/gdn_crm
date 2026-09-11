<?php

namespace Database\Factories;

use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Activities\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ActivityType::Task->value,
            'subject' => fake()->sentence(4),
            'description' => null,
            'status' => ActivityStatus::Open->value,
            'priority' => ActivityPriority::Normal->value,
            // Ahead by default so a fixture is not accidentally overdue and
            // does not turn up in a test that is counting late work.
            'due_at' => fake()->dateTimeBetween('+1 hour', '+3 weeks'),
            'all_day' => false,
            'duration_minutes' => null,
            'location' => null,
            'reminder_minutes_before' => null,
            'recurrence_frequency' => null,
            'recurrence_interval' => 1,
            'recurrence_until' => null,
            'recurrence_count' => null,
            'recurrence_parent_id' => null,
            'related_type' => null,
            'related_id' => null,
            'owner_id' => User::factory(),
            'created_by_id' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function ofType(ActivityType $type): static
    {
        return $this->state(fn () => [
            'type' => $type->value,
            'duration_minutes' => $type->hasDuration() ? 30 : null,
        ]);
    }

    public function call(): static
    {
        return $this->ofType(ActivityType::Call);
    }

    public function meeting(): static
    {
        return $this->ofType(ActivityType::Meeting);
    }

    public function withPriority(ActivityPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    public function dueAt(Carbon|string $due): static
    {
        return $this->state(fn () => ['due_at' => $due instanceof Carbon ? $due : Carbon::parse($due)]);
    }

    public function allDay(Carbon|string|null $due = null): static
    {
        return $this->state(fn (array $attributes) => [
            'all_day' => true,
            // No string cast: the definition's due_at is a DateTime from faker,
            // and casting one to a string is a fatal error — so allDay() with
            // no argument of its own used to crash. Carbon::parse takes either.
            'due_at' => Carbon::parse($due ?? $attributes['due_at'])->startOfDay(),
        ]);
    }

    /**
     * Late, and consistently so: the due date and the status have to agree or
     * the fixture describes something the application cannot produce.
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => ActivityStatus::Open->value,
            'due_at' => now()->subDays(3),
        ]);
    }

    public function completed(?Carbon $at = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Completed->value,
            'completed_at' => $at ?? now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ActivityStatus::Cancelled->value,
            'completed_at' => null,
        ]);
    }

    public function about(Model $record): static
    {
        return $this->state(fn () => [
            'related_type' => $record->getMorphClass(),
            'related_id' => $record->getKey(),
        ]);
    }

    public function remindingAfter(int $minutesBefore): static
    {
        return $this->state(fn () => [
            'reminder_minutes_before' => $minutesBefore,
            'reminder_sent_at' => null,
        ]);
    }

    /**
     * A series master. The occurrences are not created here — that is
     * GenerateRecurringActivitiesAction's job, and a fixture that wrote them
     * itself could disagree with the rule it claims to follow.
     */
    public function repeating(
        RecurrenceFrequency $frequency = RecurrenceFrequency::Weekly,
        int $interval = 1,
        ?int $count = null,
        Carbon|string|null $until = null,
    ): static {
        return $this->state(fn () => [
            'recurrence_frequency' => $frequency->value,
            'recurrence_interval' => $interval,
            'recurrence_count' => $count,
            'recurrence_until' => $until === null
                ? null
                : ($until instanceof Carbon ? $until : Carbon::parse($until)),
            'recurrence_parent_id' => null,
        ]);
    }

    /**
     * One appointment out of a series. Never carries a rule of its own — that
     * is what stops a generated row generating more.
     */
    public function occurrenceOf(Activity $master, Carbon|string $due): static
    {
        return $this->state(fn () => [
            'recurrence_parent_id' => $master->getKey(),
            'recurrence_frequency' => null,
            'recurrence_interval' => 1,
            'recurrence_count' => null,
            'recurrence_until' => null,
            'subject' => $master->subject,
            'type' => $master->getAttributeValue('type'),
            'owner_id' => $master->owner_id,
            'due_at' => $due instanceof Carbon ? $due : Carbon::parse($due),
        ]);
    }
}
