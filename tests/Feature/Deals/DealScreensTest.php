<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Actions\CloseDealAction;
use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\Actions\ReorderPipelineStagesAction;
use App\Domain\Deals\DealExportSource;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Models\UserViewPreference;
use App\Domain\Timeline\Models\Note;
use App\Livewire\Deals\DealForm;
use App\Livewire\Deals\DealShow;
use App\Livewire\Deals\DealsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function dealUser(array $permissions = ['deals.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * Somebody who runs the deals module: every permission it has, and an access
 * level that reaches every record — the account and contact pickers are only
 * meaningful against records the person can actually see.
 */
function dealAdmin(): User
{
    return dealUserSeeingEverything([
        'deals.view', 'deals.create', 'deals.update', 'deals.delete',
        'deals.assign', 'deals.close', 'deals.export',
        'accounts.view', 'contacts.view',
    ]);
}

/**
 * @param  array<int, string>  $permissions
 */
function dealUserSeeingEverything(array $permissions): User
{
    $role = Role::query()->create([
        'name' => 'Deals all '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('deals.index'))->assertRedirect(route('login'));
    $this->get(route('deals.create'))->assertRedirect(route('login'));
});

test('the list needs the deals.view permission', function () {
    $this->actingAs(User::factory()->create())->get(route('deals.index'))->assertForbidden();

    $this->actingAs(dealUser())->get(route('deals.index'))->assertOk()->assertSee('Deals');
});

test('the form needs deals.create, and editing needs deals.update', function () {
    dealPipeline();
    $deal = Deal::factory()->create();

    Livewire::actingAs(dealUser())->test(DealForm::class)->assertForbidden();
    Livewire::actingAs(dealUser())->test(DealForm::class, ['deal' => $deal])->assertForbidden();

    Livewire::actingAs(dealUser(['deals.view', 'deals.create']))->test(DealForm::class)->assertSuccessful();
});

test('a deal outside the viewer access level cannot be reached by id', function () {
    dealPipeline();

    $owner = dealUser(['deals.view']);
    $peer = dealUser(['deals.view']);
    $deal = Deal::factory()->ownedBy($owner)->create();

    Livewire::actingAs($peer)->test(DealShow::class, ['deal' => $deal])->assertForbidden();
    Livewire::actingAs($owner)->test(DealShow::class, ['deal' => $deal])->assertSuccessful();
});

test('the sidebar offers Deals only to somebody who may open it', function () {
    $this->actingAs(dealUser())->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('deals.index'), escape: false);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('deals.index'), escape: false);
});

// -- CRUD ----------------------------------------------------------------------

test('a deal is created from the form', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $account = Account::factory()->create(['name' => 'Acme']);

    Livewire::actingAs($user)
        ->test(DealForm::class)
        ->set('name', 'Acme rollout')
        ->set('account_id', (string) $account->id)
        ->set('value', '12000')
        ->set('expected_close_date', now()->addMonth()->format('Y-m-d'))
        ->call('save')
        ->assertHasNoErrors();

    $deal = Deal::query()->where('name', 'Acme rollout')->sole();

    expect($deal->account_id)->toBe($account->id)
        ->and($deal->pipeline_id)->toBe($pipeline->id)
        ->and($deal->stage)->toBe('scoping')
        ->and($deal->owner_id)->toBe($user->id);
});

test('a deal needs a name and an account', function () {
    dealPipeline();

    Livewire::actingAs(dealAdmin())
        ->test(DealForm::class)
        ->set('name', '')
        ->set('account_id', null)
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'account_id' => 'required']);
});

test('a value larger than the column holds is refused', function () {
    dealPipeline();

    // DECIMAL(15,2), so MySQL would silently truncate anything bigger.
    Livewire::actingAs(dealAdmin())
        ->test(DealForm::class)
        ->set('name', 'Huge')
        ->set('account_id', (string) Account::factory()->create()->id)
        ->set('value', '99999999999999.99')
        ->call('save')
        ->assertHasErrors(['value' => 'max']);
});

test('an account the viewer cannot reach is refused even though it exists', function () {
    dealPipeline();

    $owner = dealUser(['accounts.view']);
    $account = Account::factory()->ownedBy($owner)->create();
    $user = dealUser(['deals.view', 'deals.create']);

    // `exists` proves the account is real, never that this person may reach it.
    Livewire::actingAs($user)
        ->test(DealForm::class)
        ->set('name', 'Sneaky')
        ->set('account_id', (string) $account->id)
        ->call('save')
        ->assertHasErrors('account_id');

    expect(Deal::query()->count())->toBe(0);
});

