<?php

namespace App\Domain\Sales\Enums;

/**
 * Where a quote stands.
 *
 * The transitions are enforced in one place because they are the difference
 * between a document and a field. A quote that can go from accepted back to
 * draft is not a quote — it is a form somebody can rewrite after the customer
 * agreed to it, and 6.4 turns accepted quotes into orders.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
        };
    }

    /**
     * The palette key the status chip renders. Quotes are cyan per the UI
     * standard; the outcomes are distinguished within that.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Sent => 'cyan',
            self::Accepted => 'emerald',
            self::Declined => 'rose',
            self::Expired, self::Superseded => 'slate',
        };
    }

    /**
     * Whether the quote can still be edited.
     *
     * Only a draft. Once it has gone to the customer, changing it would mean
     * the copy they hold and the copy here are different documents with the
     * same number — which is the reason versioning exists.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether this is the live version, as opposed to history.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Draft, self::Sent => true,
            default => false,
        };
    }

    /**
     * Whether a new version can be raised from here.
     *
     * Not from a draft: a draft is already editable, and "revising" it would
     * leave an empty superseded row behind for nothing.
     */
    public function canBeRevised(): bool
    {
        return match ($this) {
            self::Sent, self::Declined, self::Expired => true,
            default => false,
        };
    }

    /**
     * The moves this status allows.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent],
            // Expired is reachable from Sent by the clock rather than by a
            // person, but it is the same move either way.
            self::Sent => [self::Accepted, self::Declined, self::Expired, self::Superseded],
            self::Declined, self::Expired => [self::Superseded],
            // An accepted quote is what an order is made from. Nothing moves it.
            self::Accepted, self::Superseded => [],
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
