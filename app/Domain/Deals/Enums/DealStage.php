<?php

namespace App\Domain\Deals\Enums;

/**
 * Where a deal has got to.
 *
 * Task 3.1 makes stages configurable per pipeline; this becomes the default
 * pipeline rather than being replaced, so the values here are the ones an
 * out-of-the-box installation starts with.
 */
enum DealStage: string
{
    case New = 'new';
    case Qualification = 'qualification';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Qualification => 'Qualification',
            self::Proposal => 'Proposal',
            self::Negotiation => 'Negotiation',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'slate',
            self::Qualification => 'blue',
            self::Proposal => 'violet',
            self::Negotiation => 'amber',
            self::Won => 'emerald',
            self::Lost => 'rose',
        };
    }

    /**
     * How likely this stage is to close, as a percentage.
     *
     * Task 3.1 makes this a per-stage setting; these are the defaults, and the
     * weighted value in 3.2 reads it from here until then.
     */
    public function probability(): int
    {
        return match ($this) {
            self::New => 10,
            self::Qualification => 25,
            self::Proposal => 50,
            self::Negotiation => 75,
            self::Won => 100,
            self::Lost => 0,
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * The stages in the order a deal moves through them.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return self::cases();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $stage) {
            $options[$stage->value] = $stage->label();
        }

        return $options;
    }
}
