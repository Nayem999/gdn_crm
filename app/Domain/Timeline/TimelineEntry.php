<?php

namespace App\Domain\Timeline;

use App\Domain\Audit\ActivityPresenter;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * One row of a record's timeline, whichever of the three strands it came from.
 *
 * The view renders these rather than three different models, so ordering and
 * paging happen once over a single shape.
 */
final readonly class TimelineEntry
{
    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function __construct(
        public TimelineEntryKind $kind,
        public int $id,
        public CarbonInterface $occurredAt,
        public string $title,
        public ?string $body,
        public ?User $actor,
        public string $color,
        public array $changes = [],
        public ?Note $note = null,
        public ?Document $document = null,
    ) {}

    public static function fromNote(Note $note): self
    {
        $author = $note->author;

        return new self(
            kind: TimelineEntryKind::Note,
            id: $note->id,
            // A note has no separate "happened at": when it was written is the
            // moment it belongs to, and editing it does not move it.
            occurredAt: $note->created_at ?? now(),
            title: self::actorName($author).' left a note',
            body: $note->body,
            actor: $author,
            color: TimelineEntryKind::Note->color(),
            note: $note,
        );
    }

    public static function fromDocument(Document $document): self
    {
        $uploader = $document->uploadedBy;

        return new self(
            kind: TimelineEntryKind::Document,
            id: $document->id,
            occurredAt: $document->created_at ?? now(),
            title: self::actorName($uploader).' attached '.$document->title,
            body: $document->description,
            actor: $uploader,
            color: TimelineEntryKind::Document->color(),
            document: $document,
        );
    }

    public static function fromActivity(Activity $activity): self
    {
        /** @var User|null $causer */
        $causer = $activity->causer instanceof User ? $activity->causer : null;

        return new self(
            kind: TimelineEntryKind::History,
            id: $activity->id,
            occurredAt: $activity->created_at ?? now(),
            title: $activity->description,
            body: null,
            actor: $causer,
            color: ActivityPresenter::color($activity->event),
            changes: ActivityPresenter::changes($activity),
        );
    }

    /**
     * An account that has since been removed still wrote what it wrote, so the
     * entry names the gap rather than dropping the sentence.
     */
    private static function actorName(?User $actor): string
    {
        return $actor === null ? 'Somebody who has since left' : $actor->name;
    }

    /**
     * The sort key: when it happened, then which strand, then the row's id.
     *
     * Timestamps are stored to the second, so entries from different strands
     * tie often — writing a note is one second's work that also stamps the
     * record. Ids cannot break that tie: they come from three separate
     * sequences, so an activity id being larger than a note id says nothing
     * about which came first. Ordering on them put a note *below* the update
     * that preceded it, which is what this rank is for.
     *
     * Within a second it is therefore a presentation choice, made once and
     * stated: what a person did comes above the bookkeeping that followed it.
     * Ids still decide within a strand, where they do mean something, and the
     * whole key is total — an unstable comparison makes "load more" repeat or
     * skip rows.
     */
    public function sortKey(): string
    {
        $rank = match ($this->kind) {
            TimelineEntryKind::Note => 2,
            TimelineEntryKind::Document => 1,
            TimelineEntryKind::History => 0,
        };

        return $this->occurredAt->format('YmdHis')
            .'-'.$rank
            .'-'.str_pad((string) $this->id, 12, '0', STR_PAD_LEFT);
    }

    public function isNote(): bool
    {
        return $this->kind === TimelineEntryKind::Note;
    }

    public function isDocument(): bool
    {
        return $this->kind === TimelineEntryKind::Document;
    }

    public function isHistory(): bool
    {
        return $this->kind === TimelineEntryKind::History;
    }
}