test('the edit form loads the deal and saves changes', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Before']);

    Livewire::actingAs($user)
        ->test(DealForm::class, ['deal' => $deal])
        ->assertSet('name', 'Before')
        ->assertSet('pipeline_id', (string) $pipeline->id)
        ->set('name', 'After')
        ->call('save')
        ->assertHasNoErrors();

    expect($deal->fresh()->name)->toBe('After');
});

test('the form has no stage control at all', function () {
    dealPipeline();

    $html = Livewire::actingAs(dealAdmin())->test(DealForm::class)->html();

    // MoveDealStageAction owns the column; a second path into it would
    // eventually disagree with the closing stamp.
    expect($html)->not->toContain('name="stage"')
        ->and(Livewire::actingAs(dealAdmin())->test(DealForm::class)->instance())
        ->not->toHaveProperty('stage');
});

test('choosing another account clears the contact', function () {
    dealPipeline();
    $user = dealAdmin();
    $first = Account::factory()->create();
    $contact = Contact::factory()->create(['account_id' => $first->id]);

    Livewire::actingAs($user)
        ->test(DealForm::class)
        ->set('account_id', (string) $first->id)
        ->set('contact_id', (string) $contact->id)
        ->set('account_id', (string) Account::factory()->create()->id)
        // Keeping it is how a deal ends up pointing at somebody who works
        // somewhere else.
        ->assertSet('contact_id', null);
});

test('each form names itself in the browser tab', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Acme rollout']);

    $this->actingAs($user)->get(route('deals.create'))->assertSee('<title>Add deal', escape: false);
    $this->actingAs($user)->get(route('deals.edit', $deal))->assertSee('<title>Edit deal', escape: false);
    $this->actingAs($user)->get(route('deals.show', $deal))->assertSee('<title>Acme rollout', escape: false);
});

test('a deal is removed from the list, and recoverably', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Doomed']);

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('delete', $deal->id)
        ->assertDontSee('Doomed');

    expect(Deal::withTrashed()->whereKey($deal->id)->exists())->toBeTrue();
});

test('bulk removal only takes the deals the viewer may delete', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view', 'deals.delete']);

    $mine = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();
    $theirs = Deal::factory()->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->set('selected', [$mine->id, $theirs->id])
        ->call('deleteSelected');

    // Access level is "all", so both were deletable — the point is that the
    // policy is asked per record rather than once for the batch.
    expect(Deal::query()->count())->toBe(0);
});

// -- Views ---------------------------------------------------------------------

test('every view mode renders with data', function (ViewMode $mode) {
    $pipeline = dealPipeline();
    $user = dealUser();
    Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Acme rollout']);

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Acme rollout');
})->with(ViewMode::cases());

test('every view mode renders when there is nothing', function (ViewMode $mode) {
    dealPipeline();

    Livewire::actingAs(dealUser())
        ->test(DealsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('No deals yet');
})->with(ViewMode::cases());

test('the board columns are the pipeline stages, in their configured order', function () {
    $pipeline = dealPipeline();
    $user = dealUser();
    Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Acme rollout']);

    $component = Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value);

    expect($component->instance()->dataViewKanbanField())->toBe('stage')
        ->and(array_column($component->instance()->dataViewKanbanColumns(), 'value'))
        ->toBe(['scoping', 'negotiation', 'closed_won', 'closed_lost']);

    $component->assertSee('Scoping')->assertSee('Closed won');
});

test('reordering the stages reorders the board', function () {
    $pipeline = dealPipeline();

    app(ReorderPipelineStagesAction::class)(
        $pipeline,
        ['negotiation', 'scoping', 'closed_won', 'closed_lost']
    );

    $component = Livewire::actingAs(dealUser())
        ->test(DealsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value);

    expect(array_column($component->instance()->dataViewKanbanColumns(), 'value'))
        ->toBe(['negotiation', 'scoping', 'closed_won', 'closed_lost']);
});

