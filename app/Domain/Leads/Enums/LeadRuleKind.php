<?php

namespace App\Domain\Leads\Enums;

/**
 * What a lead rule means when it matches.
 */
enum LeadRuleKind: string
{
    /** Matching adds the rule's points to the lead's score. */
    case Score = 'score';

    /** Matching is required before a lead may be marked Qualified. */
    case Qualification = 'qualification';

    public function label(): string
    {
        return match ($this) {
            self::Score => 'Scoring rule',
            self::Qualification => 'Qualification requirement',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Score => 'Adds points to a lead that matches it.',
            self::Qualification => 'A lead must match it before it can be qualified.',
        };
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
