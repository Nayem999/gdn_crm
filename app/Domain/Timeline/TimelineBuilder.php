<?php

namespace App\Domain\Timeline;

use App\Domain\Audit\AuditLogger;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Merges the three strands of a record's history into one ordered list.
 *
 * Notes, documents and audit entries live in three tables with three shapes, so
 * they are read separately and merged in memory rather than unioned. Each read
 * is bounded by the same limit the caller asked for, so "show me the newest 20"
 * costs three indexed reads of at most 21 rows — never a full scan of a
 * record's history to throw most of it away.
 */
class TimelineBuilder
{
    /**
     * The newest slice of a record's timeline.
     *
     * @param  array<int, TimelineEntryKind>|null  $kinds  Null means every strand.
     */
    public function for(Model $subject, int $limit = 20, ?array $kinds = null): TimelinePage
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
}
