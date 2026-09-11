<?php

namespace App\Domain\Timeline;

use App\Domain\Activities\Models\Activity as ScheduledActivity;
use App\Domain\Audit\AuditLogger;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Merges the strands of a record's history into one ordered list.
 *
 * Notes, documents, scheduled activities and audit entries live in four tables
 * with four shapes, so they are read separately and merged in memory rather
 * than unioned. Each read is bounded by the same limit the caller asked for, so
 * "show me the newest 20" costs four indexed reads of at most 21 rows — never a
 * full scan of a record's history to throw most of it away.
 *
 * The activity strand is ordered by `due_at` rather than `created_at`, because
 * that is the moment a task or meeting belongs to. Everything else here is
 * ordered by when it was written.
 */
class TimelineBuilder
{
    /**
     * The newest slice of a record's timeline.
     *
     * @param  array<int, TimelineEntryKind>|null  $kinds  Null means every strand.
     * @param  User|null  $viewer  Whose permissions and access level the
     *                             activity strand is read through.
     */
    public function for(Model $subject, int $limit = 20, ?array $kinds = null, ?User $viewer = null): TimelinePage
    {
        $limit = max(1, $limit);
        $wanted = $kinds === null || $kinds === [] ? TimelineEntryKind::cases() : $kinds;

        // One more than asked for, so "is there another page?" is answered by
        // the read rather than by a second count query.
        $take = $limit + 1;

        $entries = [];

        if (in_array(TimelineEntryKind::Note, $wanted, true)) {
            foreach ($this->notes($subject, $take) as $note) {
                $entries[] = TimelineEntry::fromNote($note);
            }
        }

        if (in_array(TimelineEntryKind::Document, $wanted, true)) {
            foreach ($this->documents($subject, $take) as $document) {
                $entries[] = TimelineEntry::fromDocument($document);
            }
        }

        if ($this->readsActivities($viewer) && in_array(TimelineEntryKind::Activity, $wanted, true)) {
            foreach ($this->scheduledActivities($subject, $take, $viewer) as $scheduled) {
                $entries[] = TimelineEntry::fromScheduledActivity($scheduled);
            }
        }

        if (in_array(TimelineEntryKind::History, $wanted, true)) {
            foreach ($this->activities($subject, $take) as $activity) {
                $entries[] = TimelineEntry::fromActivity($activity);
            }
        }

        usort($entries, fn (TimelineEntry $a, TimelineEntry $b) => strcmp($b->sortKey(), $a->sortKey()));

        return new TimelinePage(
            entries: array_slice($entries, 0, $limit),
            hasMore: count($entries) > $limit,
            noteCount: $this->countNotes($subject),
            documentCount: $this->countDocuments($subject),
            activityCount: $this->countScheduledActivities($subject, $viewer),
        );
    }

    /**
     * @return array<int, Note>
     */
    private function notes(Model $subject, int $take): array
    {
        return Note::query()
            ->with('author')
            ->where('notable_type', $subject->getMorphClass())
            ->where('notable_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get()
            ->all();
    }

    /**
     * @return array<int, Document>
     */
    private function documents(Model $subject, int $take): array
    {
        return Document::query()
            ->with(['uploadedBy', 'media'])
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get()
            ->all();
    }

    /**
     * The scheduled tasks, calls and meetings about this record.
     *
     * Read straight off the table, the way the other strands are, rather than
     * through the subject's `scheduledActivities()` relation — the builder is
     * handed a Model, and a relation would have to exist on every one of them
     * before this could run at all.
     *
     * Read through the **viewer's own** activities scope, not just the
     * subject's. Being able to see an account is not being able to see the
     * calls somebody else has booked about it: that is a different module with
     * its own permission and its own access level, and a timeline that ignored
     * them would be a way round both.
     *
     * @return array<int, ScheduledActivity>
     */
    private function scheduledActivities(Model $subject, int $take, ?User $viewer): array
    {
        if (! $this->readsActivities($viewer)) {
            return [];
        }

        return ScheduledActivity::query()
            ->visibleTo($viewer)
            ->with('owner')
            ->forRecord($subject)
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get()
            ->all();
    }

    /**
     * A builder with no viewer leaves the strand out rather than guessing. It
     * is called with one everywhere it matters, and "show everything when
     * nobody asked" is the wrong way for that default to fail.
     */
    private function readsActivities(?User $viewer): bool
    {
        return $viewer !== null && $viewer->can('activities.view');
    }

    /**
     * @return array<int, Activity>
     */
    private function activities(Model $subject, int $take): array
    {
        return Activity::query()
            ->with('causer')
            ->where('log_name', AuditLogger::LOG_NAME)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            // id is the tiebreaker, not decoration: same-second entries would
            // otherwise come back in arbitrary order, which makes paging repeat
            // or skip rows. Same rule as the audit viewer.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get()
            ->all();
    }

    public function countNotes(Model $subject): int
    {
        return Note::query()
            ->where('notable_type', $subject->getMorphClass())
            ->where('notable_id', $subject->getKey())
            ->count();
    }

    public function countDocuments(Model $subject): int
    {
        return Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->count();
    }

    public function countScheduledActivities(Model $subject, ?User $viewer = null): int
    {
        if (! $this->readsActivities($viewer)) {
            return 0;
        }

        return ScheduledActivity::query()->visibleTo($viewer)->forRecord($subject)->count();
    }
}
