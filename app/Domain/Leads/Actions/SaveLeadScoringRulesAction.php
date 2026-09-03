<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\Enums\FilterOperator;
use App\Jobs\RescoreLeads;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the rule set with what the administrator submitted, then rescores.
 *
 * Rows are matched by id so an edit keeps the rule's identity and its audit
 * history; a row whose id is gone from the submission is deleted. Anything
 * naming a field or operator the leads list does not offer is dropped here as
 * well as in the screen's validation — the same belt-and-braces registry check
 * PermissionCatalogue::only() does for permissions.
 */
class SaveLeadScoringRulesAction
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return int How many rules were stored.
     */
    public function __invoke(array $rows): int
    {
        $clean = [];

        foreach (array_values($rows) as $position => $row) {
            $attributes = $this->sanitise($row, $position);

            if ($attributes !== null) {
                $clean[] = ['id' => $this->id($row), 'attributes' => $attributes];
            }
        }

        DB::transaction(function () use ($clean) {
            $kept = array_values(array_filter(array_column($clean, 'id')));

            LeadScoringRule::query()
                ->when($kept !== [], fn ($query) => $query->whereKeyNot($kept))
                ->get()
                ->each
                ->delete();

            foreach ($clean as $rule) {
                $existing = $rule['id'] === null
                    ? null
                    : LeadScoringRule::query()->find($rule['id']);

                if ($existing === null) {
                    LeadScoringRule::create($rule['attributes']);

                    continue;
                }

                $existing->update($rule['attributes']);
            }
        });

        RescoreLeads::dispatch();

        return count($clean);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null Null when the row cannot be stored.
     */
    private function sanitise(array $row, int $position): ?array
    {
        $kind = LeadRuleKind::tryFrom((string) ($row['kind'] ?? ''));
        $field = LeadFields::filters()[(string) ($row['field'] ?? '')] ?? null;
        $operator = FilterOperator::tryFrom((string) ($row['operator'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));

        if ($kind === null || $field === null || $operator === null || $label === '') {
            return null;
        }

        if (! in_array($operator, $field->type->operators(), true)) {
            return null;
        }

        // A scoring rule cannot read the score it helps compute.
        if ($kind === LeadRuleKind::Score && $field->key === LeadFields::SCORE) {
            return null;
        }

        $selected = array_values(array_filter(
            array_map(
                fn (mixed $value) => (string) (is_scalar($value) ? $value : ''),
                is_array($row['selected'] ?? null) ? $row['selected'] : []
            ),
            fn (string $value) => $value !== '' && array_key_exists($value, $field->options)
        ));

        return [
            'kind' => $kind->value,
            'label' => $label,
            'field' => $field->key,
            'operator' => $operator->value,
            'value' => $this->scalar($row['value'] ?? null),
            'second_value' => $this->scalar($row['second_value'] ?? null),
            'selected' => $selected,
            // Points are meaningless on a requirement, which is pass or fail.
            'points' => $kind === LeadRuleKind::Score ? (int) ($row['points'] ?? 0) : 0,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'position' => $position,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function id(array $row): ?int
    {
        $id = $row['id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
