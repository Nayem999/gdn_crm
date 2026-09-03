<?php

namespace Database\Factories;

use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScoringRule>
 */
class LeadScoringRuleFactory extends Factory
{
    protected $model = LeadScoringRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => LeadRuleKind::Score->value,
            'label' => 'Has a company',
            'field' => 'company_name',
            'operator' => FilterOperator::IsNotEmpty->value,
            'value' => null,
            'second_value' => null,
            'selected' => [],
            'points' => 10,
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * A rule of the shape "field operator value", which is what most tests want.
     *
     * @param  array<int, string>  $selected
     */
    public function condition(
        string $field,
        FilterOperator $operator,
        mixed $value = null,
        mixed $secondValue = null,
        array $selected = [],
    ): static {
        return $this->state(fn () => [
            'field' => $field,
            'operator' => $operator->value,
            'value' => $value,
            'second_value' => $secondValue,
            'selected' => $selected,
        ]);
    }

    public function worth(int $points): static
    {
        return $this->state(fn () => ['points' => $points]);
    }

    public function requirement(): static
    {
        return $this->state(fn () => [
            'kind' => LeadRuleKind::Qualification->value,
            'points' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
