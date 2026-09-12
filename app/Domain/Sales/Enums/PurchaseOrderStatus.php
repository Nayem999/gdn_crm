<?php

namespace App\Domain\Sales\Enums;

/**
 * Where a purchase order stands.
 *
 * "Ordered" rather than "confirmed": the commitment on a purchase order is ours
 * and the moment that matters is when it went to the supplier.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Ordered => 'cyan',
            self::Received => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::Received, self::Cancelled],
            self::Received, self::Cancelled => [],
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
