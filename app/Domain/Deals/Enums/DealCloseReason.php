<?php

namespace App\Domain\Deals\Enums;

/**
 * Why a deal ended.
 *
 * Each reason declares which outcome it belongs to, so a won deal can never be
 * recorded as lost on price — the pairing is checked rather than left to
 * whoever is filling in the form.
 *
 * Fixed rather than configurable on purpose: these are the buckets the Phase 10
 * win/loss report groups by, and a free-text list turns that report into a
 * thousand buckets of one. A customer-specific nuance goes in `close_notes`.
 */
enum DealCloseReason: string
{
    // Won
    case BestFit = 'best_fit';
    case Price = 'price';
    case Relationship = 'relationship';
    case Features = 'features';

    // Lost
    case LostOnPrice = 'lost_on_price';
    case LostToCompetitor = 'lost_to_competitor';
    case NoBudget = 'no_budget';
    case NoDecision = 'no_decision';
    case WrongFit = 'wrong_fit';
    case WentQuiet = 'went_quiet';

    public function label(): string
    {
        return match ($this) {
            self::BestFit => 'Best fit for their need',
            self::Price => 'Our pricing won it',
            self::Relationship => 'Existing relationship',
            self::Features => 'Product capability',
            self::LostOnPrice => 'Too expensive',
            self::LostToCompetitor => 'Lost to a competitor',
            self::NoBudget => 'No budget',
            self::NoDecision => 'No decision made',
            self::WrongFit => 'Not the right fit',
            self::WentQuiet => 'Went quiet',
        };
    }

    /**
     * The stage outcome this reason belongs with.
     */
    public function outcome(): StageOutcome
    {
        return match ($this) {
            self::BestFit, self::Price, self::Relationship, self::Features => StageOutcome::Won,
            default => StageOutcome::Lost,
        };
    }

    public function color(): string
    {
        return $this->outcome() === StageOutcome::Won ? 'emerald' : 'rose';
    }

    public function isFor(StageOutcome $outcome): bool
    {
        return $this->outcome() === $outcome;
    }

    /**
     * The reasons that go with one outcome.
     *
     * @return array<int, self>
     */
    public static function forOutcome(StageOutcome $outcome): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason) => $reason->isFor($outcome)
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function options(?StageOutcome $outcome = null): array
    {
        $cases = $outcome === null ? self::cases() : self::forOutcome($outcome);

        $options = [];

        foreach ($cases as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }

    /**
     * Rich rows for `<x-select>`, grouped by what they mean.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function selectOptions(?StageOutcome $outcome = null): array
    {
        $cases = $outcome === null ? self::cases() : self::forOutcome($outcome);

        return array_map(fn (self $reason) => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'description' => $reason->outcome()->label(),
            'color' => $reason->color(),
        ], $cases);
    }
}
