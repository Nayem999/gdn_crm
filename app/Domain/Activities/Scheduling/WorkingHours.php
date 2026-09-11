<?php

namespace App\Domain\Activities\Scheduling;

use App\Domain\Settings\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The working week meetings are booked into.
 *
 * One definition for the whole installation, which is what a single-organization
 * CRM has: the company profile already carries one timezone, and a per-person
 * week would need a table and a screen that Phase 3 does not ask for.
 *
 * Every time here is on the **office clock**. A working day that started at
 * 09:00 UTC would open at three in the afternoon in Dhaka.
 */
final class WorkingHours
{
    /**
     * Which weekdays each preset covers, as Carbon day-of-week numbers
     * (0 = Sunday).
     */
    private const WEEKS = [
        'mon_fri' => [1, 2, 3, 4, 5],
        'mon_sat' => [1, 2, 3, 4, 5, 6],
        'sun_thu' => [0, 1, 2, 3, 4],
        'sat_wed' => [6, 0, 1, 2, 3],
        'all' => [0, 1, 2, 3, 4, 5, 6],
    ];

    /**
     * @return array<int, int>
     */
    public static function workingDays(): array
    {
        $preset = (string) settings('scheduling.working_week', 'mon_fri');

        return self::WEEKS[$preset] ?? self::WEEKS['mon_fri'];
    }

    public static function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array(DisplayTime::display($day)->dayOfWeek, self::workingDays(), true);
    }

    /**
     * When the working day opens, on the given date, on the office clock.
     */
    public static function opensOn(CarbonInterface $day): Carbon
    {
        return self::at($day, (string) settings('scheduling.day_starts_at', '09:00'));
    }

    public static function closesOn(CarbonInterface $day): Carbon
    {
        return self::at($day, (string) settings('scheduling.day_ends_at', '17:30'));
    }

    public static function slotMinutes(): int
    {
        return max(5, (int) settings('scheduling.slot_minutes', 30));
    }

    public static function defaultMeetingMinutes(): int
    {
        return max(5, (int) settings('scheduling.default_meeting_minutes', 30));
    }

    /**
     * The meeting lengths the booking screen offers.
     *
     * @return array<int, string>
     */
    public static function meetingLengths(): array
    {
        return [
            15 => '15 minutes',
            30 => '30 minutes',
            45 => '45 minutes',
            60 => '1 hour',
            90 => '1 hour 30 minutes',
            120 => '2 hours',
        ];
    }

    /**
     * A wall-clock time on a given date, on the office clock.
     *
     * A malformed setting falls back rather than throwing: these are typed and
     * validated on the way in, but a database somebody edited by hand should
     * not take the booking screen down.
     */
    private static function at(CarbonInterface $day, string $time): Carbon
    {
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = (int) ($parts[1] ?? 0);

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            $hour = 9;
            $minute = 0;
        }

        return DisplayTime::display($day)->startOfDay()->setTime($hour, $minute);
    }
}
