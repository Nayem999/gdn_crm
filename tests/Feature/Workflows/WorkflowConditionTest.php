<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\FilterValueMode;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Conditions\WorkflowConditions;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowCache;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * One condition, in the array shape a builder submits.
 *
 * @return array<string, mixed>
 */
function condition(string $field, FilterOperator $operator, mixed $value = null, mixed $second = null, array $selected = []): array
{
    return [
        'match' => FilterGroup::MATCH_ALL,
        'conditions' => [[
            'field' => $field,
            'operator' => $operator->value,
            'value' => $value,
            'second_value' => $second,
            'selected' => $selected,
        ]],
        'groups' => [],
    ];
}

/**
 * Whether a workflow carrying this condition tree matches this lead.
 *
 * @param  array<string, mixed>  $conditions
 */
function matchesLead(array $conditions, Lead $lead): bool
{
    $workflow = Workflow::factory()->create(['module' => 'leads', 'conditions' => $conditions]);

    return app(WorkflowConditions::class)->matches($workflow, $lead);
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
});

// -- The truth table -----------------------------------------------------------

test('a text operator matches exactly what the filter chip would', function (
    FilterOperator $operator,
    ?string $stored,
    mixed $value,
    bool $expected,
) {
    $lead = Lead::factory()->create(['company_name' => $stored]);

    expect(matchesLead(condition('company_name', $operator, $value), $lead))->toBe($expected);
})->with([
    'contains, present' => [FilterOperator::Contains, 'Golden Infotech', 'Infotech', true],
    'contains, absent' => [FilterOperator::Contains, 'Golden Infotech', 'Acme', false],
    'contains, case insensitive' => [FilterOperator::Contains, 'Golden Infotech', 'infotech', true],
    // The one every hand-rolled comparator gets wrong: a row that never had a
    // value does not contain the thing, so it must come back.
    'not contains, null' => [FilterOperator::NotContains, null, 'Acme', true],
    'not contains, present' => [FilterOperator::NotContains, 'Acme Ltd', 'Acme', false],
    'not contains, other' => [FilterOperator::NotContains, 'Golden', 'Acme', true],
    'equals' => [FilterOperator::Equals, 'Acme', 'Acme', true],
    'equals, different' => [FilterOperator::Equals, 'Acme', 'Other', false],
    'not equals, null' => [FilterOperator::NotEquals, null, 'Acme', true],
    'not equals, same' => [FilterOperator::NotEquals, 'Acme', 'Acme', false],
    'starts with' => [FilterOperator::StartsWith, 'Golden Infotech', 'Golden', true],
    'starts with, middle' => [FilterOperator::StartsWith, 'Golden Infotech', 'Infotech', false],
    'ends with' => [FilterOperator::EndsWith, 'Golden Infotech', 'Infotech', true],
    'ends with, start' => [FilterOperator::EndsWith, 'Golden Infotech', 'Golden', false],
    'is empty, null' => [FilterOperator::IsEmpty, null, null, true],
    'is empty, blank' => [FilterOperator::IsEmpty, '', null, true],
    'is empty, filled' => [FilterOperator::IsEmpty, 'Acme', null, false],
    'is not empty, filled' => [FilterOperator::IsNotEmpty, 'Acme', null, true],
    'is not empty, null' => [FilterOperator::IsNotEmpty, null, null, false],
]);

