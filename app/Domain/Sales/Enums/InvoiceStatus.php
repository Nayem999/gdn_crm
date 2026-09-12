<?php

namespace App\Domain\Sales\Enums;

/**
 * What an invoice **is** — not whether it has been paid.
 *
 * Three cases, because those are the three decisions somebody makes about an
 * invoice. Payment is not a decision: it follows from the money received, and
 * putting "paid" in here would let the document's state and its payments
 * disagree — which means either chasing a customer who has paid, or not
 * chasing one who has not.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Issued => 'cyan',
            self::Cancelled => 'rose',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether money can be recorded against it.
     *
     * Not a draft: a payment against something never sent is money that arrived
     * for a document the customer has never seen, and recording it there hides
     * the real question of what it was for.
     */
    public function acceptsPayment(): bool
    {
        return $this === self::Issued;
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            self::Issued => [self::Cancelled],
            self::Cancelled => [],
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
