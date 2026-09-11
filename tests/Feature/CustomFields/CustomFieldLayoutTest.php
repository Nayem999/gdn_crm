<?php

use App\Domain\CustomFields\Actions\SaveCustomFieldAction;
use App\Domain\CustomFields\CustomFieldValidator;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Enums\VisibilityOperator;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\VisibilityCondition;
use App\Domain\Leads\Models\Lead;
use App\Livewire\CustomFields\CustomFieldsIndex;
use App\Livewire\Leads\LeadForm;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * A field with a layout and, optionally, a condition.
 *
 * @param  array{field: string, operator: string, value: string|null}|null  $when
 * @param  array<int, string>  $options
 */
function laidOutField(
    string $label,
    CustomFieldType $type = CustomFieldType::Text,
    ?string $section = null,
    ?array $when = null,
    bool $required = false,
    array $options = ['One', 'Two'],
): CustomField {
    return app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => $label,
        'type' => $type->value,
        'section' => $section,
        'visible_when' => $when,
        'is_required' => $required,
        'options' => array_map(fn (string $o) => ['label' => $o], $options),
    ]));
}

beforeEach(function () {
    Cache::flush();
});

// -- The condition itself ------------------------------------------------------

test('each operator decides visibility the way it reads', function (string $operator, mixed $actual, ?string $expected, bool $shown) {
    $condition = new VisibilityCondition('status', VisibilityOperator::from($operator), $expected);

    expect($condition->matches(['status' => $actual]))->toBe($shown);
})->with([
    ['equals', 'lost', 'lost', true],
    ['equals', 'won', 'lost', false],
    // Compared as text and case-insensitively: a form value arrives as a string
    // whatever its type, so the same answer typed differently still matches.
    ['equals', 'LOST', 'lost', true],
    ['equals', 30, '30', true],
    ['not_equals', 'won', 'lost', true],
    ['not_equals', 'lost', 'lost', false],
    ['contains', 'closed lost', 'lost', true],
    ['contains', 'closed won', 'lost', false],
    ['is_empty', '', null, true],
    ['is_empty', null, null, true],
    ['is_empty', [], null, true],
    ['is_empty', 'anything', null, false],
    ['is_not_empty', 'anything', null, true],
    ['is_not_empty', '', null, false],
    ['is_true', true, null, true],
    ['is_true', '1', null, true],
    ['is_true', false, null, false],
    ['is_true', '0', null, false],
    ['is_false', false, null, true],
    ['is_false', true, null, false],
]);

test('a multi-value answer matches when any of its entries does', function () {
    $condition = new VisibilityCondition('cf:sectors', VisibilityOperator::Equals, 'retail');

    expect($condition->matches(['cf:sectors' => ['wholesale', 'retail']]))->toBeTrue()
        ->and($condition->matches(['cf:sectors' => ['wholesale']]))->toBeFalse();
});

test('a field the form does not carry is treated as empty, not as a crash', function () {
    $condition = new VisibilityCondition('not_on_this_form', VisibilityOperator::IsEmpty);

    expect($condition->matches([]))->toBeTrue();
});

test('a half-filled condition is stored as none at all', function () {
    // A field chosen but no operator yet is not a rule that can never match.
    expect(VisibilityCondition::fromStored(['field' => 'status']))->toBeNull()
        ->and(VisibilityCondition::fromStored(['operator' => 'equals']))->toBeNull()
        ->and(VisibilityCondition::fromStored('nonsense'))->toBeNull();

    $field = laidOutField('Lost reason', when: ['field' => 'cf:x', 'operator' => 'equals', 'value' => null]);

    // "is" with nothing to compare against would never match, so it is dropped.
    expect($field->visible_when)->toBeNull()
        ->and($field->visibilityCondition())->toBeNull();
});

test('an operator that needs no value keeps its condition', function () {
    $field = laidOutField('Lost reason', when: ['field' => 'cf:x', 'operator' => 'is_not_empty']);

    expect($field->visibilityCondition()?->operator)->toBe(VisibilityOperator::IsNotEmpty);
});

// -- Layout --------------------------------------------------------------------

test('a field with no section of its own falls under the default heading', function () {
    $field = laidOutField('Sector');

    expect($field->section)->toBeNull()
        ->and($field->sectionName())->toBe(CustomField::DEFAULT_SECTION);
});

test('the form groups fields into their sections, in field order', function () {
    laidOutField('Sector', section: 'Qualification');
    laidOutField('Budget', type: CustomFieldType::Currency, section: 'Commercials');
    laidOutField('Timeline', section: 'Qualification');

    $sections = Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->instance()
        ->customFieldSections();

    // Sections appear in the order their first field does, so reordering
    // fields reorders sections — there is no second ordering to keep in step.
    expect(array_keys($sections))->toBe(['Qualification', 'Commercials'])
        ->and(collect($sections['Qualification'])->pluck('label')->all())->toBe(['Sector', 'Timeline'])
        ->and(collect($sections['Commercials'])->pluck('label')->all())->toBe(['Budget']);
});

test('the form renders a section heading for each group', function () {
    laidOutField('Sector', section: 'Qualification');

    Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->assertSee('Qualification')
        ->assertSee('Sector');
});

test('a full width field is marked as such', function () {
    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Notes',
        'type' => 'text',
        'is_full_width' => true,
    ]));

    expect($field->is_full_width)->toBeTrue();
});

// -- Conditions on the form ----------------------------------------------------

test('a conditional field is hidden until its condition holds', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $dependent = laidOutField('Lost reason', when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    $form = Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class);

    expect($form->instance()->isCustomFieldVisible($dependent))->toBeFalse();

    $form->set('customFields.'.$trigger->key, $keys[1]);

    expect($form->instance()->isCustomFieldVisible($dependent))->toBeTrue();

    $form->set('customFields.'.$trigger->key, $keys[0]);

    expect($form->instance()->isCustomFieldVisible($dependent))->toBeFalse();
});

