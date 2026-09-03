<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\ScoreLeadsAction;
use App\Domain\Leads\DTOs\QualificationCheck;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Leads\Services\LeadQualification;
use App\Domain\Shared\Enums\FilterOperator;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Models\User;
use Livewire\Livewire;

/**
 * A qualification requirement of the "field operator value" shape.
 *
 * @param  array<int, string>  $selected
 */
function requirement(
    string $label,
    string $field,
    FilterOperator $operator,
    mixed $value = null,
    array $selected = [],
): LeadScoringRule {
    return LeadScoringRule::factory()
        ->condition($field, $operator, $value, null, $selected)
        ->requirement()
        ->create(['label' => $label]);
}

/**
 * @param  array<int, string>  $permissions
 */
function qualifyingUser(array $permissions = ['leads.view', 'leads.update']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// -- The check itself ----------------------------------------------------------

test('with no requirements configured every lead passes', function () {
    $lead = Lead::factory()->create(['company_name' => null, 'email' => null]);

    $check = app(LeadQualification::class)->for($lead);

    expect($check)->toBeInstanceOf(QualificationCheck::class)
        ->and($check->requirements)->toBe([])
        ->and($check->passes())->toBeTrue()
        ->and($check->unmet())->toBe([])
        ->and($check->reason())->toBeNull();
});

test('a requirement the lead does not meet fails the check and is named', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->create(['company_name' => null]);

    $check = app(LeadQualification::class)->for($lead);

    expect($check->passes())->toBeFalse()
        ->and($check->unmet())->toBe(['Works for a named company'])
        ->and($check->met())->toBe([])
        ->and($check->reason())->toContain('Works for a named company');
});

test('requirements are all-or-nothing, and the check reports both sides', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    requirement('Reachable by phone', 'phone', FilterOperator::IsNotEmpty);
    requirement('Named a budget', 'estimated_value', FilterOperator::GreaterThan, '0');

    $lead = Lead::factory()->create([
        'company_name' => 'Acme',
        'phone' => null,
        'estimated_value' => '5000.00',
    ]);

    $check = app(LeadQualification::class)->for($lead);

    expect($check->passes())->toBeFalse()
        ->and($check->met())->toBe(['Works for a named company', 'Named a budget'])
        ->and($check->unmet())->toBe(['Reachable by phone'])
        ->and($check->reason())->not->toContain('Works for a named company');
});

test('a lead meeting every requirement passes', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    requirement('Reachable by phone', 'phone', FilterOperator::IsNotEmpty);

    $lead = Lead::factory()->create(['company_name' => 'Acme', 'phone' => '0117 000 0000']);

    expect(app(LeadQualification::class)->for($lead)->passes())->toBeTrue();
});

test('an inactive requirement does not block anything', function () {
    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->requirement()
        ->inactive()
        ->create(['label' => 'Works for a named company']);

    $lead = Lead::factory()->create(['company_name' => null]);

    expect(app(LeadQualification::class)->for($lead)->passes())->toBeTrue()
        ->and(app(LeadQualification::class)->for($lead)->requirements)->toBe([]);
});

test('a scoring rule is not a requirement', function () {
    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->worth(50)
        ->create(['label' => 'Has a company']);

    $lead = Lead::factory()->create(['company_name' => null]);

    expect(app(LeadQualification::class)->for($lead)->requirements)->toBe([]);
});

test('a requirement naming a field that has gone can never be met, rather than always being met', function () {
    requirement('Vanished', 'no_such_field', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->create(['company_name' => 'Acme']);

    expect(app(LeadQualification::class)->for($lead)->passes())->toBeFalse();
});

// -- The gate on the status move -----------------------------------------------

test('a lead that does not meet the requirements cannot be qualified', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => null]);

    expect(fn () => app(ChangeLeadStatusAction::class)($lead, LeadStatus::Qualified))
        ->toThrow(RuntimeException::class, 'not ready to qualify');

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted->value);
});

test('a lead that meets them can be qualified', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => 'Acme']);

    app(ChangeLeadStatusAction::class)($lead, LeadStatus::Qualified);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified->value);
});

test('with no requirements a qualifying move behaves exactly as it did before', function () {
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => null]);

    app(ChangeLeadStatusAction::class)($lead, LeadStatus::Qualified);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified->value);
});

test('requirements gate only the qualifying move', function (LeadStatus $target) {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->status(LeadStatus::New)->create(['company_name' => null]);

    app(ChangeLeadStatusAction::class)($lead, $target);

    expect($lead->fresh()?->status)->toBe($target->value);
})->with([
    'contacted' => [LeadStatus::Contacted],
    'nurturing' => [LeadStatus::Nurturing],
    'unqualified' => [LeadStatus::Unqualified],
]);

test('conversion may still set a status on a lead that never met the requirements', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => null]);

    app(ChangeLeadStatusAction::class)->force($lead, LeadStatus::Qualified);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified->value);
});

// -- A minimum score, expressed as an ordinary requirement ---------------------

test('a minimum score can be required, and is judged on a freshly computed score', function () {
    // The stored score is deliberately stale: the rule that earns the points is
    // added after the lead exists, and nothing has rescored it yet.
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => 'Acme']);

    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->worth(60)
        ->create(['label' => 'Has a company']);

    requirement('Scores at least 50', LeadFields::SCORE, FilterOperator::GreaterThanOrEqual, '50');

    expect($lead->fresh()?->score)->toBe(0);

    app(ChangeLeadStatusAction::class)($lead->fresh(), LeadStatus::Qualified);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified->value)
        ->and($lead->fresh()?->score)->toBe(60);
});

test('a lead below the minimum score is held back', function () {
    requirement('Scores at least 50', LeadFields::SCORE, FilterOperator::GreaterThanOrEqual, '50');

    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->worth(20)
        ->create(['label' => 'Has a company']);

    $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['company_name' => 'Acme']);
    app(ScoreLeadsAction::class)();

    expect(fn () => app(ChangeLeadStatusAction::class)($lead->fresh(), LeadStatus::Qualified))
        ->toThrow(RuntimeException::class, 'Scores at least 50');
});

// -- On the screens ------------------------------------------------------------

test('the detail page lists the checklist with each item marked met or not', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);
    requirement('Reachable by phone', 'phone', FilterOperator::IsNotEmpty);

    $user = qualifyingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)
        ->create(['company_name' => 'Acme', 'phone' => null]);

    $component = Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $lead]);

    $component->assertSee('Before qualifying')
        ->assertSee('Works for a named company')
        ->assertSee('Reachable by phone');

    expect($component->instance()->qualification()->unmet())->toBe(['Reachable by phone']);
});

test('the detail page refuses a qualifying move and says what is missing', function () {
    requirement('Works for a named company', 'company_name', FilterOperator::IsNotEmpty);

    $user = qualifyingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)
        ->create(['company_name' => null]);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('changeStatus', LeadStatus::Qualified->value)
        ->assertDispatched('notify', type: 'error');

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted->value);
});

test('the board refuses a drag into qualified and says why', function () {
    requirement('Named a budget', 'estimated_value', FilterOperator::GreaterThan, '0');

    $user = qualifyingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)
        ->create(['estimated_value' => null]);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Qualified->value)
        ->assertDispatched('notify', type: 'error');

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted->value);
});

test('the board allows a drag into qualified once the requirements are met', function () {
    requirement('Named a budget', 'estimated_value', FilterOperator::GreaterThan, '0');

    $user = qualifyingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)
        ->create(['estimated_value' => '4000.00']);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Qualified->value)
        ->assertNotDispatched('notify');

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified->value);
});
