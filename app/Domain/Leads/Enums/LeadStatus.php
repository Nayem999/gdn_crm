<?php

namespace App\Domain\Leads\Enums;

/**
 * Where a lead has got to.
 *
 * The allowed moves are declared here rather than left to whoever happens to be
 * writing a screen, so a drag on the board, a form save and an API call all
 * agree on what is possible.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Nurturing = 'nurturing';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Nurturing => 'Nurturing',
            self::Qualified => 'Qualified',
            self::Unqualified => 'Unqualified',
            self::Converted => 'Converted',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::New => 'Just arrived, nobody has spoken to them.',
            self::Contacted => 'Someone has made contact.',
            self::Nurturing => 'Interested, but not ready yet.',
            self::Qualified => 'Worth pursuing as an opportunity.',
            self::Unqualified => 'Not a fit, or gone quiet.',
            self::Converted => 'Became an account, contact and deal.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::Contacted => 'cyan',
            self::Nurturing => 'amber',
            self::Qualified => 'violet',
            self::Unqualified => 'slate',
            self::Converted => 'emerald',
        };
    }

    /**
     * Nothing follows a converted or unqualified lead by dragging; converted is
     * reached only through conversion, and reopening is an explicit action.
     */
    public function isClosed(): bool
    {
        return $this === self::Converted;
    }

    /**
     * Which statuses may follow this one.
     *
     * Converted is deliberately absent everywhere: it is set by
     * ConvertLeadAction (task 2.6) once the account, contact and deal exist, so
     * a board drag cannot claim a conversion that never happened.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Nurturing, self::Unqualified],
            self::Contacted => [self::Nurturing, self::Qualified, self::Unqualified],
            self::Nurturing => [self::Contacted, self::Qualified, self::Unqualified],
            self::Qualified => [self::Contacted, self::Nurturing, self::Unqualified],
            // Reopening a dead lead is allowed; it lands back in Contacted
            // rather than pretending it was never touched.
            self::Unqualified => [self::Contacted, self::Nurturing],
            self::Converted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The statuses a board or a form should offer, in pipeline order.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [self::New, self::Contacted, self::Nurturing, self::Qualified, self::Unqualified, self::Converted];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::pipeline() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
