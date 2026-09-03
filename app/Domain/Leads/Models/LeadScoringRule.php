<?php

namespace App\Domain\Leads\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\LeadFields;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterCondition;
use App\Domain\Shared\Filters\FilterField;
use Database\Factories\LeadScoringRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One admin-defined rule about leads.
 *
 * The condition is stored in the same shape the filter builder uses, and is
 * turned back into a FilterCondition to be evaluated. That is deliberate: a
 * rule saying "estimated value is at least 5000" means exactly what the filter
 * chip with the same condition means, because it is the same code.
 *
 * @property int $id
 * @property string $kind
 * @property string $label
 * @property string $field
 * @property string $operator
 * @property string|null $value
 * @property string|null $second_value
 * @property array<int, string>|null $selected
 * @property int $points
 * @property bool $is_active
 * @property int $position
 */
class LeadScoringRule extends Model
{
    /** @use HasFactory<LeadScoringRuleFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'label',
        'field',
        'operator',
        'value',
        'second_value',
        'selected',
        'points',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'selected' => 'array',
            'points' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Changing how leads are scored is an administrative act, so the whole rule
     * is on the trail.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['kind', 'label', 'field', 'operator', 'value', 'second_value', 'points', 'is_active'];
    }

    public function kind(): LeadRuleKind
    {
        return LeadRuleKind::tryFrom($this->kind) ?? LeadRuleKind::Score;
    }

    /**
     * The lead field this rule reads, or null if it names one the leads list no
     * longer offers.
     */
    public function filterField(): ?FilterField
    {
        return LeadFields::filters()[$this->field] ?? null;
    }

    public function condition(): FilterCondition
    {
        return new FilterCondition(
            field: $this->field,
            operator: FilterOperator::tryFrom($this->operator) ?? FilterOperator::Equals,
            value: $this->value,
            secondValue: $this->second_value,
            selected: $this->selected ?? [],
        );
    }

    /**
     * Whether this rule can actually be evaluated. A rule left half-filled, or
     * pointing at a field that has since been removed, never matches anything —
     * it must not silently match everything instead.
     */
    public function isUsable(): bool
    {
        $field = $this->filterField();

        return $field !== null && $this->condition()->isUsable($field);
    }

    /**
     * The condition as a human phrase, e.g. "Estimated value is at least 5000".
     */
    public function summary(): string
    {
        $field = $this->filterField();

        return $field === null
            ? 'Unknown field "'.$this->field.'"'
            : ucfirst($this->condition()->summary($field));
    }

    /**
     * @param  Builder<LeadScoringRule>  $query
     * @return Builder<LeadScoringRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<LeadScoringRule>  $query
     * @return Builder<LeadScoringRule>
     */
    public function scopeOfKind(Builder $query, LeadRuleKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /**
     * @param  Builder<LeadScoringRule>  $query
     * @return Builder<LeadScoringRule>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