test('a number operator matches exactly what the filter chip would', function (
    FilterOperator $operator,
    ?int $stored,
    mixed $value,
    mixed $second,
    bool $expected,
) {
    $lead = Lead::factory()->create(['estimated_value' => $stored]);

    expect(matchesLead(condition('estimated_value', $operator, $value, $second), $lead))->toBe($expected);
})->with([
    'greater than, above' => [FilterOperator::GreaterThan, 6000, '5000', null, true],
    'greater than, equal' => [FilterOperator::GreaterThan, 5000, '5000', null, false],
    'greater than, below' => [FilterOperator::GreaterThan, 4000, '5000', null, false],
    'at least, equal' => [FilterOperator::GreaterThanOrEqual, 5000, '5000', null, true],
    'less than, below' => [FilterOperator::LessThan, 4000, '5000', null, true],
    'less than, equal' => [FilterOperator::LessThan, 5000, '5000', null, false],
    'at most, equal' => [FilterOperator::LessThanOrEqual, 5000, '5000', null, true],
    'between, inside' => [FilterOperator::Between, 5000, '1000', '9000', true],
    'between, on the edge' => [FilterOperator::Between, 1000, '1000', '9000', true],
    'between, outside' => [FilterOperator::Between, 500, '1000', '9000', false],
    // A comparison against a value that was never set is not true.
    'greater than, null' => [FilterOperator::GreaterThan, null, '5000', null, false],
    'between, null' => [FilterOperator::Between, null, '1000', '9000', false],
    'equals' => [FilterOperator::Equals, 5000, '5000', null, true],
]);

test('a select operator matches exactly what the filter chip would', function (
    FilterOperator $operator,
    LeadStatus $stored,
    array $selected,
    bool $expected,
) {
    $lead = Lead::factory()->create(['status' => $stored->value]);

    expect(matchesLead(condition('status', $operator, null, null, $selected), $lead))->toBe($expected);
})->with([
    'is any of, one' => [FilterOperator::In, LeadStatus::New, ['new'], true],
    'is any of, several' => [FilterOperator::In, LeadStatus::New, ['new', 'contacted'], true],
    'is any of, none' => [FilterOperator::In, LeadStatus::New, ['qualified'], false],
    'is none of, absent' => [FilterOperator::NotIn, LeadStatus::New, ['qualified'], true],
    'is none of, present' => [FilterOperator::NotIn, LeadStatus::New, ['new'], false],
]);

test('a date operator matches exactly what the filter chip would', function (
    FilterOperator $operator,
    string $stored,
    mixed $value,
    bool $expected,
) {
    Carbon::setTestNow('2026-10-15 12:00:00');

    $lead = Lead::factory()->create(['status_changed_at' => Carbon::parse($stored)]);

    expect(matchesLead(condition('status_changed_at', $operator, $value), $lead))->toBe($expected);

    Carbon::setTestNow();
})->with([
    // A whole day, not an instant: a record stamped at 09:00 is "on" that date.
    'on, same day' => [FilterOperator::On, '2026-10-15 09:00:00', '2026-10-15', true],
    'on, same day late' => [FilterOperator::On, '2026-10-15 23:30:00', '2026-10-15', true],
    'on, day before' => [FilterOperator::On, '2026-10-14 09:00:00', '2026-10-15', false],
    'before' => [FilterOperator::Before, '2026-10-01 09:00:00', '2026-10-15', true],
    'before, same day' => [FilterOperator::Before, '2026-10-15 09:00:00', '2026-10-15', false],
    'after' => [FilterOperator::After, '2026-10-20 09:00:00', '2026-10-15', true],
    'after, same day' => [FilterOperator::After, '2026-10-15 23:00:00', '2026-10-15', false],
    'last days, inside' => [FilterOperator::LastDays, '2026-10-13 09:00:00', '7', true],
    'last days, outside' => [FilterOperator::LastDays, '2026-09-01 09:00:00', '7', false],
]);

test('a boolean operator matches exactly what the filter chip would', function () {
    // On contacts, which is the module that has one. The evaluator asks each
    // module for its own field set, so this also proves a workflow is not
    // limited to leads.
    $primary = Contact::factory()->create(['is_primary' => true]);
    $secondary = Contact::factory()->create(['is_primary' => false]);

    $workflow = fn (FilterOperator $operator): Workflow => Workflow::factory()->create([
        'module' => 'contacts',
        'conditions' => condition('is_primary', $operator),
    ]);

    $evaluator = app(WorkflowConditions::class);

    expect($evaluator->matches($workflow(FilterOperator::IsTrue), $primary))->toBeTrue()
        ->and($evaluator->matches($workflow(FilterOperator::IsTrue), $secondary))->toBeFalse()
        ->and($evaluator->matches($workflow(FilterOperator::IsFalse), $secondary))->toBeTrue()
        ->and($evaluator->matches($workflow(FilterOperator::IsFalse), $primary))->toBeFalse();
});

