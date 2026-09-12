<?php

namespace App\Domain\Products\Enums;

/**
 * What a catalogue row is.
 *
 * A bundle is a kind of product rather than a separate table, so everything
 * that sells something — a quote line, a deal's attached products, a price book
 * entry — handles one shape instead of two.
 */
enum ProductKind: string
{
    case Product = 'product';
    case Service = 'service';
    case Bundle = 'bundle';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
            self::Bundle => 'Bundle',
        };
    }

    /**
     * The palette key the status chip renders. Products are orange per the UI
     * standard; the other two are distinguished within that family.
     */
    public function color(): string
    {
        return match ($this) {
            self::Product => 'orange',
            self::Service => 'blue',
            self::Bundle => 'violet',
        };
    }

    /**
     * Whether this kind is made of other catalogue rows.
     */
    public function hasComponents(): bool
    {
        return $this === self::Bundle;
    }

    /**
     * Whether a unit cost is a meaningful thing to record.
     *
     * A bundle's cost is the sum of what is in it, so storing one beside the
     * components would be a second answer that drifts from the first.
     */
    public function hasOwnCost(): bool
    {
        return $this !== self::Bundle;
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
