<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\ActivityMergeData;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use Illuminate\Support\Carbon;

/**
 * Sends the reminders that have come due.
 *
 * A sweep rather than a job scheduled per activity. A delayed job would have to
 * be found and cancelled every time somebody moved a due date, completed the
 * activity early or deleted it, and a queue with no such job is indistinguishable
 * from one that lost it. Reading the table each minute cannot drift from it.
 */
class SendActivityRemindersAction
{
    /**
     * How stale a reminder may be before it is written off rather than sent.
     *
     * If the scheduler was down for a week, "your call was due last Tuesday"
     * arriving as a reminder is noise — the activity is on the overdue list,
     * which is where it belongs by then.
     */
    public const STALE_AFTER_HOURS = 24;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return int how many reminders were handed to the notification engine
     */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        Activity::query()
            ->where('status', ActivityStatus::Open->value)
            ->whereNotNull('reminder_minutes_before')
            ->whereNull('reminder_sent_at')
            // The lead time is per activity, so the comparison has to happen
            // against the column rather than against one cut-off: due_at minus
            // its own reminder_minutes_before must already have passed.
            ->whereRaw('DATE_SUB(due_at, INTERVAL reminder_minutes_before MINUTE) <= ?', [$now])
            ->where('due_at', '>=', $now->copy()->subHours(self::STALE_AFTER_HOURS))
            ->with(['owner', 'related'])
            ->orderBy('due_at')
            ->chunkById(200, function ($activities) use (&$sent, $now) {
                foreach ($activities as $activity) {
                    $sent += $this->remind($activity, $now);
                }
            });

        return $sent;
    }

    private function remind(Activity $activity, Carbon $now): int
    {
        $owner = $activity->owner;

        // Stamped whether or not anybody could be told, so a reminder for an
        // activity whose owner has since been removed is not retried every
        // minute for the next day.
        $activity->forceFill(['reminder_sent_at' => $now])->save();

        if ($owner === null) {
            return 0;
        }

        // No actor: nobody performed this, the clock did. Passing the owner as
        // the actor would make the engine drop it as their own action.
        $this->notifier->send(
            'activity.reminder',
            [Recipient::user($owner, RecipientType::AssignedAgent)],
            ActivityMergeData::for($activity),
            null,
            route('activities.edit', $activity->id),
        );

        return 1;
    }
}
