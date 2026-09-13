<?php

namespace App\Domain\Reports\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a scheduled report goes out.
 *
 * Three, and no cron expression. A cron field on a form is a field most people
 * get wrong, and the three that matter cover what a company actually asks for:
 * every morning, every Monday, the first of the month.
 */
enum ScheduleFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
            self::Monthly => 'Every month',
        };
    }

    public function usesDayOfWeek(): bool
    {
        return $this === self::Weekly;
    }

    public function usesDayOfMonth(): bool
    {
        return $this === self::Monthly;
    }

    /**
     * The next time this schedule is due, strictly after `$after`.
     *
     * Strictly after, always: a schedule that could return the moment it was
     * just sent would send again on the next sweep, and again after that.
     */
    public function next(Carbon $after, int $hour, ?int $dayOfWeek = null, ?int $dayOfMonth = null): Carbon
    {
        $hour = max(0, min(23, $hour));

        return match ($this) {
            self::Daily => $this->nextDaily($after, $hour),
            self::Weekly => $this->nextWeekly($after, $hour, $dayOfWeek ?? Carbon::MONDAY),
            self::Monthly => $this->nextMonthly($after, $hour, $dayOfMonth ?? 1),
        };
    }

    private function nextDaily(Carbon $after, int $hour): Carbon
    {
        $candidate = $after->copy()->setTime($hour, 0);

        return $candidate->greaterThan($after) ? $candidate : $candidate->addDay();
    }

    private function nextWeekly(Carbon $after, int $hour, int $dayOfWeek): Carbon
    {
        $dayOfWeek = max(0, min(6, $dayOfWeek));

        $candidate = $after->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayOfWeek)->setTime($hour, 0);

        return $candidate->greaterThan($after) ? $candidate : $candidate->addWeek();
    }

    private function nextMonthly(Carbon $after, int $hour, int $dayOfMonth): Carbon
    {
        $dayOfMonth = max(1, min(31, $dayOfMonth));

        $candidate = $this->onDayOf($after, $dayOfMonth, $hour);

        if ($candidate->greaterThan($after)) {
            return $candidate;
        }

        return $this->onDayOf($after->copy()->addMonthNoOverflow()->startOfMonth(), $dayOfMonth, $hour);
    }

    /**
     * A day in a month, clamped to that month's length.
     *
     * The 31st in February is the 28th, not the 3rd of March: a monthly report
     * set to the last day of the month must go out in every month.
     */
    private function onDayOf(Carbon $month, int $dayOfMonth, int $hour): Carbon
    {
        $start = $month->copy()->startOfMonth();

        // Counted forward from the first, so the clamp is visible: the 31st
        // of February is the 28th, not the 3rd of March.
        return $start->copy()
            ->addDays(min($dayOfMonth, $start->daysInMonth) - 1)
            ->setTime($hour, 0);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
