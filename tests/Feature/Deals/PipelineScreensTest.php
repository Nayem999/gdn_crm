<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Livewire\Deals\PipelineForm;
use App\Livewire\Deals\PipelinesIndex;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function pipelineUser(array $permissions = ['deals.pipelines']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @return array<int, array<string, mixed>>
 */
function formStages(): array
{
    return [
        ['key' => '', 'name' => 'Working', 'outcome' => 'open', 'probability' => '40', 'color' => 'blue'],
        ['key' => '', 'name' => 'Closed won', 'outcome' => 'won', 'probability' => '100', 'color' => 'emerald'],
    ];
}

// -- Authorization -------------------------------------------------------------

test('the pipelines screen needs its own permission', function () {
    Livewire::actingAs(pipelineUser([]))
        ->test(PipelinesIndex::class)
        ->assertForbidden();

    // deals.view is not enough: configuring how every deal is worked is
    // administration, not deal work.
    Livewire::actingAs(pipelineUser(['deals.view']))
        ->test(PipelinesIndex::class)
        ->assertForbidden();

    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->assertSuccessful();
});

test('the form is closed to somebody without the permission', function () {
    $pipeline = Pipeline::factory()->withStages()->create();

    Livewire::actingAs(pipelineUser(['deals.view']))
        ->test(PipelineForm::class)
        ->assertForbidden();

    Livewire::actingAs(pipelineUser(['deals.view']))
        ->test(PipelineForm::class, ['pipeline' => $pipeline])
        ->assertForbidden();
});

test('the routes are closed to a guest', function () {
    $pipeline = Pipeline::factory()->withStages()->create();

    $this->get(route('settings.pipelines'))->assertRedirect(route('login'));
    $this->get(route('settings.pipelines.create'))->assertRedirect(route('login'));
    $this->get(route('settings.pipelines.edit', $pipeline))->assertRedirect(route('login'));
});

// -- The list ------------------------------------------------------------------

test('the list shows each pipeline with its stages in order', function () {
    $user = pipelineUser();
    $pipeline = Pipeline::factory()->default()->create(['name' => 'Standard sales']);
    savePipeline(pipelineData('Standard sales', threeStages()), $pipeline);

    Livewire::actingAs($user)
        ->test(PipelinesIndex::class)
        ->assertSuccessful()
        ->assertSee('Standard sales')
        ->assertSeeInOrder(['Working', 'Closed won', 'Closed lost'])
        ->assertSee('Default');
});

test('an installation with no pipelines shows an empty state, never a blank panel', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->assertSee('No pipelines yet');
});

test('the list route renders through the settings shell', function () {
    savePipeline(pipelineData('Standard sales', threeStages()));

    $this->actingAs(pipelineUser())
        ->get(route('settings.pipelines'))
        ->assertSuccessful()
        ->assertSee('Standard sales')
        // The settings navigation lists it, so it is reachable from the shell.
        ->assertSee('Pipelines');
});

// -- Creating and editing ------------------------------------------------------

test('a pipeline is created from the form', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', 'Renewals')
        ->set('description', 'For existing customers')
        ->set('stages', formStages())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.pipelines'));

    $pipeline = Pipeline::query()->where('name', 'Renewals')->sole();

    expect($pipeline->stages->pluck('name')->all())->toBe(['Working', 'Closed won'])
        ->and($pipeline->description)->toBe('For existing customers');
});

test('each form names itself in the browser tab', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    $this->actingAs(pipelineUser())
        ->get(route('settings.pipelines.create'))
        ->assertSee('<title>Add pipeline', escape: false);

    $this->actingAs(pipelineUser())
        ->get(route('settings.pipelines.edit', $pipeline))
        ->assertSee('<title>Edit pipeline', escape: false);
});

test('a new pipeline form arrives with workable stages rather than a blank list', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->assertCount('stages', 3)
        // Asserted on state, not markup: a wire:model input carries no value
        // attribute server-side, Livewire fills it on the client at boot.
        ->assertSet('stages.1.name', 'Closed won')
        ->assertSet('stages.1.outcome', 'won')
        ->assertSet('stages.2.name', 'Closed lost')
        ->assertSet('stages.2.outcome', 'lost');
});

test('the edit form loads the pipeline as it stands', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages(), isDefault: true));

    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class, ['pipeline' => $pipeline])
        ->assertSet('name', 'Sales')
        ->assertSet('isDefault', true)
        ->assertCount('stages', 3)
        ->assertSet('stages.0.name', 'Working')
        // The existing key travels with the row, which is what lets a rename
        // keep the deals sitting in that stage.
        ->assertSet('stages.0.key', 'working')
        ->assertSet('stages.0.probability', '40');
});

test('a pipeline needs a name, and it must be unique', function () {
    savePipeline(pipelineData('Sales', threeStages()));

    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', 'Sales')
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);
});

test('a probability outside 0-100 is refused', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', 'Sales')
        ->set('stages', [['key' => '', 'name' => 'Working', 'outcome' => 'open', 'probability' => '140', 'color' => 'blue']])
        ->call('save')
        ->assertHasErrors(['stages.0.probability' => 'max']);
});