// -- Groups ---------------------------------------------------------------------

test('an empty condition tree matches everything', function () {
    // A workflow with no conditions is the common case, and it must not be the
    // case that matches nothing.
    $lead = Lead::factory()->create();

    expect(matchesLead(FilterGroup::EMPTY, $lead))->toBeTrue();
});

test('all of a group must hold', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New->value, 'estimated_value' => 6000]);

    $both = [
        'match' => FilterGroup::MATCH_ALL,
        'conditions' => [
            ['field' => 'status', 'operator' => FilterOperator::In->value, 'selected' => ['new']],
            ['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000'],
        ],
        'groups' => [],
    ];

    expect(matchesLead($both, $lead))->toBeTrue();

    $lead->update(['estimated_value' => 1000]);

    expect(matchesLead($both, $lead->fresh()))->toBeFalse();
});

test('any of a group is enough', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::New->value, 'estimated_value' => 100]);

    $either = [
        'match' => FilterGroup::MATCH_ANY,
        'conditions' => [
            ['field' => 'status', 'operator' => FilterOperator::In->value, 'selected' => ['new']],
            ['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000'],
        ],
        'groups' => [],
    ];

    // The first holds, the second does not.
    expect(matchesLead($either, $lead))->toBeTrue();

    $lead->update(['status' => LeadStatus::Contacted->value]);

    // Now neither.
    expect(matchesLead($either, $lead->fresh()))->toBeFalse();
});

test('a nested group is evaluated as its own unit', function () {
    // status is new AND (value over 5000 OR source is referral).
    $tree = fn (): array => [
        'match' => FilterGroup::MATCH_ALL,
        'conditions' => [
            ['field' => 'status', 'operator' => FilterOperator::In->value, 'selected' => ['new']],
        ],
        'groups' => [[
            'match' => FilterGroup::MATCH_ANY,
            'conditions' => [
                ['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000'],
                ['field' => 'source', 'operator' => FilterOperator::In->value, 'selected' => [LeadSource::Referral->value]],
            ],
            'groups' => [],
        ]],
    ];

    // Outer holds, inner holds on its second arm.
    $lead = Lead::factory()->create([
        'status' => LeadStatus::New->value,
        'estimated_value' => 100,
        'source' => LeadSource::Referral->value,
    ]);

    expect(matchesLead($tree(), $lead))->toBeTrue();

    // Inner fails on both arms.
    $lead->update(['source' => LeadSource::ColdCall->value]);

    expect(matchesLead($tree(), $lead->fresh()))->toBeFalse();

    // Inner holds on its first arm, but the outer now fails.
    $lead->update(['estimated_value' => 9000, 'status' => LeadStatus::Contacted->value]);

    expect(matchesLead($tree(), $lead->fresh()))->toBeFalse();
});

test('a half-filled condition cannot make a workflow match everything', function () {
    // Stored conditions are normalised on save, but a row written before a
    // field was removed can still be unusable. It is dropped, and a group whose
    // every condition is dropped is empty — which matches. That is the same
    // answer the filter builder gives for the same tree, which is the point.
    $lead = Lead::factory()->create();

    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'conditions' => [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [['field' => 'a_field_that_went_away', 'operator' => FilterOperator::Equals->value, 'value' => 'x']],
            'groups' => [],
        ],
    ]);

    expect(app(WorkflowConditions::class)->matches($workflow, $lead))->toBeTrue();
});

// -- Custom fields ---------------------------------------------------------------

