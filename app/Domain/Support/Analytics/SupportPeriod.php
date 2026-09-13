<?php

namespace App\Domain\Support\Analytics;

use Illuminate\Support\Carbon;

/**
 * The window a figure is measured over.
 *
 * A value object rather than two loose arguments, because every metric here has
 * to use the same window — two that disagreed would put a resolution time
 * beside a volume it was not drawn from, which is worse than showing neither.
 *
 * The ends are inclusive days on the office clock: "this month" means every
 * ticket raised on the first, and every one raised on the last.
 */
readonly class SupportPeriod
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $label,
    ) {}

    public static function days(int $days, string $label = ''): self
    {
        $to = now()->endOfDay();

        return new self(
            from: now()->subDays($days - 1)->startOfDay(),
            to: $to,
            label: $label === '' ? 'Last '.$days.' days' : $label,
        );
    }

    public static function thisMonth(): self
    {
        return new self(now()->startOfMonth(), now()->endOfDay(), 'This month');
    }

    public static function lastMonth(): self
    {
        $start = now()->subMonthNoOverflow()->startOfMonth();

        return new self($start, $start->copy()->endOfMonth(), 'Last month');
    }

    /**
     * The windows the screen offers, keyed by what a URL carries.
     *
     * Mixed keys, honestly typed: PHP turns the numeric ones straight back into
     * ints however they are written here, and the named ones stay strings.
     *
     * @return array<int|string, string>
     */
    public static function options(): array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            'this-month' => 'This month',
            'last-month' => 'Last month',
        ];
    }

    /**
     * A window from what the URL carried, falling back rather than failing —
     * a hand-edited query string should show a sensible page, not an error.
     */
    public static function fromKey(string $key): self
    {
        return match ($key) {
            '7' => self::days(7),
            '90' => self::days(90),
            'this-month' => self::thisMonth(),
            'last-month' => self::lastMonth(),
            default => self::days(30),
        };
    }

    /**
     * How many whole days the window covers, at least one.
     *
     * Named dayCount() rather than days() because the static constructor above
     * already owns that name — a class cannot have both.
     */
    public function dayCount(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to) + 1);
    }
}