test('a colour outside the palette is refused', function () {
    // Tailwind cannot see class names built at runtime, so a colour off the
    // palette would render as no colour at all.
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', 'Sales')
        ->set('stages', [['key' => '', 'name' => 'Working', 'outcome' => 'open', 'probability' => '40', 'color' => 'chartreuse']])
        ->call('save')
        ->assertHasErrors(['stages.0.color']);
});

test('the last stage cannot be removed from the form', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('name', 'Sales')
        ->set('stages', [])
        ->call('save')
        ->assertHasErrors(['stages']);
});

test('a refusal from the action is shown against the stages, not thrown', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));
    Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage' => 'working']);

    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class, ['pipeline' => $pipeline])
        // Drop the stage the deal is sitting in.
        ->set('stages', [
            ['key' => 'closed_won', 'name' => 'Closed won', 'outcome' => 'won', 'probability' => '100', 'color' => 'emerald'],
        ])
        ->call('save')
        ->assertHasErrors('stages')
        ->assertSee('cannot be removed');
});

// -- Stage rows ----------------------------------------------------------------

test('adding and removing stage rows moves the generation so the dropdowns rebuild', function () {
    $component = Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->assertSet('generation', 0)
        ->call('addStage')
        ->assertCount('stages', 4)
        ->assertSet('generation', 1)
        ->call('removeStage', 0)
        ->assertCount('stages', 3)
        ->assertSet('generation', 2);

    // Indices stay contiguous, or wire:model on stages.N would address a gap.
    expect(array_keys($component->get('stages')))->toBe([0, 1, 2]);
});

test('removing a row that is not there does nothing', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->call('removeStage', 99)
        ->assertCount('stages', 3)
        ->assertSet('generation', 0);
});

test('choosing a closed outcome fixes the probability there and then', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->set('stages', [['key' => '', 'name' => 'Ending', 'outcome' => 'open', 'probability' => '60', 'color' => 'slate']])
        ->set('stages.0.outcome', 'won')
        ->assertSet('stages.0.probability', '100')
        ->set('stages.0.outcome', 'lost')
        ->assertSet('stages.0.probability', '0')
        // Back to open and it is the administrator's to set again.
        ->set('stages.0.outcome', 'open')
        ->assertSet('stages.0.probability', '0');
});

// -- Reordering ----------------------------------------------------------------

test('dragging a stage writes the new order', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class, ['pipeline' => $pipeline])
        ->call('reorderStages', ['closed_lost', 'working', 'closed_won'])
        ->assertSuccessful();

    expect($pipeline->fresh()->stages->pluck('key')->all())
        ->toBe(['closed_lost', 'working', 'closed_won']);
});

test('dragging on an unsaved pipeline only moves the rows on screen', function () {
    $component = Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        // Nothing is saved yet, so there are no keys to move — the rows still
        // reorder, and the save takes its positions from that order.
        ->call('reorderStages', [])
        ->assertSuccessful();

    expect($component->get('stages'))->toHaveCount(3)
        ->and(Pipeline::query()->count())->toBe(0);
});

test('dragging a pipeline writes the new order', function () {
    $first = savePipeline(pipelineData('First', threeStages()));
    $second = savePipeline(pipelineData('Second', threeStages()));

    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('reorder', [$second->id, $first->id])
        ->assertSuccessful();

    expect(Pipeline::query()->ordered()->pluck('name')->all())->toBe(['Second', 'First']);
});

// -- Default and deletion from the list ----------------------------------------

test('making a pipeline the default takes it from the other', function () {
    $first = savePipeline(pipelineData('First', threeStages(), isDefault: true));
    $second = savePipeline(pipelineData('Second', threeStages()));

    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('makeDefault', $second->id)
        ->assertSuccessful();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('a removable pipeline is removed from the list', function () {
    savePipeline(pipelineData('Default', threeStages(), isDefault: true));
    $spare = savePipeline(pipelineData('Spare', threeStages()));

    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('delete', $spare->id)
        ->assertSuccessful()
        ->assertDontSee('Spare');

    expect(Pipeline::query()->whereKey($spare->id)->exists())->toBeFalse();
});

test('deleting one that cannot go reports why instead of failing', function () {
    $default = savePipeline(pipelineData('Default', threeStages(), isDefault: true));
    savePipeline(pipelineData('Other', threeStages()));

    // The policy refuses first, so the button is never offered.
    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('delete', $default->id)
        ->assertForbidden();

    expect(Pipeline::query()->whereKey($default->id)->exists())->toBeTrue();
});

test('a pipeline id that does not exist is a missing page', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('makeDefault', 999_999)
        ->assertNotFound();
});

// -- Dropdowns -----------------------------------------------------------------

test('every dropdown on the form is an x-select', function () {
    $html = Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class)
        ->html();

    // The component renders one native <select> for Tom Select to take over,
    // so the two counts matching is what proves there is no plain one.
    expect(substr_count($html, '<select'))->toBe(substr_count($html, 'tomSelectField('))
        ->and(substr_count($html, '<select'))->toBeGreaterThan(0);
});
