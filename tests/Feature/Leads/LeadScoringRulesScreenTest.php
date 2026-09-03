<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Settings\SettingsNavigation;
use App\Domain\Shared\Enums\FilterOperator;
use App\Livewire\Leads\LeadScoringRules;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function scoringUser(array $permissions = ['leads.scoring']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('settings.lead-scoring'))->assertRedirect(route('login'));
});

test('the screen needs the leads.scoring permission', function () {
    $this->actingAs(scoringUser([]))
        ->get(route('settings.lead-scoring'))
        ->assertForbidden();
});

test('being allowed to edit leads is not being allowed to change how they are scored', function () {
    $this->actingAs(scoringUser(['leads.view', 'leads.update', 'leads.assign', 'leads.delete']))
        ->get(route('settings.lead-scoring'))
        ->assertForbidden();
});

test('an administrator with the permission gets the screen', function () {
    $this->actingAs(scoringUser())
        ->get(route('settings.lead-scoring'))
        ->assertOk()
        ->assertSee('Lead scoring');
});

test('leads.scoring is in the catalogue, so the roles matrix can grant it', function () {
    expect(PermissionCatalogue::has('leads.scoring'))->toBeTrue()
        ->and(PermissionCatalogue::only(['leads.scoring']))->toBe(['leads.scoring']);
});

test('the settings navigation shows the item only to somebody who can open it', function () {
    $labels = fn (User $user) => collect(SettingsNavigation::for($user))
        ->pluck('items')
        ->flatten(1)
        ->pluck('label')
        ->all();

    expect($labels(scoringUser()))->toContain('Lead scoring')
        ->and($labels(scoringUser(['leads.view'])))->not->toContain('Lead scoring');
});

test('every settings navigation entry resolves to a real route', function () {
    foreach (SettingsNavigation::sections() as $section) {
        foreach ($section['items'] as $item) {
            expect(route($item['route'], $item['params']))->toBeString();
        }
    }
});

// -- Rows ----------------------------------------------------------------------

test('the screen loads the stored rules in order', function () {
    LeadScoringRule::factory()->condition('phone', FilterOperator::IsNotEmpty)->at(1)->create(['label' => 'Second']);
    LeadScoringRule::factory()->condition('company_name', FilterOperator::IsNotEmpty)->at(0)->create(['label' => 'First']);

    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class);

    expect(array_column($component->get('rules'), 'label'))->toBe(['First', 'Second']);
});

test('a row can be added for each kind and removed again', function () {
    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->call('addRule', LeadRuleKind::Qualification->value);

    expect($component->get('rules'))->toHaveCount(2)
        ->and(array_column($component->get('rules'), 'kind'))
        ->toBe([LeadRuleKind::Score->value, LeadRuleKind::Qualification->value]);

    $component->call('removeRule', 0);

    expect($component->get('rules'))->toHaveCount(1)
        ->and($component->get('rules')[0]['kind'])->toBe(LeadRuleKind::Qualification->value);
});

test('an added row starts on a field and comparison the engine accepts', function () {
    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value);

    $row = $component->get('rules')[0];

    expect(LeadFields::filters())->toHaveKey($row['field'])
        ->and(FilterOperator::tryFrom($row['operator']))->not->toBeNull();
});

test('an unknown kind adds nothing', function () {
    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', 'sabotage');

    expect($component->get('rules'))->toBe([]);
});

test('changing the field resets a comparison that no longer applies', function () {
    LeadScoringRule::factory()->condition('company_name', FilterOperator::Contains, 'Acme')->create();

    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->set('rules.0.field', 'estimated_value');

    $row = $component->get('rules')[0];
    $operator = FilterOperator::tryFrom($row['operator']);

    expect($operator)->not->toBeNull()
        ->and(LeadFields::filters()['estimated_value']->type->operators())->toContain($operator)
        ->and($row['value'])->toBeNull()
        ->and($row['selected'])->toBe([]);
});