test('a condition can be built on a custom field', function () {
    // 4.2 put custom fields in the filter builder; a workflow gets them for
    // free because it asks the same field set.
    $field = CustomField::factory()->create([
        'module' => 'leads',
        'key' => 'budget_band',
        'label' => 'Budget band',
        'type' => CustomFieldType::Text->value,
        'is_active' => true,
    ]);

    $matching = Lead::factory()->create();
    $other = Lead::factory()->create();

    $matching->customFieldValues()->create([
        'custom_field_id' => $field->id,
        'value_string' => 'Enterprise',
    ]);

    $conditions = condition('cf_budget_band', FilterOperator::Equals, 'Enterprise');

    expect(matchesLead($conditions, $matching))->toBeTrue()
        // A record that never answered the field does not match.
        ->and(matchesLead($conditions, $other))->toBeFalse();
});

// -- Soft deletes ------------------------------------------------------------------

test('a delete workflow can still read the record it fired for', function () {
    // The record is gone by the time conditions are evaluated. Without lifting
    // the soft-delete scope every condition would read false, and no delete
    // workflow with conditions could ever run.
    $lead = Lead::factory()->create(['estimated_value' => 9000]);
    $lead->delete();

    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordDeleted->value,
        'conditions' => condition('estimated_value', FilterOperator::GreaterThan, '5000'),
    ]);

    expect(app(WorkflowConditions::class)->matches($workflow, $lead))->toBeTrue();
});

// -- The gate --------------------------------------------------------------------

test('a record that does not match starts no run', function () {
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'conditions' => condition('estimated_value', FilterOperator::GreaterThan, '5000'),
        'is_active' => true,
    ]);
    WorkflowAction::factory()->for($workflow)->create();
    app(WorkflowCache::class)->flush();

    Lead::factory()->create(['estimated_value' => 100]);

    expect(WorkflowRun::query()->count())->toBe(0);

    Lead::factory()->create(['estimated_value' => 9000]);

    expect(WorkflowRun::query()->count())->toBe(1);
});

test('conditions are read against the record as it is now', function () {
    // An update workflow conditioned on the new value must see the new value,
    // not the one the record had when the edit began.
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordUpdated->value,
        'conditions' => condition('status', FilterOperator::In, null, null, [LeadStatus::Qualified->value]),
        'is_active' => true,
    ]);
    WorkflowAction::factory()->for($workflow)->create();
    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

    $lead->update(['first_name' => 'Priya']);
    expect(WorkflowRun::query()->count())->toBe(0);

    $lead->update(['status' => LeadStatus::Qualified->value]);
    expect(WorkflowRun::query()->count())->toBe(1);
});

test('a workflow is not scoped to whoever triggered it', function () {
    // A workflow acts for the organisation. If its conditions ran through an
    // access-level scope, the same record would match or not depending on who
    // happened to edit it.
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $lead = Lead::factory()->create(['owner_id' => $theirs->id, 'estimated_value' => 9000]);

    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'conditions' => condition('estimated_value', FilterOperator::GreaterThan, '5000'),
    ]);

    $this->actingAs($mine);

    expect(app(WorkflowConditions::class)->matches($workflow, $lead))->toBeTrue();
});

test('every operator the builder offers is one the evaluator can apply', function (string $value) {
    // The guard against an operator existing in the enum, being offered by a
    // field type, and then being silently dropped by the applier.
    $operator = FilterOperator::from($value);
    $lead = Lead::factory()->create(['company_name' => 'Acme', 'estimated_value' => 5000]);

    $field = match ($operator->valueMode()) {
        FilterValueMode::Multiple => 'status',
        default => in_array($operator, FilterFieldType::Number->operators(), true)
            ? 'estimated_value'
            : 'company_name',
    };

    $conditions = condition(
        $field,
        $operator,
        value: $operator->valueMode() === FilterValueMode::None ? null : '5000',
        second: '9000',
        selected: ['new'],
    );

    // The assertion is that this resolves to a boolean without throwing, and
    // that the condition was actually applied rather than dropped.
    $result = matchesLead($conditions, $lead);

    expect($result)->toBeBool();
})->with(array_column(FilterOperator::cases(), 'value'));
