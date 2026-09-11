<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowCache;
use App\Livewire\Workflows\WorkflowBuilder;
use App\Livewire\Workflows\WorkflowsIndex;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function workflowUser(array $permissions = ['workflows.view', 'workflows.create', 'workflows.update', 'workflows.delete']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
});

// -- The headline requirement ----------------------------------------------------

test('the builder saves a definition and round-trips it', function () {
    $user = workflowUser();

    Livewire::actingAs($user)
        ->test(WorkflowBuilder::class)
        ->set('name', 'Chase high-value leads')
        ->set('description', 'Anything worth chasing.')
        ->set('module', 'leads')
        ->set('triggerEvent', WorkflowTrigger::FieldChanged->value)
        ->set('triggerField', 'status')
        ->set('runOncePerRecord', true)
        ->set('filters', [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [
                ['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000', 'second_value' => null, 'selected' => []],
            ],
            'groups' => [[
                'match' => FilterGroup::MATCH_ANY,
                'conditions' => [
                    ['field' => 'city', 'operator' => FilterOperator::Contains->value, 'value' => 'Dhaka', 'second_value' => null, 'selected' => []],
                ],
                'groups' => [],
            ]],
        ])
        ->set('steps', [
            ['id' => null, 'type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'company_name', 'value' => 'Chased'], 'is_active' => true, 'stop_on_failure' => true],
            ['id' => null, 'type' => WorkflowActionType::SendNotification->value, 'config' => ['recipient' => 'record_owner', 'message' => 'Worth a call.'], 'is_active' => true, 'stop_on_failure' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $workflow = Workflow::query()->with('actions')->sole();

    // Stored as submitted...
    expect($workflow->name)->toBe('Chase high-value leads')
        ->and($workflow->module())->toBe('leads')
        ->and($workflow->trigger())->toBe(WorkflowTrigger::FieldChanged)
        ->and($workflow->trigger_field)->toBe('status')
        ->and($workflow->run_once_per_record)->toBeTrue()
        ->and($workflow->conditions()->count())->toBe(2)
        ->and($workflow->conditions()->groups[0]->matchAny())->toBeTrue()
        ->and($workflow->actions)->toHaveCount(2)
        ->and($workflow->actions[0]->setting('value'))->toBe('Chased')
        ->and($workflow->actions[1]->stop_on_failure)->toBeFalse();

    // ...and read back the same, which is the round trip.
    $reopened = Livewire::actingAs($user)->test(WorkflowBuilder::class, ['workflow' => $workflow]);

    expect($reopened->get('name'))->toBe('Chase high-value leads')
        ->and($reopened->get('module'))->toBe('leads')
        ->and($reopened->get('triggerEvent'))->toBe(WorkflowTrigger::FieldChanged->value)
        ->and($reopened->get('triggerField'))->toBe('status')
        ->and($reopened->get('runOncePerRecord'))->toBeTrue()
        ->and($reopened->get('filters')['conditions'][0]['field'])->toBe('estimated_value')
        ->and($reopened->get('filters')['groups'][0]['conditions'][0]['value'])->toBe('Dhaka')
        ->and($reopened->get('steps'))->toHaveCount(2)
        ->and($reopened->get('steps')[0]['config']['field'])->toBe('company_name')
        ->and($reopened->get('steps')[1]['type'])->toBe(WorkflowActionType::SendNotification->value);
});

test('saving twice does not duplicate the steps, and keeps their ids', function () {
    // The execution log points at step ids. Saving must not hand them new ones.
    $user = workflowUser();

    $screen = Livewire::actingAs($user)
        ->test(WorkflowBuilder::class)
        ->set('name', 'Twice')
        ->set('steps', [
            ['id' => null, 'type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'company_name', 'value' => 'A'], 'is_active' => true, 'stop_on_failure' => true],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $firstId = WorkflowAction::query()->sole()->id;

    $screen->set('steps.0.config.value', 'B')->call('save')->assertHasNoErrors();

    expect(WorkflowAction::query()->count())->toBe(1)
        ->and(WorkflowAction::query()->sole()->id)->toBe($firstId)
        ->and(WorkflowAction::query()->sole()->setting('value'))->toBe('B');
});

// -- What the builder refuses ------------------------------------------------------

test('a workflow needs a name and at least one step', function () {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', '')
        ->set('steps', [])
        ->call('save')
        ->assertHasErrors(['name', 'steps']);
});

test('a change trigger must say which field it watches', function () {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', 'Watches nothing')
        ->set('triggerEvent', WorkflowTrigger::FieldChanged->value)
        ->set('triggerField', '')
        ->call('save')
        ->assertHasErrors('triggerField');
});

test('a date trigger only accepts a date field', function () {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', 'Date')
        ->set('triggerEvent', WorkflowTrigger::DateReached->value)
        ->set('triggerField', 'first_name')
        ->call('save')
        ->assertHasErrors('triggerField');
});

test('a schedule that cannot be read is refused with something useful', function () {
    // Stored unparseable, it would throw inside the sweep once a minute rather
    // than anywhere somebody would see it.
    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', 'Scheduled')
        ->set('triggerEvent', WorkflowTrigger::Scheduled->value)
        ->set('scheduleExpression', 'every so often')
        ->call('save')
        ->assertHasErrors('scheduleExpression');

    expect($screen->errors()->first('scheduleExpression'))->toContain('0 9 * * 1-5');
});

test('a module the registry does not list is refused', function () {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', 'Elsewhere')
        ->set('module', 'users')
        ->call('save')
        ->assertHasErrors('module');
});

test('a step type the library does not have is refused', function () {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('name', 'Invented')
        ->set('steps', [['id' => null, 'type' => 'run_arbitrary_code', 'config' => [], 'is_active' => true, 'stop_on_failure' => true]])
        ->call('save')
        ->assertHasErrors('steps.0.type');
});

// -- How the builder behaves ---------------------------------------------------------

test('changing the module clears what was chosen against the old one', function () {
    // A condition on a field the new module does not have would be dropped
    // silently on save, and the screen would then show something different
    // from what was stored.
    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('filters', [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '1', 'second_value' => null, 'selected' => []]],
            'groups' => [],
        ])
        ->set('triggerEvent', WorkflowTrigger::FieldChanged->value)
        ->set('triggerField', 'status')
        ->set('module', 'accounts');

    expect($screen->get('filters')['conditions'])->toBe([])
        ->and($screen->get('triggerField'))->toBe('');
});

test('changing a step type does not leave the old settings behind', function () {
    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('steps', [['id' => null, 'type' => WorkflowActionType::CallWebhook->value, 'config' => ['url' => 'https://example.com/hook'], 'is_active' => true, 'stop_on_failure' => true]])
        ->set('steps.0.type', WorkflowActionType::SendEmail->value);

    expect($screen->get('steps')[0]['config'])->toBe([]);
});

test('the module cannot be changed once a workflow exists', function () {
    // Its conditions and steps all name that module's fields.
    $user = workflowUser();
    $workflow = Workflow::factory()->create(['module' => 'leads']);
    WorkflowAction::factory()->for($workflow)->create();

    Livewire::actingAs($user)
        ->test(WorkflowBuilder::class, ['workflow' => $workflow])
        ->set('module', 'deals')
        ->call('save')
        ->assertHasNoErrors();

    expect($workflow->fresh()->module())->toBe('leads');
});

test('conditions are built on the watched module, not always leads', function () {
    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('module', 'accounts');

    $keys = collect($screen->instance()->conditionFields())->pluck('key');

    expect($keys)->not->toContain('estimated_value')
        ->and($keys)->toContain('name');
});

test('a step can only set a field the module lets a form set', function () {
    $options = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->instance()
        ->writableFieldOptions();

    expect($options)->toHaveKey('company_name')
        // In the filter set, but not fillable: a workflow can filter on when a
        // lead was captured and cannot rewrite it.
        ->and($options)->not->toHaveKey('created_at');
});

// -- The dry run ------------------------------------------------------------------------

test('a dry run says whether a workflow would fire, without firing it', function () {
    $user = workflowUser();
    $matching = Lead::factory()->create(['estimated_value' => 9000, 'first_name' => 'Priya']);

    $screen = Livewire::actingAs($user)
        ->test(WorkflowBuilder::class)
        ->set('name', 'Chase')
        ->set('filters', [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000', 'second_value' => null, 'selected' => []]],
            'groups' => [],
        ])
        ->set('steps', [['id' => null, 'type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'company_name', 'value' => 'Chased'], 'is_active' => true, 'stop_on_failure' => true]])
        ->set('dryRunRecordId', (string) $matching->id)
        ->call('testAgainst');

    $result = $screen->get('dryRun');

    expect($result['found'])->toBeTrue()
        ->and($result['matches'])->toBeTrue()
        ->and($result['steps'][0])->toContain('Chased');

    // Nothing happened: no run, and the record is untouched.
    expect(WorkflowRun::query()->count())->toBe(0)
        ->and($matching->fresh()->company_name)->not->toBe('Chased');
});

test('a dry run answers for the definition on screen, not the saved one', function () {
    // The question is "would what I am editing fire?", and answering from the
    // stored copy would answer a different question.
    $user = workflowUser();
    $lead = Lead::factory()->create(['estimated_value' => 100]);

    $workflow = Workflow::factory()->create(['module' => 'leads', 'conditions' => FilterGroup::EMPTY]);
    WorkflowAction::factory()->for($workflow)->create();

    $screen = Livewire::actingAs($user)
        ->test(WorkflowBuilder::class, ['workflow' => $workflow])
        ->set('filters', [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000', 'second_value' => null, 'selected' => []]],
            'groups' => [],
        ])
        ->set('dryRunRecordId', (string) $lead->id)
        ->call('testAgainst');

    // The stored definition has no conditions and would match; the edited one
    // does not.
    expect($screen->get('dryRun')['matches'])->toBeFalse();
});

test('a dry run against a record in another module finds nothing', function () {
    $lead = Lead::factory()->create();

    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('module', 'accounts')
        ->set('dryRunRecordId', (string) $lead->id)
        ->call('testAgainst');

    // Ids are per table, so this may or may not find an account — what matters
    // is that it never reaches across into the leads table.
    $result = $screen->get('dryRun');

    expect($result)->toBeArray();

    if ($result['found']) {
        expect($result['label'])->not->toContain($lead->first_name);
    }
});

// -- The list --------------------------------------------------------------------------

test('the list groups workflows by the module they watch', function () {
    Workflow::factory()->create(['module' => 'leads', 'name' => 'On leads']);
    Workflow::factory()->create(['module' => 'deals', 'name' => 'On deals']);

    Livewire::actingAs(workflowUser())
        ->test(WorkflowsIndex::class)
        ->assertSee('On leads')
        ->assertSee('On deals')
        ->assertSee('Leads')
        ->assertSee('Deals');
});

test('switching one on is refused when it could not run, with the reason', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads']);

    $screen = Livewire::actingAs(workflowUser())
        ->test(WorkflowsIndex::class)
        ->call('toggle', $workflow->id);

    expect($workflow->fresh()->is_active)->toBeFalse()
        ->and($screen->get('error'))->toContain('at least one step');
});

test('a complete workflow switches on from the list', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads']);
    WorkflowAction::factory()->for($workflow)->create();

    Livewire::actingAs(workflowUser())
        ->test(WorkflowsIndex::class)
        ->call('toggle', $workflow->id);

    expect($workflow->fresh()->is_active)->toBeTrue();
});

test('removing a workflow keeps its history', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads']);
    WorkflowRun::factory()->forWorkflow($workflow)->create();

    Livewire::actingAs(workflowUser())
        ->test(WorkflowsIndex::class)
        ->call('delete', $workflow->id);

    expect(Workflow::query()->count())->toBe(0)
        ->and(WorkflowRun::query()->count())->toBe(1);
});

// -- Permissions ---------------------------------------------------------------------------

test('the screens need their permissions', function () {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->get(route('workflows.index'))->assertForbidden();
    $this->actingAs($nobody)->get(route('workflows.create'))->assertForbidden();

    $this->actingAs(workflowUser())->get(route('workflows.index'))->assertOk();
    $this->actingAs(workflowUser())->get(route('workflows.create'))->assertOk();
});

test('somebody who may only read cannot save', function () {
    $reader = workflowUser(['workflows.view']);
    $workflow = Workflow::factory()->create(['module' => 'leads']);

    $this->actingAs($reader)->get(route('workflows.edit', $workflow))->assertForbidden();
});

test('the sidebar links automation only for those who may see it', function () {
    $this->actingAs(workflowUser())
        ->get(route('dashboard'))
        ->assertSee(route('workflows.index'), false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('workflows.index'), false);
});

// -- The builder renders ----------------------------------------------------------------

test('the builder renders every step type without error', function (string $value) {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('steps', [['id' => null, 'type' => $value, 'config' => [], 'is_active' => true, 'stop_on_failure' => true]])
        ->assertOk();
})->with(array_column(WorkflowActionType::cases(), 'value'));

test('the builder renders every trigger without error', function (string $value) {
    Livewire::actingAs(workflowUser())
        ->test(WorkflowBuilder::class)
        ->set('triggerEvent', $value)
        ->assertOk();
})->with(array_column(WorkflowTrigger::cases(), 'value'));