test('a condition can name one of the form own fields, not just a custom one', function () {
    $dependent = laidOutField('Referral source', when: [
        'field' => 'source',
        'operator' => 'equals',
        'value' => 'referral',
    ]);

    $form = Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class);

    expect($form->instance()->isCustomFieldVisible($dependent))->toBeFalse();

    $form->set('source', 'referral');

    expect($form->instance()->isCustomFieldVisible($dependent))->toBeTrue();
});

test('a field with no condition is always shown', function () {
    $field = laidOutField('Sector');

    expect(Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->instance()
        ->isCustomFieldVisible($field))->toBeTrue();
});

// -- Validation ----------------------------------------------------------------

test('a hidden field is never required', function () {
    // The person cannot see it, so insisting on it makes the form
    // unsubmittable with no visible error.
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $required = laidOutField('Lost reason', required: true, when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->set('customFields.'.$trigger->key, $keys[0])
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1)
        ->and(Lead::query()->firstOrFail()->customField($required->key))->toBeNull();
});

test('a visible required field is still required', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $required = laidOutField('Lost reason', required: true, when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->set('customFields.'.$trigger->key, $keys[1])
        ->call('save')
        ->assertHasErrors('customFields.'.$required->key);
});

test('a hidden field is still type checked, because a stale value can survive', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $number = laidOutField('Headcount', type: CustomFieldType::Number, when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    Livewire::actingAs(customFieldUser(['leads.view', 'leads.create']))
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        // Typed while visible, then the condition turned against it.
        ->set('customFields.'.$number->key, 'not a number')
        ->set('customFields.'.$trigger->key, $keys[0])
        ->call('save')
        ->assertHasErrors('customFields.'.$number->key);
});

test('a hidden required tickbox does not have to be ticked', function () {
    // `accepted` is what makes a required checkbox mean "must be ticked", and
    // it passes on nothing — so it has to come off when the field is hidden.
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $box = laidOutField('Confirmed', type: CustomFieldType::Checkbox, required: true, when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    $fields = collect([$box]);
    $validator = app(CustomFieldValidator::class);

    $hidden = $validator->rules($fields, conditionValues: ['cf:'.$trigger->key => $keys[0]]);
    $shown = $validator->rules($fields, conditionValues: ['cf:'.$trigger->key => $keys[1]]);

    expect($hidden['customFields.'.$box->key])->not->toContain('accepted')
        ->and($shown['customFields.'.$box->key])->toContain('accepted');
});

test('a lookup on a hidden field is not checked against the viewer access level', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $lookup = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Parent account',
        'type' => 'lookup',
        'lookup_module' => 'accounts',
        'visible_when' => ['field' => 'cf:'.$trigger->key, 'operator' => 'equals', 'value' => $keys[1]],
    ]));

    Livewire::actingAs(customFieldUser(['leads.view', 'leads.create', 'accounts.view']))
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        // An id that names nothing, on a field that is not on screen.
        ->set('customFields.'.$lookup->key, 999999)
        ->set('customFields.'.$trigger->key, $keys[0])
        ->call('save')
        ->assertHasNoErrors();
});

// -- The admin screen ----------------------------------------------------------

test('the editor saves a section and a condition', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Lost reason')
        ->set('section', 'Outcome')
        ->set('isFullWidth', true)
        ->set('conditionField', 'cf:'.$trigger->key)
        ->set('conditionOperator', 'equals')
        ->set('conditionValue', $trigger->optionKeys()[1])
        ->call('save')
        ->assertHasNoErrors();

    $saved = CustomField::query()->where('key', 'lost_reason')->firstOrFail();

    expect($saved->sectionName())->toBe('Outcome')
        ->and($saved->is_full_width)->toBeTrue()
        ->and($saved->visibilityCondition()?->field)->toBe('cf:'.$trigger->key);
});

test('a condition cannot name a field the module does not offer', function () {
    laidOutField('Stage');

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Lost reason')
        ->set('conditionField', 'cf:invented')
        ->call('save')
        ->assertHasErrors('conditionField');
});

test('a field is never offered as a condition on itself', function () {
    $field = laidOutField('Stage');

    $options = Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('edit', $field->id)
        ->instance()
        ->conditionFieldOptions();

    expect($options)->not->toHaveKey('cf:'.$field->key);
});

test('editing loads the stored section and condition back into the editor', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);
    $keys = $trigger->optionKeys();

    $field = laidOutField('Lost reason', section: 'Outcome', when: [
        'field' => 'cf:'.$trigger->key,
        'operator' => 'equals',
        'value' => $keys[1],
    ]);

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('edit', $field->id)
        ->assertSet('section', 'Outcome')
        ->assertSet('conditionField', 'cf:'.$trigger->key)
        ->assertSet('conditionOperator', 'equals')
        ->assertSet('conditionValue', $keys[1]);
});

test('the value choices follow the field the condition names', function () {
    $trigger = laidOutField('Stage', type: CustomFieldType::Select, options: ['Won', 'Lost']);

    $screen = Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('conditionField', 'cf:'.$trigger->key);

    expect(array_values($screen->instance()->conditionValueOptions()))->toBe(['Won', 'Lost']);
});

test('the section a field carries is recorded in the audit allowlist', function () {
    $field = laidOutField('Sector', section: 'Qualification');
    $logged = (new ReflectionClass(CustomField::class))->getMethod('activityAttributes');
    $logged->setAccessible(true);

    expect($logged->invoke($field))->toContain('section');
});
