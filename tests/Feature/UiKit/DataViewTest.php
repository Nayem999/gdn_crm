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

// -- The filter builder uses x-select, not plain dropdowns ---------------------

/**
 * The builder rendered on its own, so a count of dropdowns is a count of the
 * builder's dropdowns and not of whatever else the page happens to show.
 *
 * @param  array<int, array<string, mixed>>  $conditions
 * @param  array<int, array<string, mixed>>  $groups
 */
function filterBuilderHtml(array $conditions = [], array $groups = []): string
{
    $fields = collect((new DataViewHarness)->dataViewFilterFields())->keyBy('key')->all();

    return (string) test()->blade(
        '<x-filter-builder :fields="$fields" :filters="$filters" :count="1" />',
        [
            'fields' => $fields,
            'filters' => ['match' => 'all', 'conditions' => $conditions, 'groups' => $groups],
        ]
    );
}

test('every dropdown in the filter builder is the searchable select', function () {
    $html = filterBuilderHtml([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'won', 'second_value' => null, 'selected' => []],
    ]);

    // Each <x-select> renders exactly one native <select> for Tom Select to
    // take over, so equal counts mean nothing is a plain dropdown. The match
    // switch, the field, the comparison and the value make four.
    expect(substr_count($html, '<select'))->toBe(4)
        ->and(substr_count($html, 'tomSelectField('))->toBe(4);
});

test('a nested group adds its own match select and none of them are plain', function () {
    $html = filterBuilderHtml(
        [['field' => 'name', 'operator' => 'contains', 'value' => 'Acme', 'second_value' => null, 'selected' => []]],
        [[
            'match' => 'any',
            'conditions' => [
                ['field' => 'stage', 'operator' => 'equals', 'value' => 'won', 'second_value' => null, 'selected' => []],
            ],
            'groups' => [],
        ]],
    );

    // Two match switches, two field and two comparison dropdowns, plus the
    // grouped condition's value. The text condition's value is an input.
    expect(substr_count($html, '<select'))->toBe(7)
        ->and(substr_count($html, 'tomSelectField('))->toBe(7);
});

test('a text condition uses an input, not a dropdown, for its value', function () {
    $html = filterBuilderHtml([
        ['field' => 'name', 'operator' => 'contains', 'value' => null, 'second_value' => null, 'selected' => []],
    ]);

    // Match, field and comparison only: the value is free text.
    expect(substr_count($html, 'tomSelectField('))->toBe(3)
        ->and($html)->toContain('wire:model.live.debounce.400ms="filters.conditions.0.value"');
});

test('an operator needing no value renders no third control at all', function () {
    $html = filterBuilderHtml([
        ['field' => 'name', 'operator' => 'is_empty', 'value' => null, 'second_value' => null, 'selected' => []],
    ]);

    expect(substr_count($html, 'tomSelectField('))->toBe(3)
        ->and($html)->not->toContain('filters.conditions.0.value');
});

test('a range condition renders both ends as inputs', function () {
    $html = filterBuilderHtml([
        ['field' => 'value', 'operator' => 'between', 'value' => '1000', 'second_value' => '9000', 'selected' => []],
    ]);

    expect($html)->toContain('wire:model.live.debounce.400ms="filters.conditions.0.value"')
        ->and($html)->toContain('wire:model.live.debounce.400ms="filters.conditions.0.second_value"')
        ->and(substr_count($html, 'tomSelectField('))->toBe(3);
});

test('the dropdowns carry the labels they had as plain selects', function () {
    $html = filterBuilderHtml([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'won', 'second_value' => null, 'selected' => []],
    ]);

    // Tom Select copies aria-label from the original select onto its own
    // control, so passing it through keeps the controls named.
    expect($html)->toContain('aria-label="Filter field"')
        ->and($html)->toContain('aria-label="Filter operator"')
        ->and($html)->toContain('aria-label="Filter value"')
        ->and($html)->toContain('aria-label="Match all or any condition"');
});

test('the comparison dropdown is keyed on the field so Tom Select rebuilds', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'name');

    // wire:ignore stops Livewire updating the options in place; changing the
    // key is what makes it replace the node.
    expect($component->html())->toContain('wire:key="filters.conditions.0-1-op-name"');

    $component->set('filters.conditions.0.field', 'value');

    expect($component->html())->toContain('wire:key="filters.conditions.0-1-op-value"')
        ->and($component->html())->not->toContain('-op-name"');
});

test('the value area is keyed on the field and comparison, since its shape changes', function () {
    $component = Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'value')
        ->set('filters.conditions.0.operator', 'gt');

    expect($component->html())->toContain('wire:key="filters.conditions.0-1-val-value-gt"');

    $component->set('filters.conditions.0.operator', 'between');

    expect($component->html())->toContain('wire:key="filters.conditions.0-1-val-value-between"');
});

test('editing a value does not re-key its dropdown, so the list stays open', function () {
    // Keying a dropdown on its own selection tears the node down on every pick,
    // which for "is any of" means reopening the list once per value.
    $component = Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'stage')
        ->set('filters.conditions.0.operator', 'in');

    $before = $component->html();

    $component->set('filters.conditions.0.selected', ['won', 'lost']);

    expect($before)->toContain('wire:key="filters.conditions.0-1-val-stage-in"')
        ->and($component->html())->toContain('wire:key="filters.conditions.0-1-val-stage-in"')
        ->and($component->html())->toContain('multiple');
});

test('the match switches are keyed on their value', function () {
    $component = Livewire::test(DataViewHarness::class)->call('addFilterGroup');

    expect($component->html())->toContain('wire:key="filters-match-all"')
        ->and($component->html())->toContain('wire:key="filters-group-0-match-any"');

    $component->set('filters.match', 'any')->set('filters.groups.0.match', 'all');

    expect($component->html())->toContain('wire:key="filters-match-any"')
        ->and($component->html())->toContain('wire:key="filters-group-0-match-all"');
});

test('removing a condition re-keys the row that takes its place', function () {
    // Rows are keyed by index, so dropping the first hands row 0's DOM to what
    // used to be row 1. Without a key that moves, the ignored Tom Select
    // subtree would keep showing the removed condition.
    $component = Livewire::test(DataViewHarness::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'name')
        ->call('addCondition')
        ->set('filters.conditions.1.field', 'stage');

    expect($component->html())->toContain('wire:key="filters.conditions.0-2-field"')
        ->and($component->html())->toContain('wire:key="filters.conditions.1-2-field"');

    $component->call('removeCondition', 0);

    // The sibling count fell to one, so every remaining row is re-keyed and
    // Tom Select rebuilds from the condition the row actually holds now.
    expect($component->html())->toContain('wire:key="filters.conditions.0-1-field"')
        ->and($component->html())->not->toContain('-2-field"')
        ->and($component->instance()->filters['conditions'][0]['field'])->toBe('stage');
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
