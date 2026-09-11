<?php

namespace App\Domain\Dashboard\Enums;

use App\Domain\Settings\DisplayTime;
use Illuminate\Support\Carbon;

/**
 * The window a KPI describes.
 *
 * Boundaries are worked out on the **office clock** and handed back in stored
 * terms — "this month" ends at midnight where the company is, not at midnight
 * UTC. See .ai/rules/settings.md on DisplayTime.
 *
 * The value reaches the component from the URL, so it is an enum: a period the
 * dashboard does not offer resolves to the default rather than deciding a date
 * range.
 */
enum DashboardPeriod: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'This month',
            self::Quarter => 'This quarter',
            self::Year => 'This year',
        };
    }

    /**
     * The short form a KPI caption uses, where the card already says what it
     * is counting.
     */
    public function caption(): string
    {
        return match ($this) {
            self::Month => 'this month',
            self::Quarter => 'this quarter',
            self::Year => 'this year',
        };
    }

    /**
     * Named startsAt/endsAt rather than from/to: `from()` is BackedEnum's own
     * static, and declaring one here is a fatal redeclaration.
     */
    public function startsAt(): Carbon
    {
        $now = DisplayTime::now();

        $start = match ($this) {
            self::Month => $now->copy()->startOfMonth(),
            self::Quarter => $now->copy()->startOfQuarter(),
            self::Year => $now->copy()->startOfYear(),
        };

        return DisplayTime::startOfDay($start);
    }

    public function endsAt(): Carbon
    {
        $now = DisplayTime::now();

        $end = match ($this) {
            self::Month => $now->copy()->endOfMonth(),
            self::Quarter => $now->copy()->endOfQuarter(),
            self::Year => $now->copy()->endOfYear(),
        };

        return DisplayTime::endOfDay($end);
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