test('the board shows one pipeline only', function () {
    $sales = dealPipeline();
    $renewals = savePipeline(pipelineData('Renewals', [
        ['name' => 'Renewal due', 'outcome' => 'open', 'probability' => 50, 'color' => 'cyan'],
    ]));

    $user = dealUserSeeingEverything(['deals.view']);
    Deal::factory()->onPipeline($sales)->create(['name' => 'On sales']);
    Deal::factory()->onPipeline($renewals)->create(['name' => 'On renewals']);

    // A board mixing two pipelines' stages would put a deal in a column that
    // does not apply to it.
    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->assertSee('On sales')
        ->assertDontSee('On renewals')
        ->set('pipelineId', (string) $renewals->id)
        ->assertSee('On renewals')
        ->assertDontSee('On sales');
});

test('the board names the pipeline it is showing rather than claiming to show all', function () {
    $pipeline = dealPipeline();

    $component = Livewire::actingAs(dealUser())->test(DealsIndex::class);

    // In the list, "All pipelines" is the truth.
    $component->assertSee('All pipelines');

    // On the board it is not: the board is scoped to one pipeline, so the
    // control says which. The value is left empty on purpose — Tom Select sits
    // behind wire:ignore and keeps its own selection, so a server-set value
    // would show in the native select and not in the control on screen.
    $component->call('setViewMode', ViewMode::Kanban->value)
        ->assertSet('pipelineId', '')
        ->assertSee($pipeline->name)
        ->assertDontSee('All pipelines');
});

test('a board with no pipeline configured shows an error state, never a blank screen', function () {
    $user = dealUser();

    expect(Pipeline::query()->count())->toBe(0);

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->assertOk()
        ->assertSee('No pipeline to show a board for');
});

test('a board drag moves the deal through MoveDealStageAction', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'negotiation');

    expect($deal->fresh()->stage)->toBe('negotiation');
});

test('a board drag onto a stage that is not on the pipeline is refused with a reason', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'renewal_due')
        ->assertDispatched('notify', type: 'error');

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('a board drag into a closing stage says the reason is still missing', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'closed_won')
        ->assertDispatched('notify');

    expect($deal->fresh()->isWon())->toBeTrue()
        ->and($deal->fresh()->close_reason)->toBeNull();
});

test('the view mode persists per user', function () {
    dealPipeline();
    $user = dealUser();

    Livewire::actingAs($user)->test(DealsIndex::class)->call('setViewMode', ViewMode::Grid->value);

    expect(UserViewPreference::lookup($user, 'deals')->view_mode)->toBe(ViewMode::Grid->value);
});

test('a hidden column persists and leaves the table', function () {
    $pipeline = dealPipeline();
    $user = dealUser();
    Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->assertSee("wire:click=\"sort('value')\"", false)
        ->call('toggleColumn', 'value')
        ->assertDontSee("wire:click=\"sort('value')\"", false);

    expect(UserViewPreference::lookup($user, 'deals')->columns)->not->toContain('value');
});

// -- Weighted value on screen --------------------------------------------------

test('the totals describe the filtered set, not the page', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    // 20% and 60% stages, so the weighted total is 200 + 600.
    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['value' => '1000']);
    Deal::factory()->onPipeline($pipeline, 'negotiation')->create(['value' => '1000']);

    $totals = Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->set('perPage', 1)
        ->instance()
        ->totals();

    expect($totals['count'])->toBe(2)
        ->and($totals['value'])->toBe(2000.0)
        ->and($totals['weighted'])->toBe(800.0);
});

test('the totals follow the filters', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['value' => '1000']);
    Deal::factory()->onPipeline($pipeline, 'closed_won')->create(['value' => '5000']);

    $component = Livewire::actingAs($user)->test(DealsIndex::class)->call('setQuickFilter', 'open');

    expect($component->instance()->totals())
        ->toMatchArray(['count' => 1, 'value' => 1000.0, 'weighted' => 200.0]);
});

test('the totals do not count removed deals', function () {
    $user = dealAdmin();
    dealPipeline();

    Deal::factory()->ownedBy($user)->create(['value' => 100]);
    $gone = Deal::factory()->ownedBy($user)->create(['value' => 900]);
    $gone->delete();

    // The aggregate drops to the base builder, where Eloquent's global scopes
    // are not applied for it — so the soft-delete condition has to be put back
    // deliberately, or the figure describes rows the list does not show.
    $totals = Livewire::actingAs($user)->test(DealsIndex::class)->instance()->totals();

    expect($totals['count'])->toBe(1)->and($totals['value'])->toBe(100.0);
});

test('a deal whose pipeline has no matching stage counts at zero rather than dropping out', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    // A stage key that is not on this pipeline — the join finds nothing.
    Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage' => 'orphaned', 'value' => '1000']);

    $totals = Livewire::actingAs($user)->test(DealsIndex::class)->instance()->totals();

    expect($totals['count'])->toBe(1)
        ->and($totals['value'])->toBe(1000.0)
        ->and($totals['weighted'])->toBe(0.0);
});

