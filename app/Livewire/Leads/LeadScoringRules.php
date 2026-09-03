<?php

namespace App\Livewire\Leads;

use App\Domain\Leads\Actions\SaveLeadScoringRulesAction;
use App\Domain\Leads\Actions\ScoreLeadsAction;
use App\Domain\Leads\Enums\LeadGrade;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\FilterValueMode;
use App\Domain\Shared\Filters\FilterField;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How leads are scored, and what a lead must satisfy before it can be
 * qualified.
 *
 * Rows are a flat list keyed by index so wire:model paths stay stable while the
 * view groups them by kind. Nothing submitted is trusted: the field and
 * operator are checked against LeadFields::filters() here for the message, and
 * again in SaveLeadScoringRulesAction for the write.
 */
#[Title('Lead scoring')]
class LeadScoringRules extends Component
{
    use AuthorizesRequests;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rules = [];

    public ?string $saved = null;

    public function mount(): void
    {
        $this->authorize('viewAny', LeadScoringRule::class);

        $this->loadRules();
    }

    // -- Rows -----------------------------------------------------------------

    public function addRule(string $kind): void
    {
        $this->authorize('update', LeadScoringRule::class);

        $ruleKind = LeadRuleKind::tryFrom($kind);

        if ($ruleKind === null) {
            return;
        }

        $firstKey = (string) array_key_first($this->fieldOptionsFor($ruleKind));

        $this->rules[] = [
            'id' => null,
            'kind' => $ruleKind->value,
            'label' => '',
            'field' => $firstKey,
            'operator' => $this->defaultOperatorFor($firstKey),
            'value' => null,
            'second_value' => null,
            'selected' => [],
            'points' => $ruleKind === LeadRuleKind::Score ? 10 : 0,
            'is_active' => true,
        ];

        $this->saved = null;
    }

    public function removeRule(int $index): void
    {
        $this->authorize('update', LeadScoringRule::class);

        unset($this->rules[$index]);

        // Re-index so the wire:model paths stay contiguous.
        $this->rules = array_values($this->rules);
        $this->saved = null;
    }

    /**
     * Changing the field can leave the operator and value nonsensical, so both
     * are reset to something the new field actually offers.
     */
    public function updated(string $property): void
    {
        if (preg_match('/^rules\.(\d+)\.field$/', $property, $matches) !== 1) {
            return;
        }

        $index = (int) $matches[1];

        if (! isset($this->rules[$index])) {
            return;
        }

        $this->rules[$index]['operator'] = $this->defaultOperatorFor((string) $this->rules[$index]['field']);
        $this->rules[$index]['value'] = null;
        $this->rules[$index]['second_value'] = null;
        $this->rules[$index]['selected'] = [];
    }

    // -- Saving ---------------------------------------------------------------

