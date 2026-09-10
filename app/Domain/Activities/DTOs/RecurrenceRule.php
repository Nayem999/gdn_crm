<?php

namespace App\Domain\Activities\DTOs;

use App\Domain\Activities\Enums\RecurrenceFrequency;
use Illuminate\Support\Carbon;

/**
 * How a series repeats, and which dates that works out to.
 *
 * `count` is the number of occurrences **including** the first one, which is
 * how every calendar application states it — "repeat 5 times" means five
 * appointments in total, not five more.
 */
readonly class RecurrenceRule
{
    /**
     * A ceiling on what one expansion may produce, so a rule that somehow ends
     * up nonsensical cannot fill the table. The horizon normally stops the
     * loop long before this.
     */
    public const MAX_OCCURRENCES = 500;

    public function __construct(
        public RecurrenceFrequency $frequency,
        public int $interval = 1,
        public ?Carbon $until = null,
        public ?int $count = null,
    ) {}

    /**
     * The due dates that follow `$start`, up to `$horizon`.
     *
     * The first occurrence is the series master itself and is not repeated
     * here — this returns what has to be created alongside it.
     *
     * @return array<int, Carbon>
     */
    public function dueDatesAfter(Carbon $start, Carbon $horizon): array
    {
        $dates = [];
        $remaining = $this->count === null ? null : max(0, $this->count - 1);
        $step = 1;

        while ($remaining === null || $step <= $remaining) {
            if (count($dates) >= self::MAX_OCCURRENCES) {
                break;
            }

            // Counted from the series start every time rather than from the
            // last date produced, so a monthly series that began on the 31st
            // does not drift a day further into the next month each step.
            $next = $this->frequency->advance($start, $step, max(1, $this->interval));

            if ($next->greaterThan($horizon)) {
                break;
            }

            if ($this->until !== null && $next->greaterThan($this->until->copy()->endOfDay())) {
                break;
            }

            $dates[] = $next;
            $step++;
        }

        return $dates;
    }

    /**
     * How the rule reads on screen.
     */
    public function label(): string
    {
        $label = $this->frequency->intervalLabel(max(1, $this->interval));

        if ($this->count !== null) {
            return $label.', '.$this->count.' times';
        }

        if ($this->until !== null) {
            return $label.' until '.$this->until->format('j M Y');
        }

        return $label;
    }
}
