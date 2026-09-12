<?php

namespace App\Domain\Products\Enums;

/**
 * How a bundle arrives at its price.
 *
 * Three modes because all three are real commercial arrangements, and which one
 * applies is not derivable from anything else:
 *
 * - **Fixed**: the bundle has its own price, unrelated to its parts. What a
 *   bundle is usually for.
 * - **Sum**: it is priced at whatever its parts come to. Useful when the bundle
 *   exists for convenience rather than for a discount, and it keeps itself in
 *   step when a component's price changes.
 * - **Sum less a percentage**: the parts, minus an agreed saving. The
 *   "10% off when bought together" arrangement, expressed once rather than as a
 *   fixed price somebody has to remember to update.
 */
enum BundlePricing: string
{
    case Fixed = 'fixed';
    case Sum = 'sum';
    case SumLessPercent = 'sum_less_percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Its own price',
            self::Sum => 'What the parts come to',
            self::SumLessPercent => 'The parts, less a percentage',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fixed => 'The bundle is priced on its own, whatever its parts cost.',
            self::Sum => 'Adds up the parts at their own prices, and follows them when they change.',
            self::SumLessPercent => 'Adds up the parts and takes an agreed saving off.',
        };
    }

    public function usesComponents(): bool
    {
        return $this !== self::Fixed;
    }

    public function needsPercentage(): bool
    {
        return $this === self::SumLessPercent;
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