// -- Filters -------------------------------------------------------------------

test('the quick filters narrow the list', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    $mine = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create(['name' => 'Mine']);
    $theirs = Deal::factory()->onPipeline($pipeline)->create(['name' => 'Theirs']);
    $won = Deal::factory()->onPipeline($pipeline, 'closed_won')->create(['name' => 'Bagged']);
    $late = Deal::factory()->onPipeline($pipeline)->create([
        'name' => 'Late one',
        'expected_close_date' => now()->subWeek()->format('Y-m-d'),
    ]);

    $component = Livewire::actingAs($user)->test(DealsIndex::class);

    $component->call('setQuickFilter', 'mine')->assertSee('Mine')->assertDontSee('Theirs');
    $component->call('setQuickFilter', 'won')->assertSee('Bagged')->assertDontSee('Mine');
    $component->call('setQuickFilter', 'overdue')->assertSee('Late one')->assertDontSee('Bagged');
    $component->call('setQuickFilter', 'open')->assertSee('Mine')->assertDontSee('Bagged');
});

test('a quick filter chip the screen does not offer is ignored', function () {
    dealPipeline();

    Livewire::actingAs(dealUser())
        ->test(DealsIndex::class)
        ->call('setQuickFilter', 'deals; drop table deals')
        ->assertSet('quickFilter', '')
        ->assertOk();
});

test('the filter builder narrows by value and by stage', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['name' => 'Small one', 'value' => '500']);
    Deal::factory()->onPipeline($pipeline, 'negotiation')->create(['name' => 'Big one', 'value' => '90000']);

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->set('filters', [
            'match' => 'all',
            'conditions' => [
                ['field' => 'value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '1000', 'value2' => null],
            ],
            'groups' => [],
        ])
        ->assertSee('Big one')
        ->assertDontSee('Small one');

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->set('filters', [
            'match' => 'all',
            'conditions' => [
                ['field' => 'stage', 'operator' => FilterOperator::Equals->value, 'value' => 'scoping', 'value2' => null],
            ],
            'groups' => [],
        ])
        ->assertSee('Small one')
        ->assertDontSee('Big one');
});

test('search matches the deal name and its notes', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->onPipeline($pipeline)->create(['name' => 'Q3 rollout', 'description' => 'seats']);
    Deal::factory()->onPipeline($pipeline)->create(['name' => 'Something else', 'description' => null]);

    $component = Livewire::actingAs($user)->test(DealsIndex::class);

    $component->set('search', 'rollout')->assertSee('Q3 rollout')->assertDontSee('Something else');
    $component->set('search', 'seats')->assertSee('Q3 rollout')->assertDontSee('Something else');
});

test('the list and a queued export search exactly the same columns', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view', 'deals.export']);

    Deal::factory()->onPipeline($pipeline)->create(['name' => 'Findable']);
    Deal::factory()->onPipeline($pipeline)->create(['name' => 'Hidden']);

    $onScreen = Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->set('search', 'Findable')
        ->instance()
        ->dataViewQuery()
        ->pluck('deals.id')
        ->all();

    $request = new ExportRequest(
        source: DealExportSource::class,
        format: ExportFormat::Csv,
        module: 'deals',
        columns: ['name' => 'Deal'],
        search: 'Findable',
        userId: $user->id,
    );

    // A column that is searchable on screen and not in an export — or the
    // other way round — is the drift the shared field declaration exists to
    // prevent.
    expect(app(DealExportSource::class)->exportQuery($request)->pluck('deals.id')->all())
        ->toBe($onScreen);
});

test('the stage filter offers every configured stage, and only once per key', function () {
    dealPipeline();
    savePipeline(pipelineData('Twin', [
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 30, 'color' => 'blue'],
        ['name' => 'Handover', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ]));

    $options = DealFields::stageOptions();

    expect(array_keys($options))->toContain('scoping', 'negotiation', 'closed_won', 'handover')
        ->and(array_keys($options))->toHaveCount(count(array_unique(array_keys($options))));
});

// -- Show ----------------------------------------------------------------------

test('the detail page offers the moves the pipeline allows, and no others', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $component = Livewire::actingAs($user)->test(DealShow::class, ['deal' => $deal]);

    expect($component->instance()->availableStages()->pluck('key')->all())
        // Everything except where it already is.
        ->toBe(['negotiation', 'closed_won', 'closed_lost']);

    $component->assertSee('Negotiation')->assertSee('Closed won');
});

