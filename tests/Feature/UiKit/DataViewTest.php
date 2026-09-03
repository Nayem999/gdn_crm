<?php

use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\UserViewPreference;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Fixtures\DataViewHarness;
use Tests\Fixtures\DataViewRecord;
use Tests\Fixtures\DataViewRecordPolicy;

beforeEach(function () {
    DataViewRecord::createTable();
    DataViewRecord::query()->delete();

    Gate::policy(DataViewRecord::class, DataViewRecordPolicy::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    foreach ([
        ['name' => 'Acme Corporation', 'stage' => 'new', 'value' => 1000, 'closes_on' => '2026-01-10', 'is_starred' => true],
        ['name' => 'Beta Industries', 'stage' => 'won', 'value' => 5000, 'closes_on' => '2026-02-20', 'is_starred' => false],
        ['name' => 'Gamma Holdings', 'stage' => 'lost', 'value' => 250, 'closes_on' => '2026-03-30', 'is_starred' => false],
    ] as $attributes) {
        DataViewRecord::query()->create($attributes);
    }
});

test('the list renders with its default columns and every row', function () {
    Livewire::test(DataViewHarness::class)
        ->assertOk()
        ->assertSee('Acme Corporation')
        ->assertSee('Beta Industries')
        ->assertSee('Gamma Holdings')
        // The table header carries a sort control per visible column.
        ->assertSee("wire:click=\"sort('stage')\"", false)
        // Columns marked optional stay out of the table until switched on,
        // though the column manager still offers them.
        ->assertDontSee("wire:click=\"sort('is_starred')\"", false)
        ->assertSee("wire:click=\"toggleColumn('is_starred')\"", false);
});

test('switching view mode persists for that user and module', function () {
    Livewire::test(DataViewHarness::class)
        ->assertSet('viewMode', ViewMode::Table->value)
        ->call('setViewMode', ViewMode::Grid->value)
        ->assertSet('viewMode', ViewMode::Grid->value);

    $preference = UserViewPreference::lookup($this->user, 'data-view-records');

    expect($preference)->not->toBeNull()
        ->and($preference->view_mode)->toBe(ViewMode::Grid->value);

    // A fresh visit comes back to the view they left in.
    Livewire::test(DataViewHarness::class)->assertSet('viewMode', ViewMode::Grid->value);
});

test('an unknown view mode is ignored rather than stored', function () {
    Livewire::test(DataViewHarness::class)
        ->call('setViewMode', 'holographic')
        ->assertSet('viewMode', ViewMode::Table->value);

    expect(UserViewPreference::lookup($this->user, 'data-view-records'))->toBeNull();
});

test('every view mode renders its own markup', function (ViewMode $mode) {
    Livewire::test(DataViewHarness::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Acme Corporation');
})->with(ViewMode::cases());

test('hiding a column persists and removes it from the table', function () {
    Livewire::test(DataViewHarness::class)
        ->assertSee("wire:click=\"sort('stage')\"", false)
        ->call('toggleColumn', 'stage')
        ->assertSet('visibleColumns', ['name', 'value', 'closes_on'])
        ->assertDontSee("wire:click=\"sort('stage')\"", false)
        ->assertSee("wire:click=\"sort('value')\"", false);

    expect(UserViewPreference::lookup($this->user, 'data-view-records')->columns)
        ->toBe(['name', 'value', 'closes_on']);

    Livewire::test(DataViewHarness::class)->assertSet('visibleColumns', ['name', 'value', 'closes_on']);
});

test('showing an optional column persists too', function () {
    Livewire::test(DataViewHarness::class)
        ->call('toggleColumn', 'is_starred')
        ->assertSee("wire:click=\"sort('is_starred')\"", false);

    expect(UserViewPreference::lookup($this->user, 'data-view-records')->columns)
        ->toContain('is_starred');
});

test('a locked column cannot be switched off', function () {
    Livewire::test(DataViewHarness::class)
        ->call('toggleColumn', 'name')
        ->assertSet('visibleColumns', ['name', 'stage', 'value', 'closes_on']);
});

test('column order persists and a locked column is kept present', function () {
    Livewire::test(DataViewHarness::class)
        ->call('reorderColumns', ['value', 'stage', 'closes_on'])
        // "name" is locked, so it is restored at the front rather than dropped.
        ->assertSet('visibleColumns', ['name', 'value', 'stage', 'closes_on']);

    expect(UserViewPreference::lookup($this->user, 'data-view-records')->columns)
        ->toBe(['name', 'value', 'stage', 'closes_on']);
});

test('a stored layout naming a column the screen no longer offers is discarded', function () {
    UserViewPreference::remember($this->user, 'data-view-records', [
        'columns' => ['name', 'legacy_column', 'value'],
    ]);

    Livewire::test(DataViewHarness::class)
        ->assertSet('visibleColumns', ['name', 'value'])
        ->assertOk();
});

test('pinning a column persists, forces it visible and puts it first', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->call('togglePin', 'value')
        ->assertSet('pinnedColumns', ['value']);

    expect($component->instance()->pinnedThenLooseColumns()[0]->key)->toBe('value');

    $preference = UserViewPreference::lookup($this->user, 'data-view-records');
    expect($preference->pinned_columns)->toBe(['value']);

    Livewire::test(DataViewHarness::class)->assertSet('pinnedColumns', ['value']);
});

test('pinning a hidden column brings it back into view', function () {
    Livewire::test(DataViewHarness::class)
        ->call('togglePin', 'is_starred')
        ->assertSet('pinnedColumns', ['is_starred'])
        ->assertSet('visibleColumns', ['name', 'stage', 'value', 'closes_on', 'is_starred'])
        ->assertSee("wire:click=\"sort('is_starred')\"", false);
});

test('hiding a pinned column drops the pin with it', function () {
    Livewire::test(DataViewHarness::class)
        ->call('togglePin', 'stage')
        ->call('toggleColumn', 'stage')
        ->assertSet('pinnedColumns', [])
        ->assertSet('visibleColumns', ['name', 'value', 'closes_on']);
});

test('resetting columns restores the defaults and clears pins', function () {
    Livewire::test(DataViewHarness::class)
        ->call('toggleColumn', 'stage')
        ->call('togglePin', 'value')
        ->call('resetColumns')
        ->assertSet('visibleColumns', ['name', 'stage', 'value', 'closes_on'])
        ->assertSet('pinnedColumns', []);

    $preference = UserViewPreference::lookup($this->user, 'data-view-records');

    expect($preference->columns)->toBe(['name', 'stage', 'value', 'closes_on'])
        ->and($preference->pinned_columns)->toBe([]);
});

test('rows per page persists and is limited to the offered sizes', function () {
    Livewire::test(DataViewHarness::class)
        ->call('setPerPage', 50)
        ->assertSet('perPage', 50)
        ->call('setPerPage', 9999)
        ->assertSet('perPage', 25);

    expect(UserViewPreference::lookup($this->user, 'data-view-records')->per_page)->toBe(25);
});

test('one user\'s layout never reaches another', function () {
    Livewire::test(DataViewHarness::class)->call('setViewMode', ViewMode::List->value);

    $this->actingAs(User::factory()->create());

    Livewire::test(DataViewHarness::class)->assertSet('viewMode', ViewMode::Table->value);
});

test('searching narrows the list', function () {
    Livewire::test(DataViewHarness::class)
        ->set('search', 'Beta')
        ->assertSee('Beta Industries')
        ->assertDontSee('Acme Corporation');
});

test('sorting toggles direction on the same column and resets on a new one', function () {
    Livewire::test(DataViewHarness::class)
        ->call('sort', 'value')
        ->assertSet('sortBy', 'value')
        ->assertSet('sortDirection', 'asc')
        ->call('sort', 'value')
        ->assertSet('sortDirection', 'desc')
        ->call('sort', 'name')
        ->assertSet('sortBy', 'name')
        ->assertSet('sortDirection', 'asc');
});

test('sorting by an unknown column is ignored', function () {
    Livewire::test(DataViewHarness::class)
        ->call('sort', 'secret_column')
        ->assertSet('sortBy', '');
});

test('a filter condition narrows the list and shows a chip', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'stage')
        ->set('filters.conditions.0.operator', 'equals')
        ->set('filters.conditions.0.value', 'won');

    $component->assertSee('Beta Industries')
        ->assertDontSee('Acme Corporation')
        ->assertSee('Stage is Won');

    expect($component->instance()->activeFilterCount())->toBe(1);
});

