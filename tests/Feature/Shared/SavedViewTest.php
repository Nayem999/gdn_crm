<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\SavedView;
use App\Domain\Shared\Models\UserViewPreference;
use App\Domain\Shared\SavedViews\SavedViewState;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Contacts\ContactsIndex;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Leads\LeadsIndex;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function savedViewUser(array $permissions = ['leads.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * An arrangement that differs from the screen's defaults in every dimension the
 * task names: filters, columns, sort and view mode.
 */
function arrangedState(): SavedViewState
{
    return new SavedViewState(
        viewMode: ViewMode::Grid->value,
        search: 'northwind',
        sortBy: 'company_name',
        sortDirection: 'desc',
        perPage: 50,
        visibleColumns: ['name', 'company_name', 'email'],
        pinnedColumns: ['name'],
        filters: [
            'match' => 'all',
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Leeds']],
            'groups' => [],
        ],
    );
}

beforeEach(function () {
    Cache::flush();
});

// -- The headline requirement --------------------------------------------------

test('a saved view restores filters, columns, sort and view mode', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(arrangedState())->create();

    $screen = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id);

    $screen->assertSet('viewMode', ViewMode::Grid->value)
        ->assertSet('search', 'northwind')
        ->assertSet('sortBy', 'company_name')
        ->assertSet('sortDirection', 'desc')
        ->assertSet('perPage', 50)
        ->assertSet('visibleColumns', ['name', 'company_name', 'email'])
        ->assertSet('pinnedColumns', ['name'])
        ->assertSet('savedViewId', $view->id);

    expect($screen->instance()->filters['conditions'][0]['field'])->toBe('city');
});

test('a restored view actually filters the list', function () {
    $user = savedViewUser();

    Lead::factory()->ownedBy($user)->create(['last_name' => 'Ashworth', 'city' => 'Leeds']);
    Lead::factory()->ownedBy($user)->create(['last_name' => 'Pemberton', 'city' => 'Bristol']);

    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(
        filters: [
            'match' => 'all',
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Leeds']],
            'groups' => [],
        ],
    ))->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSee('Ashworth')
        ->assertDontSee('Pemberton');
});

test('saving captures what is on screen, and reopening puts it back', function () {
    $user = savedViewUser();

    $screen = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->set('search', 'northwind')
        ->call('setViewMode', ViewMode::Grid->value)
        ->call('sort', 'company_name')
        ->call('startSavingView')
        ->set('savedViewName', 'Leeds prospects')
        ->call('saveView')
        ->assertHasNoErrors();

    $saved = SavedView::query()->firstOrFail();

    expect($saved->name)->toBe('Leeds prospects')
        ->and($saved->state()->search)->toBe('northwind')
        ->and($saved->state()->viewMode)->toBe(ViewMode::Grid->value)
        ->and($saved->state()->sortBy)->toBe('company_name');

    // A fresh screen, reopened on the view, comes back to the same place.
    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $saved->id)
        ->assertSet('search', 'northwind')
        ->assertSet('viewMode', ViewMode::Grid->value)
        ->assertSet('sortBy', 'company_name');
});

// -- A view can be older than the screen --------------------------------------

test('a column the module no longer offers does not come back', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(
        visibleColumns: ['name', 'a_column_that_went_away'],
    ))->create();

    $columns = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->instance()
        ->visibleColumns;

    expect($columns)->toContain('name')
        ->and($columns)->not->toContain('a_column_that_went_away');
});

test('a filter on a field the screen no longer declares is dropped', function () {
    // Left in, it would be silently ignored by the applier and the person would
    // see a filter chip that narrows nothing.
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(
        filters: [
            'match' => 'all',
            'conditions' => [
                ['field' => 'city', 'operator' => 'equals', 'value' => 'Leeds'],
                ['field' => 'retired_field', 'operator' => 'equals', 'value' => 'x'],
            ],
            'groups' => [],
        ],
    ))->create();

    $filters = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->instance()
        ->filters;

    expect($filters['conditions'])->toHaveCount(1)
        ->and($filters['conditions'][0]['field'])->toBe('city');
});

test('a sort on a column that is gone falls back to no sort', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(sortBy: 'retired'))->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSet('sortBy', '');
});

test('a sort on a column that exists but is not sortable is refused', function () {
    // `owner` is declared sortable: false on leads.
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(sortBy: 'owner'))->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSet('sortBy', '');
});

test('a view mode and page size the screen does not offer fall back', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(
        viewMode: 'hologram',
        perPage: 999,
    ))->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSet('viewMode', ViewMode::Table->value)
        ->assertSet('perPage', 25);
});