    public function save(): void
    {
        $this->authorize('update', LeadScoringRule::class);

        $this->resetErrorBag();

        foreach ($this->rules as $index => $rule) {
            $this->validateRule($index, $rule);
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $stored = app(SaveLeadScoringRulesAction::class)($this->rules);

        $this->loadRules();
        $this->saved = $stored === 1
            ? '1 rule saved. Every lead is being rescored.'
            : $stored.' rules saved. Every lead is being rescored.';
    }

    /**
     * Rescore without changing anything, for after an import or a data fix.
     */
    public function recalculate(): void
    {
        $this->authorize('update', LeadScoringRule::class);

        $count = app(ScoreLeadsAction::class)();

        $this->saved = $count === 1 ? '1 lead rescored.' : $count.' leads rescored.';
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateRule(int $index, array $rule): void
    {
        $kind = LeadRuleKind::tryFrom((string) ($rule['kind'] ?? ''));
        $label = trim((string) ($rule['label'] ?? ''));
        $field = LeadFields::filters()[(string) ($rule['field'] ?? '')] ?? null;
        $operator = FilterOperator::tryFrom((string) ($rule['operator'] ?? ''));

        if ($label === '') {
            $this->addError("rules.{$index}.label", 'Give this rule a name so the trail reads sensibly.');
        } elseif (mb_strlen($label) > 120) {
            $this->addError("rules.{$index}.label", 'Keep the name under 120 characters.');
        }

        if ($field === null) {
            $this->addError("rules.{$index}.field", 'Choose a field the leads list offers.');

            return;
        }

        if ($kind === LeadRuleKind::Score && $field->key === LeadFields::SCORE) {
            $this->addError("rules.{$index}.field", 'A scoring rule cannot read the score it helps compute.');

            return;
        }

        if ($operator === null || ! in_array($operator, $field->type->operators(), true)) {
            $this->addError(
                "rules.{$index}.operator",
                'That comparison does not apply to '.strtolower($field->label).'.'
            );

            return;
        }

        $this->validateRuleValue($index, $rule, $operator);

        if ($kind !== LeadRuleKind::Score) {
            return;
        }

        if (! is_numeric($rule['points'] ?? null)) {
            $this->addError("rules.{$index}.points", 'Points must be a whole number.');
        } elseif (abs((int) $rule['points']) > 100) {
            $this->addError("rules.{$index}.points", 'Keep points between -100 and 100.');
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateRuleValue(int $index, array $rule, FilterOperator $operator): void
    {
        $missing = fn (mixed $value): bool => $value === null || $value === '' || $value === [];

        match ($operator->valueMode()) {
            FilterValueMode::None => null,
            FilterValueMode::Single => $missing($rule['value'] ?? null)
                ? $this->addError("rules.{$index}.value", 'This comparison needs a value.')
                : null,
            FilterValueMode::Pair => $missing($rule['value'] ?? null) || $missing($rule['second_value'] ?? null)
                ? $this->addError("rules.{$index}.value", 'Give both ends of the range.')
                : null,
            FilterValueMode::Multiple => $missing($rule['selected'] ?? null)
                ? $this->addError("rules.{$index}.selected", 'Choose at least one option.')
                : null,
        };
    }

    // -- Options --------------------------------------------------------------

    /**
     * @return array<string, FilterField>
     */
    public function fields(): array
    {
        return LeadFields::filters();
    }

    /**
     * @return array<string, string>
     */
    public function fieldOptionsFor(LeadRuleKind $kind): array
    {
        $options = [];

        foreach (LeadFields::filters() as $field) {
            if ($kind === LeadRuleKind::Score && $field->key === LeadFields::SCORE) {
                continue;
            }

            $options[$field->key] = $field->label;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function operatorOptionsFor(string $fieldKey): array
    {
        $field = LeadFields::filters()[$fieldKey] ?? null;

        return $field === null ? [] : FilterOperator::optionsFor($field->type);
    }

    /**
     * @return array<int, LeadRuleKind>
     */
    public function kinds(): array
    {
        return LeadRuleKind::cases();
    }

    /**
     * How the bands read, so an administrator setting points knows what 60 buys.
     *
     * @return array<int, LeadGrade>
     */
    public function grades(): array
    {
        return LeadGrade::descending();
    }

    /**
     * Row indexes belonging to one kind, in order, so the view can group a flat
     * list without moving anything.
     *
     * @return array<int, int>
     */
    public function indexesFor(LeadRuleKind $kind): array
    {
        $indexes = [];

        foreach ($this->rules as $index => $rule) {
            if (($rule['kind'] ?? null) === $kind->value) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    public function render(): View
    {
        return view('livewire.leads.lead-scoring-rules');
    }

    private function loadRules(): void
    {
        $this->rules = LeadScoringRule::query()
            ->ordered()
            ->get()
            ->map(fn (LeadScoringRule $rule): array => [
                'id' => $rule->id,
                'kind' => $rule->kind,
                'label' => $rule->label,
                'field' => $rule->field,
                'operator' => $rule->operator,
                'value' => $rule->value,
                'second_value' => $rule->second_value,
                'selected' => $rule->selected ?? [],
                'points' => $rule->points,
                'is_active' => $rule->is_active,
            ])
            ->all();
    }

    private function defaultOperatorFor(string $fieldKey): string
    {
        return (string) array_key_first($this->operatorOptionsFor($fieldKey));
    }
}
