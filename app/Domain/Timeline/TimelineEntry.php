<?php

namespace App\Domain\Timeline;

use App\Domain\Activities\Models\Activity as ScheduledActivity;
use App\Domain\Audit\ActivityPresenter;
use App\Domain\Settings\DisplayTime;
use App\Domain\Timeline\Communications\Communication;
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
        public ?ScheduledActivity $scheduled = null,
        public ?Communication $communication = null,
    ) {}

    /**
     * A message, on whichever channel carried it.
     *
     * Its id is a hash of the source key rather than a row id: these come from
     * four different tables whose ids collide, and the sort key needs one
     * number that is stable for a given entry and different for different ones.
     */
    public static function fromCommunication(Communication $communication): self
    {
        return new self(
            kind: TimelineEntryKind::Communication,
            id: (int) sprintf('%u', crc32($communication->sourceKey)),
            occurredAt: $communication->occurredAt,
            // The subject is what a person scans for, so it goes in the
            // heading; the channel, the direction and who it was with are the
            // line underneath.
            title: $communication->channel->label().' '.strtolower($communication->directionLabel()).' — '.$communication->title,
            body: $communication->body,
            actor: null,
            color: TimelineEntryKind::Communication->color(),
            communication: $communication,
        );
    }

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

    /**
     * A scheduled task, call or meeting.
     *
     * Its moment is **when it is due**, not when it was written down. A record's
     * timeline is read as "what is going on with this account", and a call
     * booked for Thursday belongs on Thursday — which does mean an upcoming
     * appointment sits above today at the top of a newest-first list. That is
     * the point: the next thing due is the thing somebody needs to see.
     */
    public static function fromScheduledActivity(ScheduledActivity $activity): self
    {
        $owner = $activity->owner;

        return new self(
            kind: TimelineEntryKind::Activity,
            id: $activity->id,
            occurredAt: $activity->due_at,
            title: $activity->type()->label().': '.$activity->subject,
            body: $activity->description,
            actor: $owner,
            color: $activity->type()->color(),
            scheduled: $activity,
        );
    }

    /**
     * An audit entry — spatie's Activity, not the scheduled kind above.
     */
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
            TimelineEntryKind::Note => 3,
            TimelineEntryKind::Document => 2,
            TimelineEntryKind::Activity => 1,
            TimelineEntryKind::History => 0,
            // Above the bookkeeping, below what a person typed: a message is
            // something that happened rather than something somebody wrote here.
            TimelineEntryKind::Communication => 1,
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

    public function isCommunication(): bool
    {
        return $this->kind === TimelineEntryKind::Communication;
    }

    public function isActivity(): bool
    {
        return $this->kind === TimelineEntryKind::Activity;
    }

    public function isHistory(): bool
    {
        return $this->kind === TimelineEntryKind::History;
    }

    /**
     * When it happened, as the office reads it. The calendar and the timeline
     * must not disagree about which day a meeting is on.
     */
    public function occurredLabel(): string
    {
        return DisplayTime::dateTime($this->occurredAt);
    }
}