test('a view with no columns at all falls back to the default set', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(visibleColumns: []))->create();

    $columns = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->instance()
        ->visibleColumns;

    expect($columns)->not->toBeEmpty();
});

// -- Whose view is it ----------------------------------------------------------

test('a private view belongs to its owner alone', function () {
    $owner = savedViewUser();
    $stranger = savedViewUser();

    $view = SavedView::factory()->ownedBy($owner)->create(['name' => 'My private list']);

    expect(Livewire::actingAs($owner)->test(LeadsIndex::class)->instance()->savedViews()->pluck('id')->all())
        ->toBe([$view->id]);

    expect(Livewire::actingAs($stranger)->test(LeadsIndex::class)->instance()->savedViews())->toBeEmpty();
});

test('somebody else private view cannot be opened by guessing its id', function () {
    $owner = savedViewUser();
    $stranger = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->withState(arrangedState())->create();

    Livewire::actingAs($stranger)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSet('savedViewId', null)
        ->assertSet('search', '');
});

test('a shared view is offered to everybody', function () {
    $owner = savedViewUser();
    $colleague = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->shared()->create(['name' => 'Team pipeline']);

    expect(Livewire::actingAs($colleague)->test(LeadsIndex::class)->instance()->savedViews()->pluck('id')->all())
        ->toBe([$view->id]);
});

test('a shared view shows each person only their own records', function () {
    // It carries a filter, not a result set.
    $owner = savedViewUser();
    $colleague = savedViewUser();

    Lead::factory()->ownedBy($owner)->create(['last_name' => 'Ashworth', 'city' => 'Leeds']);
    Lead::factory()->ownedBy($colleague)->create(['last_name' => 'Pemberton', 'city' => 'Leeds']);

    $view = SavedView::factory()->ownedBy($owner)->shared()->withState(new SavedViewState(
        filters: [
            'match' => 'all',
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Leeds']],
            'groups' => [],
        ],
    ))->create();

    Livewire::actingAs($colleague)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->assertSee('Pemberton')
        ->assertDontSee('Ashworth');
});

test('only the owner may remove a view', function () {
    $owner = savedViewUser();
    $colleague = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->shared()->create();

    Livewire::actingAs($colleague)
        ->test(LeadsIndex::class)
        ->call('deleteSavedView', $view->id);

    expect(SavedView::query()->count())->toBe(1);

    Livewire::actingAs($owner)
        ->test(LeadsIndex::class)
        ->call('deleteSavedView', $view->id);

    expect(SavedView::query()->count())->toBe(0);
});

test('only the owner may update a view in place', function () {
    $owner = savedViewUser();
    $colleague = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->shared()->withState(new SavedViewState(search: 'original'))->create();

    Livewire::actingAs($colleague)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->set('search', 'changed')
        ->call('updateSavedView');

    expect($view->fresh()->state()->search)->toBe('original');
});

test('a view from another module is never offered', function () {
    $user = savedViewUser();
    SavedView::factory()->ownedBy($user)->forModule('deals')->create(['name' => 'Deal view']);

    expect(Livewire::actingAs($user)->test(LeadsIndex::class)->instance()->savedViews())->toBeEmpty();
});

// -- Sharing is permission-gated ----------------------------------------------

test('sharing needs the permission, and a payload cannot grant it', function () {
    $user = savedViewUser(['leads.view']);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('startSavingView')
        ->set('savedViewName', 'Mine only')
        ->set('savedViewShared', true)
        ->call('saveView');

    expect(SavedView::query()->firstOrFail()->is_shared)->toBeFalse();
});

test('somebody with the permission may share', function () {
    $user = savedViewUser(['leads.view', 'saved-views.share']);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('startSavingView')
        ->set('savedViewName', 'Team list')
        ->set('savedViewShared', true)
        ->call('saveView');

    expect(SavedView::query()->firstOrFail()->is_shared)->toBeTrue();
});

test('the share permission is declared in the catalogue', function () {
    expect(PermissionCatalogue::has('saved-views.share'))->toBeTrue();
});

// -- Saving --------------------------------------------------------------------

test('a view needs a name', function () {
    Livewire::actingAs(savedViewUser())
        ->test(LeadsIndex::class)
        ->call('startSavingView')
        ->call('saveView')
        ->assertHasErrors('savedViewName');
});