test('the comparison and value pickers are keyed on the field so Tom Select rebuilds', function () {
    LeadScoringRule::factory()->condition('company_name', FilterOperator::Contains, 'Acme')->create();

    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class);

    // <x-select> keeps Tom Select behind wire:ignore, so a re-render cannot
    // update the options in place. The key is what makes Livewire replace the
    // node; without it the Comparison dropdown keeps the old field's operators
    // and shows nothing selected.
    expect($component->html())->toContain('wire:key="rule-op-0-company_name"');

    $component->set('rules.0.field', 'estimated_value');

    expect($component->html())->toContain('wire:key="rule-op-0-estimated_value"')
        ->and($component->html())->not->toContain('wire:key="rule-op-0-company_name"');
});

test('the value picker is keyed on the comparison too, since the input shape changes', function () {
    LeadScoringRule::factory()->condition('estimated_value', FilterOperator::GreaterThan, '0')->create();

    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class);

    expect($component->html())->toContain('wire:key="rule-val-0-estimated_value-gt"');

    $component->set('rules.0.operator', FilterOperator::Between->value);

    expect($component->html())->toContain('wire:key="rule-val-0-estimated_value-between"');
});

// -- What the screen offers ----------------------------------------------------

test('the score is offered as a requirement field but not as a scoring one', function () {
    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class);
    $instance = $component->instance();

    expect($instance->fieldOptionsFor(LeadRuleKind::Score))->not->toHaveKey(LeadFields::SCORE)
        ->and($instance->fieldOptionsFor(LeadRuleKind::Qualification))->toHaveKey(LeadFields::SCORE);
});

test('the comparisons offered are the ones the field type allows', function (string $field) {
    $instance = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)->instance();

    $offered = array_keys($instance->operatorOptionsFor($field));
    $allowed = array_map(
        fn (FilterOperator $operator) => $operator->value,
        LeadFields::filters()[$field]->type->operators()
    );

    expect($offered)->toBe($allowed);
})->with(['company_name', 'estimated_value', 'status', 'created_at']);

test('an unknown field offers no comparisons at all', function () {
    $instance = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)->instance();

    expect($instance->operatorOptionsFor('password'))->toBe([]);
});

// -- Saving --------------------------------------------------------------------

test('a complete rule set saves', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->set('rules', [
            [
                'id' => null, 'kind' => LeadRuleKind::Score->value, 'label' => 'Works for a named company',
                'field' => 'company_name', 'operator' => FilterOperator::IsNotEmpty->value,
                'value' => null, 'second_value' => null, 'selected' => [],
                'points' => 20, 'is_active' => true,
            ],
            [
                'id' => null, 'kind' => LeadRuleKind::Qualification->value, 'label' => 'Named a budget',
                'field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value,
                'value' => '0', 'second_value' => null, 'selected' => [],
                'points' => 0, 'is_active' => true,
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadScoringRule::query()->count())->toBe(2)
        ->and(LeadScoringRule::query()->ofKind(LeadRuleKind::Score)->value('points'))->toBe(20);
});

test('a rule without a name is refused', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', '   ')
        ->call('save')
        ->assertHasErrors('rules.0.label');

    expect(LeadScoringRule::query()->count())->toBe(0);
});

test('a comparison that needs a value is refused without one', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Named a budget')
        ->set('rules.0.field', 'estimated_value')
        ->set('rules.0.operator', FilterOperator::GreaterThan->value)
        ->set('rules.0.value', null)
        ->call('save')
        ->assertHasErrors('rules.0.value');
});

test('a range comparison is refused with only one end', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Mid-range budget')
        ->set('rules.0.field', 'estimated_value')
        ->set('rules.0.operator', FilterOperator::Between->value)
        ->set('rules.0.value', '1000')
        ->set('rules.0.second_value', null)
        ->call('save')
        ->assertHasErrors('rules.0.value');
});

test('an is-any-of comparison is refused with nothing chosen', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Worked statuses')
        ->set('rules.0.field', 'status')
        ->set('rules.0.operator', FilterOperator::In->value)
        ->set('rules.0.selected', [])
        ->call('save')
        ->assertHasErrors('rules.0.selected');
});

