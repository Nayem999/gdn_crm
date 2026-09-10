<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use Illuminate\Support\Carbon;

/**
 * Materialises the occurrences of every repeating activity.
 *
 * Occurrences are **real rows inside a rolling window**, not dates worked out
 * when a screen asks. Three reasons, and all of them bite:
 *
 * - they have to be filterable, sortable, assignable and completable one at a
 *   time, which a virtual date computed in a view is not;
 * - a series with no end date has infinitely many occurrences, so something has
 *   to bound them, and a window is the only bound that does not need the user
 *   to invent an end;
 * - the calendar in 3.6 and the reminder sweep both read the table, and neither
 *   should have to know what recurrence means.
 *
 * Idempotent: it is safe to run repeatedly, and it does, both when a series is
 * saved and nightly as the window rolls forward.
 */
class GenerateRecurringActivitiesAction
{
    /**
     * How far ahead occurrences are materialised. Long enough that a monthly
     * series is visible a quarter out, short enough that a daily one is 90 rows
     * rather than thousands.
     */
    public const HORIZON_DAYS = 90;

    /**
     * @return int how many occurrences were created
     */
    public function __invoke(?Activity $master = null, ?Carbon $horizon = null): int
    {
        $horizon ??= now()->addDays(self::HORIZON_DAYS);

        if ($master !== null) {
            return $this->expand($master, $horizon);
        }

        $created = 0;

        Activity::query()
            ->whereNull('recurrence_parent_id')
            ->whereNotNull('recurrence_frequency')
            // A called-off series stops producing. A *completed* one does not:
            // completing the first appointment is how a series makes progress.
            ->where('status', '!=', ActivityStatus::Cancelled->value)
            ->orderBy('id')
            // Untyped on purpose: the closure's parameter is inferred from the
            // typed builder, which keeps the Activity generic intact.
            ->chunkById(200, function ($masters) use (&$created, $horizon) {
                foreach ($masters as $series) {
                    $created += $this->expand($series, $horizon);
                }
            });

        return $created;
    }

    private function expand(Activity $master, Carbon $horizon): int
    {
        $rule = $master->recurrence();

        if ($rule === null || $master->isOccurrence() || $master->isCancelled()) {
            return 0;
        }

        $dates = $rule->dueDatesAfter($master->due_at, $horizon);

        if ($dates === []) {
            return 0;
        }

        // withTrashed, and this is the whole of the idempotence: an occurrence
        // somebody deleted must stay deleted. Without it the nightly sweep
        // would put every removed appointment straight back.
        $taken = $master->occurrences()->withTrashed()
            ->pluck('due_at')
            ->map(fn (mixed $due) => Carbon::parse((string) $due)->format('Y-m-d H:i:s'))
            ->all();

        $template = $this->template($master);
        $created = 0;

        foreach ($dates as $due) {
            if (in_array($due->format('Y-m-d H:i:s'), $taken, true)) {
                continue;
            }

            $occurrence = new Activity;
            $occurrence->forceFill([...$template, 'due_at' => $due])->save();
            $created++;
        }

        return $created;
    }

    /**
     * What an occurrence inherits from its series.
     *
     * The recurrence columns are pointedly *not* copied: an occurrence carrying
     * a rule would generate occurrences of its own, and the second generation
     * would do it again.
     *
     * @return array<string, mixed>
     */
    private function template(Activity $master): array
    {
        return [
            'type' => $master->getAttributeValue('type'),
            'subject' => $master->subject,
            'description' => $master->description,
            'status' => ActivityStatus::Open->value,
            'priority' => $master->getAttributeValue('priority'),
            'all_day' => $master->all_day,
            'duration_minutes' => $master->duration_minutes,
            'location' => $master->location,
            'reminder_minutes_before' => $master->reminder_minutes_before,
            'related_type' => $master->related_type,
            'related_id' => $master->related_id,
            'owner_id' => $master->owner_id,
            'created_by_id' => $master->created_by_id,
            'recurrence_parent_id' => $master->getKey(),
        ];
    }
}