test('saving over a name you already used replaces that view', function () {
    $user = savedViewUser();

    $screen = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('startSavingView')
        ->set('savedViewName', 'My list')
        ->call('saveView')
        ->set('search', 'second time')
        ->call('startSavingView')
        ->set('savedViewName', 'My list')
        ->call('saveView');

    expect(SavedView::query()->count())->toBe(1)
        ->and(SavedView::query()->firstOrFail()->state()->search)->toBe('second time');

    $screen->assertHasNoErrors();
});

test('two people may each have a view of the same name', function () {
    $one = savedViewUser();
    $two = savedViewUser();

    foreach ([$one, $two] as $user) {
        Livewire::actingAs($user)
            ->test(LeadsIndex::class)
            ->call('startSavingView')
            ->set('savedViewName', 'My list')
            ->call('saveView')
            ->assertHasNoErrors();
    }

    expect(SavedView::query()->count())->toBe(2);
});

test('the screen says when it has drifted from the view it was opened on', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(search: 'original'))->create();

    $screen = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id);

    expect($screen->instance()->savedViewHasChanges())->toBeFalse();

    $screen->set('search', 'something else');

    expect($screen->instance()->savedViewHasChanges())->toBeTrue();
});

test('the owner can update the open view in place', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(new SavedViewState(search: 'original'))->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('applySavedView', $view->id)
        ->set('search', 'updated')
        ->call('updateSavedView');

    expect($view->fresh()->state()->search)->toBe('updated');
});

// -- The default view ----------------------------------------------------------

test('a module opens on the view somebody chose as their default', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->withState(arrangedState())->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('makeSavedViewDefault', $view->id);

    expect(UserViewPreference::lookup($user, 'leads')?->default_saved_view_id)->toBe($view->id);

    // A fresh visit opens on it.
    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->assertSet('savedViewId', $view->id)
        ->assertSet('search', 'northwind')
        ->assertSet('viewMode', ViewMode::Grid->value);
});

test('a default is per person, not per view', function () {
    // An owner sharing a view must not change what everybody else's module
    // opens on.
    $owner = savedViewUser();
    $colleague = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->shared()->withState(arrangedState())->create();

    Livewire::actingAs($owner)
        ->test(LeadsIndex::class)
        ->call('makeSavedViewDefault', $view->id);

    Livewire::actingAs($colleague)
        ->test(LeadsIndex::class)
        ->assertSet('savedViewId', null);
});

test('a default can be cleared', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->create();

    $screen = Livewire::actingAs($user)->test(LeadsIndex::class);
    $screen->call('makeSavedViewDefault', $view->id);
    $screen->call('makeSavedViewDefault', null);

    expect(UserViewPreference::lookup($user, 'leads')?->default_saved_view_id)->toBeNull();
});

test('a default view that has been deleted does not break the module', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)->test(LeadsIndex::class)->call('makeSavedViewDefault', $view->id);

    // Deleting the row nulls the preference rather than taking the layout with
    // it, and the screen opens as though nothing had been chosen.
    $view->delete();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->assertOk()
        ->assertSet('savedViewId', null);

    expect(UserViewPreference::lookup($user, 'leads'))->not->toBeNull();
});

test('somebody else default cannot be set by guessing an id', function () {
    $owner = savedViewUser();
    $stranger = savedViewUser();
    $view = SavedView::factory()->ownedBy($owner)->create();

    Livewire::actingAs($stranger)
        ->test(LeadsIndex::class)
        ->call('makeSavedViewDefault', $view->id);

    expect(UserViewPreference::lookup($stranger, 'leads')?->default_saved_view_id)->toBeNull();
});

// -- Every module has them -----------------------------------------------------

test('every list screen carries saved views', function (string $component) {
    $user = savedViewUser([
        'leads.view', 'contacts.view', 'accounts.view', 'deals.view', 'activities.view',
    ]);

    expect(Livewire::actingAs($user)->test($component)->instance()->savedViews())->toBeEmpty();
})->with([
    LeadsIndex::class,
    ContactsIndex::class,
    AccountsIndex::class,
    DealsIndex::class,
    ActivitiesIndex::class,
]);

test('the audit trail records a view without its filter values', function () {
    $user = savedViewUser();
    $view = SavedView::factory()->ownedBy($user)->create();

    $logged = (new ReflectionClass(SavedView::class))->getMethod('activityAttributes');
    $logged->setAccessible(true);

    // `state` is a blob nobody reads in an audit entry, and logging it would
    // put every filter value somebody used into the trail.
    expect($logged->invoke($view))->toContain('name')
        ->and($logged->invoke($view))->not->toContain('state');
});
