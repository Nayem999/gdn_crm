<?php

namespace App\Domain\Sales\Enums;

/**
 * Where a sales order stands.
 *
 * Deliberately shorter than a quote's flow: an order is a commitment already
 * made, so there is no "declined" — a customer who changes their mind cancels,
 * which is a different fact and reads differently in a report.
 */
enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Confirmed => 'cyan',
            self::Fulfilled => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether an invoice can be raised from here.
     *
     * Not from a draft: invoicing something nobody has confirmed is how a
     * customer receives a bill for an order they never placed.
     */
    public function canBeInvoiced(): bool
    {
        return $this === self::Confirmed || $this === self::Fulfilled;
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Fulfilled, self::Cancelled],
            // Both terminal: an order that was delivered or called off does not
            // go back to being open.
            self::Fulfilled, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
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
