<?php

namespace App\Domain\Meta\Conversions\Enums;

/**
 * The CRM outcomes worth telling Meta about.
 *
 * Three, not everything. Meta's delivery optimises against what it is told, so
 * reporting every status change would teach it that a lead someone merely rang
 * is as valuable as one that paid — and the whole reason to send outcomes back
 * is to stop Meta optimising for volume it cannot tell apart.
 *
 * The names are what Meta receives. `Purchase` is a **standard** event, which
 * matters: standard events can be optimised against and appear in Ads Manager
 * without configuration, while a custom name has to be registered as a custom
 * conversion before it can be used for anything. The other two are custom on
 * purpose — there is no standard event for "this lead is worth talking to", and
 * inventing a mapping onto `Lead` would collide with the event Meta already
 * records when the form is submitted.
 */
enum ConversionOutcome: string
{
    case Qualified = 'qualified';
    case Opportunity = 'opportunity';
    case Won = 'won';

    /**
     * What Meta is told this event is called.
     */
    public function eventName(): string
    {
        return match ($this) {
            self::Qualified => 'Qualified',
            self::Opportunity => 'Opportunity',
            self::Won => 'Purchase',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'Lead qualified',
            self::Opportunity => 'Opportunity created',
            self::Won => 'Closed won',
        };
    }

    /**
     * Whether the money is part of this event.
     *
     * Only the win carries a value. An "opportunity worth £40,000" that closes
     * at £4,000 would have reported ten times the revenue it produced, and Meta
     * has no way to hear the correction.
     */
    public function carriesValue(): bool
    {
        return $this === self::Won;
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
