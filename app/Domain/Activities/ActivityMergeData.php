<?php

namespace App\Domain\Activities;

use App\Domain\Activities\Models\Activity;

/**
 * The merge values every activity notification carries.
 *
 * One place, because the assignment message and the reminder describe the same
 * thing and a template naming a field the event does not declare is refused at
 * save time. Keep this in step with the merge fields the two activity events
 * declare in NotificationEventRegistry — a test walks both and compares them.
 */
final class ActivityMergeData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Activity $activity): array
    {
        $related = $activity->related;

        return [
            'activity' => [
                'subject' => $activity->subject,
                'type' => $activity->type()->label(),
                'due' => $activity->dueLabel(),
                'priority' => $activity->priority()->label(),
                // owner_id is NOT NULL and restrictOnDelete, so there is
                // always somebody on the other end of this.
                'owner' => $activity->owner->name,
                'related' => $related === null
                    ? 'nothing in particular'
                    : ActivityRelations::typeLabel($related).' '.ActivityRelations::label($related),
            ],
        ];
    }

    /**
     * The field names the notification events declare, so the two cannot drift.
     *
     * @return array<string, string>
     */
    public static function mergeFields(): array
    {
        return [
            'activity.subject' => 'What it is',
            'activity.type' => 'Task, call or meeting',
            'activity.due' => 'When it is due',
            'activity.priority' => 'How urgent it is',
            'activity.owner' => 'Whose it is',
            'activity.related' => 'The record it is about',
        ];
    }
}