test('a half-filled condition filters nothing and is not counted', function () {
    $component = Livewire::test(DataViewHarness::class)->call('addCondition');

    expect($component->instance()->activeFilterCount())->toBe(0);

    $component->assertSee('Acme Corporation')
        ->assertSee('Beta Industries')
        ->assertSee('Gamma Holdings');
});

test('changing a condition field to another type resets its operator', function () {
    Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.operator', 'contains')
        ->set('filters.conditions.0.value', 'Acme')
        ->set('filters.conditions.0.field', 'is_starred')
        // "contains" is meaningless for a boolean, so it falls back.
        ->assertSet('filters.conditions.0.operator', 'is_true')
        ->assertSet('filters.conditions.0.value', null);
});

test('nested groups combine with the root conditions', function () {
    Livewire::test(DataViewHarness::class)
        ->call('addFilterGroup')
        ->set('filters.groups.0.match', 'any')
        ->set('filters.groups.0.conditions.0.field', 'stage')
        ->set('filters.groups.0.conditions.0.operator', 'equals')
        ->set('filters.groups.0.conditions.0.value', 'won')
        ->assertSee('Beta Industries')
        ->assertDontSee('Acme Corporation');
});

test('clearing filters brings every row back', function () {
    Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'stage')
        ->set('filters.conditions.0.operator', 'equals')
        ->set('filters.conditions.0.value', 'won')
        ->assertDontSee('Acme Corporation')
        ->call('clearFilters')
        ->assertSee('Acme Corporation')
        ->assertSee('Gamma Holdings');
});