test('moving to an open stage happens straight away', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'negotiation')
        ->assertDispatched('deal-updated')
        ->assertSet('closing', false);

    expect($deal->fresh()->stage)->toBe('negotiation');
});

test('moving to a closing stage asks why before it moves', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'closed_won')
        ->assertSet('closing', true)
        ->assertSet('closeStage', 'closed_won')
        ->assertSee('Why is this deal ending?');

    // Nothing moved yet: the reason is the one thing that cannot be filled in
    // later without somebody remembering.
    expect($deal->fresh()->stage)->toBe('scoping');
});

test('the closing form only offers reasons that match the outcome', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $component = Livewire::actingAs($user)->test(DealShow::class, ['deal' => $deal]);

    $component->call('moveTo', 'closed_won');
    $won = array_column($component->instance()->closeReasonOptions(), 'value');

    $component->call('moveTo', 'closed_lost');
    $lost = array_column($component->instance()->closeReasonOptions(), 'value');

    expect($won)->toContain(DealCloseReason::BestFit->value)
        ->and($won)->not->toContain(DealCloseReason::LostOnPrice->value)
        ->and($lost)->toContain(DealCloseReason::LostOnPrice->value)
        ->and($lost)->not->toContain(DealCloseReason::BestFit->value);
});

test('closing from the detail page records everything together', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'closed_won')
        ->set('closeReason', DealCloseReason::BestFit->value)
        ->set('closeNotes', 'Beat two others')
        ->call('close')
        ->assertHasNoErrors()
        ->assertSet('closing', false);

    $closed = $deal->fresh();

    expect($closed->stage)->toBe('closed_won')
        ->and($closed->closeReason())->toBe(DealCloseReason::BestFit)
        ->and($closed->close_notes)->toBe('Beat two others');
});

test('closing without a reason is refused', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'closed_lost')
        ->call('close')
        ->assertHasErrors(['closeReason' => 'required']);

    expect($deal->fresh()->isOpen())->toBeTrue();
});

test('a mismatched reason is reported on the field rather than thrown', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'closed_won')
        // A Lost reason against a Won stage.
        ->set('closeReason', DealCloseReason::NoBudget->value)
        ->call('close')
        ->assertHasErrors('closeReason')
        ->assertSee('Lost reason');
});

test('closing needs deals.close, not just deals.update', function () {
    $pipeline = dealPipeline();
    $user = dealUser(['deals.view', 'deals.update']);
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->call('moveTo', 'closed_won')
        ->assertForbidden();

    expect($deal->fresh()->isOpen())->toBeTrue();
});

test('reopening puts the deal back and drops the reason', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    app(CloseDealAction::class)($deal, 'closed_lost', DealCloseReason::WentQuiet);

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal->fresh()])
        ->call('reopen', 'negotiation')
        ->assertDispatched('deal-updated');

    expect($deal->fresh()->isOpen())->toBeTrue()
        ->and($deal->fresh()->close_reason)->toBeNull();
});

test('a deal is handed to somebody else', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $colleague = User::factory()->create();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->set('reassignTo', (string) $colleague->id)
        ->call('reassign')
        ->assertDispatched('deal-updated');

    expect($deal->fresh()->owner_id)->toBe($colleague->id);
});

test('reassigning needs deals.assign', function () {
    $pipeline = dealPipeline();
    $user = dealUser(['deals.view', 'deals.update']);
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal])
        ->set('reassignTo', (string) User::factory()->create()->id)
        ->call('reassign')
        ->assertForbidden();
});

test('the detail page carries the record timeline', function () {
    $pipeline = dealPipeline();
    $user = dealUser(['deals.view', 'timeline.view']);
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Note::factory()->on($deal)->create(['body' => 'worth remembering']);

    $this->actingAs($user)
        ->get(route('deals.show', $deal))
        ->assertOk()
        ->assertSee('Timeline')
        ->assertSee('worth remembering');
});

// -- Export --------------------------------------------------------------------

test('the export menu is offered only with deals.export', function () {
    $pipeline = dealPipeline();
    Deal::factory()->onPipeline($pipeline)->create();

    expect(Livewire::actingAs(dealUser())->test(DealsIndex::class)->instance()->dataViewExportSource())->toBeNull()
        ->and(Livewire::actingAs(dealAdmin())->test(DealsIndex::class)->instance()->dataViewExportSource())
        ->toBeInstanceOf(DealExportSource::class);
});