test('points must be a whole number within reach of the range', function (mixed $points) {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Works for a named company')
        ->set('rules.0.field', 'company_name')
        ->set('rules.0.operator', FilterOperator::IsNotEmpty->value)
        ->set('rules.0.points', $points)
        ->call('save')
        ->assertHasErrors('rules.0.points');
})->with([
    'not a number' => ['plenty'],
    'far too many' => [500],
    'far too few' => [-500],
]);

test('a scoring rule on the score is refused with a reason', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Circular')
        ->set('rules.0.field', LeadFields::SCORE)
        ->set('rules.0.operator', FilterOperator::GreaterThan->value)
        ->set('rules.0.value', '10')
        ->call('save')
        ->assertHasErrors('rules.0.field');

    expect(LeadScoringRule::query()->count())->toBe(0);
});

test('a tampered field or comparison never reaches the database', function (array $row) {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->set('rules', [$row])
        ->call('save');

    expect(LeadScoringRule::query()->count())->toBe(0);
})->with([
    'a column nobody filters on' => [[
        'id' => null, 'kind' => 'score', 'label' => 'Sneaky', 'field' => 'password',
        'operator' => 'is_not_empty', 'value' => null, 'second_value' => null,
        'selected' => [], 'points' => 90, 'is_active' => true,
    ]],
    'a comparison the field type does not offer' => [[
        'id' => null, 'kind' => 'score', 'label' => 'Sneaky', 'field' => 'estimated_value',
        'operator' => 'starts_with', 'value' => '1', 'second_value' => null,
        'selected' => [], 'points' => 90, 'is_active' => true,
    ]],
    'a kind that does not exist' => [[
        'id' => null, 'kind' => 'sabotage', 'label' => 'Sneaky', 'field' => 'company_name',
        'operator' => 'is_not_empty', 'value' => null, 'second_value' => null,
        'selected' => [], 'points' => 90, 'is_active' => true,
    ]],
]);

test('saving reports what was stored and reloads the rules with their ids', function () {
    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Works for a named company')
        ->set('rules.0.field', 'company_name')
        ->set('rules.0.operator', FilterOperator::IsNotEmpty->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($component->get('saved'))->toContain('1 rule saved')
        ->and($component->get('rules')[0]['id'])->toBe(LeadScoringRule::first()?->id);
});

test('saving rescores the leads, because the queue runs inline in tests', function () {
    $lead = Lead::factory()->create(['company_name' => 'Acme']);

    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('addRule', LeadRuleKind::Score->value)
        ->set('rules.0.label', 'Works for a named company')
        ->set('rules.0.field', 'company_name')
        ->set('rules.0.operator', FilterOperator::IsNotEmpty->value)
        ->set('rules.0.points', 45)
        ->call('save');

    expect($lead->fresh()?->score)->toBe(45);
});

test('rescoring on demand reports how many leads it touched', function () {
    Lead::factory()->count(3)->create();

    $component = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->call('recalculate');

    expect($component->get('saved'))->toContain('3 leads rescored');
});

// -- The UI standard -----------------------------------------------------------

test('the screen picks fields and comparisons with the searchable select, not a plain one', function () {
    LeadScoringRule::factory()->condition('status', FilterOperator::In, null, null, ['contacted'])->create();

    $html = Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)->html();

    // Three pickers on the row: field, comparison and the chosen statuses. Each
    // <x-select> renders one native <select> for Tom Select to take over, so
    // equal counts mean no plain dropdown slipped in alongside them.
    expect(substr_count($html, 'tomSelectField('))->toBe(3)
        ->and(substr_count($html, '<select'))->toBe(3);
});

test('the grade bands are explained on the screen', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->assertSee('Hot')
        ->assertSee('Warm')
        ->assertSee('Cool')
        ->assertSee('Cold');
});

test('each section says what an empty list means', function () {
    Livewire::actingAs(scoringUser())->test(LeadScoringRules::class)
        ->assertSee('any lead may be qualified')
        ->assertSee('every lead scores zero');
});