test('removing a condition by chip index drops just that condition', function () {
    Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'stage')
        ->set('filters.conditions.0.operator', 'equals')
        ->set('filters.conditions.0.value', 'won')
        ->call('removeCondition', 0)
        ->assertSet('filters.conditions', [])
        ->assertSee('Acme Corporation');
});

test('selecting rows drives the bulk actions bar', function () {
    $first = DataViewRecord::query()->first();

    Livewire::test(DataViewHarness::class)
        ->call('toggleSelection', $first->id)
        ->assertSet('selected', [$first->id])
        ->assertSee('1 record selected')
        ->call('toggleSelection', $first->id)
        ->assertSet('selected', [])
        ->assertDontSee('record selected');
});

test('select all on the page picks up every visible row and clears again', function () {
    $component = Livewire::test(DataViewHarness::class)->call('togglePageSelection');

    expect($component->get('selected'))->toHaveCount(3);

    $component->call('togglePageSelection');

    expect($component->get('selected'))->toBe([]);
});

test('page selection only covers rows the current filter shows', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->set('search', 'Beta')
        ->call('togglePageSelection');

    expect($component->get('selected'))->toHaveCount(1);
});

test('select all matching counts every filtered row, not just the page', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->set('search', 'a')
        ->call('selectAllMatchingFilters');

    expect($component->instance()->selectionCount())->toBe(3)
        ->and($component->instance()->hasSelection())->toBeTrue();
});

test('a kanban move writes the new stage', function () {
    $record = DataViewRecord::query()->where('stage', 'new')->first();

    Livewire::test(DataViewHarness::class)->call('moveCard', $record->id, 'won');

    expect($record->fresh()->stage)->toBe('won');
});

test('a kanban move to a column the board does not offer is refused', function () {
    $record = DataViewRecord::query()->where('stage', 'new')->first();

    Livewire::test(DataViewHarness::class)->call('moveCard', $record->id, 'deleted');

    expect($record->fresh()->stage)->toBe('new');
});

test('a kanban move the policy forbids is refused', function () {
    $record = DataViewRecord::query()->where('stage', 'lost')->first();

    Livewire::test(DataViewHarness::class)
        ->call('moveCard', $record->id, 'won')
        ->assertForbidden();

    expect($record->fresh()->stage)->toBe('lost');
});

test('a kanban move naming a record outside the visible query is refused', function () {
    $record = DataViewRecord::query()->where('stage', 'new')->first();
    $missingId = DataViewRecord::query()->max('id') + 500;

    Livewire::test(DataViewHarness::class)->call('moveCard', $missingId, 'won');

    expect($record->fresh()->stage)->toBe('new');
});

test('the empty state appears when nothing matches, and says so', function () {
    Livewire::test(DataViewHarness::class)
        ->set('search', 'nothing matches this')
        ->assertSee('No matching records')
        ->assertDontSee('No records yet');
});

test('the empty state invites a first record when the module is simply empty', function () {
    DataViewRecord::query()->delete();

    Livewire::test(DataViewHarness::class)
        ->assertSee('No records yet')
        ->assertDontSee('No matching records');
});
