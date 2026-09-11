<?php

namespace App\Domain\Activities\Scheduling;

use App\Domain\Activities\Models\Activity;
use App\Domain\Settings\DisplayTime;
use Illuminate\Support\Carbon;

/**
 * One bookable slot on somebody's day.
 *
 * Both ends are on the office clock. `value()` is what the browser sends back,
 * and it is a wall-clock string rather than an id — a slot is not a record, so
 * there is nothing to look up, and the action re-checks the time it names.
 */
final readonly class Slot
{
    public function __construct(
        public Carbon $startsAt,
        public Carbon $endsAt,
        /** What is already in the way, if anything. */
        public ?Activity $conflict = null,
    ) {}

    public function isFree(): bool
    {
        return $this->conflict === null;
    }

    /**
     * The value a button posts back: a wall-clock time in the office's own
     * timezone, which BookMeetingAction converts on the way in.
     */
    public function value(): string
    {
        return $this->startsAt->format('Y-m-d H:i');
    }

    public function label(): string
    {
        return DisplayTime::time($this->startsAt);
    }

    public function rangeLabel(): string
    {
        return DisplayTime::time($this->startsAt).'–'.DisplayTime::time($this->endsAt);
    }

    /**
     * Why this slot cannot be used, for the tooltip on a disabled button.
     */
    public function conflictLabel(): ?string
    {
        return $this->conflict === null
            ? null
            : $this->conflict->type()->label().': '.$this->conflict->subject;
    }
}
