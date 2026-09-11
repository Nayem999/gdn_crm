<?php

namespace App\Domain\Activities\Calendar;

use App\Domain\Activities\Models\Activity;
use App\Domain\Settings\DisplayTime;
use Illuminate\Support\Carbon;

/**
 * One activity, placed on the calendar.
 *
 * The activity itself knows when it is due in stored terms; this knows where
 * that lands on the office clock, which is the only thing the grid can draw
 * against. Everything public here is already in the display timezone.
 */
final readonly class CalendarEvent
{
    /**
     * How long a block is when the activity does not say.
     *
     * A task has no duration — it takes as long as it takes — but it still has
     * to occupy something in an hour grid, and a zero-height block is a block
     * nobody can click.
     */
    public const DEFAULT_MINUTES = 30;

    private function __construct(
        public Activity $activity,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public bool $allDay,
    ) {}

    public static function for(Activity $activity): self
    {
        $start = DisplayTime::display($activity->due_at);
        $minutes = $activity->duration_minutes ?? self::DEFAULT_MINUTES;

        return new self(
            activity: $activity,
            startsAt: $start,
            // Clamped to its own day. A 23:30 call running an hour belongs to
            // the 23:30 cell; spilling it into tomorrow's first row would draw
            // one appointment twice and make the day's list disagree with the
            // grid beside it.
            endsAt: $start->copy()->addMinutes($minutes)->min($start->copy()->endOfDay()),
            allDay: (bool) $activity->all_day,
        );
    }

    /**
     * Which cell this belongs in — the day on the office clock, not the stored
     * one. This is the whole of "timezone-safe": an activity at 23:30 UTC is
     * tomorrow's problem in Dhaka, and this is where that is decided.
     */
    public function dayKey(): string
    {
        return $this->startsAt->format('Y-m-d');
    }

    /**
     * Minutes from midnight, which is what positions a block in the hour grid.
     */
    public function offsetMinutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->startsAt->copy()->startOfDay(), true);
    }

    public function durationMinutes(): int
    {
        return max(1, (int) $this->startsAt->diffInMinutes($this->endsAt, true));
    }

    public function timeLabel(): string
    {
        return $this->allDay ? 'All day' : DisplayTime::time($this->startsAt);
    }

    /**
     * The title in the block, which has room for very little.
     */
    public function title(): string
    {
        return $this->activity->subject;
    }

    public function color(): string
    {
        return $this->activity->type()->color();
    }

    public function icon(): string
    {
        return $this->activity->type()->icon();
    }

    public function isCompleted(): bool
    {
        return $this->activity->isCompleted();
    }

    public function isOverdue(): bool
    {
        return $this->activity->isOverdue();
    }

    /**
     * Ordering within a day: all-day entries first, then by the clock, then by
     * id so two appointments at the same minute keep a stable order between
     * renders.
     */
    public function sortKey(): string
    {
        return ($this->allDay ? '0' : '1')
            .'-'.$this->startsAt->format('His')
            .'-'.str_pad((string) $this->activity->id, 12, '0', STR_PAD_LEFT);
    }
}
