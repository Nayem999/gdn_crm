<?php

namespace App\Domain\Products\Enums;

/**
 * What one of something is.
 *
 * A fixed set rather than free text: the unit is printed on a quote line beside
 * a quantity, and "hr", "hour", "Hours" and "hrs" in one document is how a
 * quote starts looking like it was assembled by four people.
 */
enum ProductUnit: string
{
    case Each = 'each';
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
    case Licence = 'licence';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Each => 'Each',
            self::Hour => 'Hour',
            self::Day => 'Day',
            self::Month => 'Month',
            self::Year => 'Year',
            self::Licence => 'Licence',
            self::User => 'User',
        };
    }

    /**
     * How it reads on a line with a quantity: "3 hours", "1 licence".
     */
    public function forQuantity(float $quantity): string
    {
        $label = strtolower($this->label());

        return abs($quantity - 1.0) < 0.0001 ? $label : str($label)->plural()->toString();
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