test('an export downloads and respects the active filters', function () {
    Excel::fake();

    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view', 'deals.export']);

    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['name' => 'Kept', 'value' => '9000']);
    Deal::factory()->onPipeline($pipeline, 'closed_won')->create(['name' => 'Dropped', 'value' => '9000']);

    // The clock is frozen because ExportRequest fixes the filename at
    // construction: without it the assertion and the file can straddle a
    // second and disagree.
    Carbon::setTestNow('2026-05-01 09:00:00');

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('setQuickFilter', 'open')
        ->call('export', ExportFormat::Csv->value)
        ->assertOk();

    Excel::assertDownloaded('deals-'.now()->format('Y-m-d-His').'.csv');
});

test('an export re-applies the visibility scope', function () {
    $pipeline = dealPipeline();

    $owner = dealUser(['deals.view', 'deals.export']);
    $peer = dealUser(['deals.view', 'deals.export']);

    Deal::factory()->ownedBy($owner)->onPipeline($pipeline)->create(['name' => 'Theirs only']);

    // A queued export runs without a session, so the source has to scope it
    // again or somebody is emailed rows they could not see.
    $request = new ExportRequest(
        source: DealExportSource::class,
        format: ExportFormat::Csv,
        module: 'deals',
        columns: ['name' => 'Deal'],
        userId: $peer->id,
    );

    expect(app(DealExportSource::class)->exportQuery($request)->count())->toBe(0);
});

test('the export renders the stage and the weighted value as data, not markup', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view', 'deals.export']);
    $deal = Deal::factory()->onPipeline($pipeline, 'negotiation')->create(['value' => '1000']);

    $request = new ExportRequest(
        source: DealExportSource::class,
        format: ExportFormat::Csv,
        module: 'deals',
        columns: ['stage' => 'Stage', 'weighted_value' => 'Weighted'],
        userId: $user->id,
    );

    $row = app(DealExportSource::class)->exportRow($deal->fresh(), $request);

    // A spreadsheet column of "£600.00" cannot be summed.
    expect($row[0])->toBe('Negotiation')
        ->and($row[1])->toBe(600.0);
});

// -- Dropdowns -----------------------------------------------------------------

test('every dropdown on the deal screens is an x-select', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $screens = [
        Livewire::actingAs($user)->test(DealsIndex::class)->html(),
        Livewire::actingAs($user)->test(DealForm::class)->html(),
        Livewire::actingAs($user)->test(DealShow::class, ['deal' => $deal])->html(),
    ];

    foreach ($screens as $html) {
        // The component renders one native <select> for Tom Select to take
        // over, so the counts matching is what proves there is no plain one.
        expect(substr_count($html, '<select'))->toBe(substr_count($html, 'tomSelectField('));
    }
});

test('the contact picker depends on the account and pages its results', function () {
    dealPipeline();
    $user = dealAdmin();
    $account = Account::factory()->create();
    Contact::factory()->count(30)->create(['account_id' => $account->id]);
    Contact::factory()->create(['account_id' => Account::factory()->create()->id, 'last_name' => 'Elsewhere']);

    $component = Livewire::actingAs($user)->test(DealForm::class);

    // No account chosen yet, so there is nothing to pick from.
    expect($component->instance()->searchContacts(null, 1)['options'])->toBe([]);

    $component->set('account_id', (string) $account->id);

    $first = $component->instance()->searchContacts(null, 1);
    $second = $component->instance()->searchContacts(null, 2);

    expect($first['options'])->toHaveCount(DealForm::PER_PAGE)
        ->and($first['hasMore'])->toBeTrue()
        ->and($second['options'])->toHaveCount(5)
        ->and($second['hasMore'])->toBeFalse()
        // Nobody from another account, whatever the term.
        ->and(collect($component->instance()->searchContacts('Elsewhere', 1)['options']))->toBeEmpty();
});

test('the account picker accepts a null term, which is what Tom Select sends', function () {
    dealPipeline();
    Account::factory()->create(['name' => 'Acme']);

    // A string parameter here turns a preload into a silent 500 that the
    // dropdown shows as "No matches found".
    $options = Livewire::actingAs(dealAdmin())->test(DealForm::class)->instance()->searchAccounts(null, 1);

    expect($options['options'])->not->toBeEmpty();
});
