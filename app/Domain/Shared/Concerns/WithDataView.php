<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterCondition;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Shared\Models\UserViewPreference;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Everything a list screen needs: view mode, columns, sorting, search, the
 * filter tree, row selection and paging — plus persistence of the parts that
 * belong to the user rather than the URL.
 *
 * A consuming component supplies dataViewModule(), dataViewColumns() and
 * dataViewFilterFields(), then renders <x-data-view>.
 */
trait WithDataView
{
    // Every list screen pages, so paging arrives with the trait rather than
    // each screen having to remember it.
    use WithPagination;

    public string $viewMode = ViewMode::Table->value;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sortBy = '';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public int $perPage = 25;

    /** @var array<int, string> */
    public array $visibleColumns = [];

    /** @var array<int, string> */
    public array $pinnedColumns = [];

    /**
     * The filter tree, kept in the array shape the builder UI edits and shared
     * through the URL so a filtered list can be linked to.
     *
     * @var array<string, mixed>
     */
    #[Url(as: 'f', except: ['match' => FilterGroup::MATCH_ALL, 'conditions' => [], 'groups' => []])]
    public array $filters = ['match' => FilterGroup::MATCH_ALL, 'conditions' => [], 'groups' => []];

    /** @var array<int, int> */
    public array $selected = [];

    public bool $selectAllMatching = false;

    public function mountWithDataView(): void
    {
        $this->visibleColumns = $this->defaultVisibleColumns();

        $preference = $this->currentUser()
            ? UserViewPreference::lookup($this->currentUser(), $this->dataViewModule())
            : null;

        if ($preference === null) {
            return;
        }

        $this->viewMode = $preference->viewMode()->value;
        $this->perPage = $preference->per_page;

        // Stored columns are re-checked against what the screen offers now, so a
        // renamed or removed column cannot resurrect itself from a saved layout.
        if (is_array($preference->columns) && $preference->columns !== []) {
            $this->visibleColumns = $this->sanitiseColumns($preference->columns);
        }

        if (is_array($preference->pinned_columns)) {
            $this->pinnedColumns = $this->sanitisePins($preference->pinned_columns);
        }
    }

    // -- View mode ----------------------------------------------------------

    public function setViewMode(string $mode): void
    {
        $resolved = ViewMode::tryFrom($mode);

        if ($resolved === null) {
            return;
        }

        $this->viewMode = $resolved->value;
        $this->rememberPreference(['view_mode' => $resolved->value]);
    }

    public function currentViewMode(): ViewMode
    {
        return ViewMode::tryFrom($this->viewMode) ?? ViewMode::Table;
    }

    /**
     * @return array<int, ViewMode>
     */
    public function availableViewModes(): array
    {
        // Kanban only makes sense once a screen says what to group by.
        return array_values(array_filter(
            ViewMode::cases(),
            fn (ViewMode $mode) => $mode !== ViewMode::Kanban || $this->dataViewKanbanField() !== null
        ));
    }

    // -- Columns ------------------------------------------------------------

    /**
     * @return array<int, Column>
     */
    public function orderedColumns(): array
    {
        $byKey = collect($this->dataViewColumns())->keyBy('key');

        return collect($this->visibleColumns)
            ->map(fn (string $key) => $byKey->get($key))
            ->filter()
            ->values()
            ->all();
    }

    public function toggleColumn(string $key): void
    {
        $column = collect($this->dataViewColumns())->firstWhere('key', $key);

        if ($column === null || $column->locked) {
            return;
        }

        if (in_array($key, $this->visibleColumns, true)) {
            $this->visibleColumns = array_values(array_diff($this->visibleColumns, [$key]));
            // A hidden column cannot stay pinned, or unhiding it later would
            // silently jump it back to the left edge.
            $this->pinnedColumns = array_values(array_diff($this->pinnedColumns, [$key]));
        } else {
            $this->visibleColumns = $this->sanitiseColumns([...$this->visibleColumns, $key]);
        }

        $this->rememberPreference([
            'columns' => $this->visibleColumns,
            'pinned_columns' => $this->pinnedColumns,
        ]);
    }

    /**
     * @param  array<int, mixed>  $keys  Straight from the browser after a drag.
     */
    public function reorderColumns(array $keys): void
    {
        $this->visibleColumns = $this->sanitiseColumns($keys);
        $this->rememberPreference(['columns' => $this->visibleColumns]);
    }

    public function resetColumns(): void
    {
        $this->visibleColumns = $this->defaultVisibleColumns();
        $this->pinnedColumns = [];
        $this->rememberPreference([
            'columns' => $this->visibleColumns,
            'pinned_columns' => [],
        ]);
    }

    public function columnIsVisible(string $key): bool
    {
        return in_array($key, $this->visibleColumns, true);
    }

    /**
     * Pin a column to the left edge so it stays put while the table scrolls
     * sideways. Pinning also forces the column visible.
     */
    public function togglePin(string $key): void
    {
        if (! in_array($key, $this->availableColumnKeys(), true)) {
            return;
        }

        if (in_array($key, $this->pinnedColumns, true)) {
            $this->pinnedColumns = array_values(array_diff($this->pinnedColumns, [$key]));
        } else {
            $this->pinnedColumns = $this->sanitisePins([...$this->pinnedColumns, $key]);

            if (! $this->columnIsVisible($key)) {
                $this->visibleColumns = $this->sanitiseColumns([...$this->visibleColumns, $key]);
            }
        }

        $this->rememberPreference([
            'columns' => $this->visibleColumns,
            'pinned_columns' => $this->pinnedColumns,
        ]);
    }

    public function columnIsPinned(string $key): bool
    {
        return in_array($key, $this->pinnedColumns, true);
    }

    /**
     * Pinned columns render first, in the order they were pinned.
     *
     * @return array<int, Column>
     */
    public function pinnedThenLooseColumns(): array
    {
        $ordered = collect($this->orderedColumns());

        return [
            ...$ordered->filter(fn (Column $column) => $this->columnIsPinned($column->key))
                ->sortBy(fn (Column $column) => array_search($column->key, $this->pinnedColumns, true))
                ->values()
                ->all(),
            ...$ordered->reject(fn (Column $column) => $this->columnIsPinned($column->key))->values()->all(),
        ];
    }

    // -- Sorting ------------------------------------------------------------

    public function sort(string $key): void
    {
        $column = collect($this->dataViewColumns())->firstWhere('key', $key);

        if ($column === null || ! $column->sortable) {
            return;
        }

        if ($this->sortBy === $key) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $key;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    // -- Filters ------------------------------------------------------------

    public function filterGroup(): FilterGroup
    {
        return FilterGroup::fromArray($this->filters);
    }

    /**
     * @return array<string, FilterField>
     */
    public function filterFieldMap(): array
    {
        return collect($this->dataViewFilterFields())->keyBy('key')->all();
    }

    public function addCondition(?int $groupIndex = null): void
    {
        $first = collect($this->dataViewFilterFields())->first();

        if ($first === null) {
            return;
        }

        $condition = [
            'field' => $first->key,
            'operator' => $first->type->operators()[0]->value,
            'value' => null,
            'second_value' => null,
            'selected' => [],
        ];

        if ($groupIndex === null) {
            $this->filters['conditions'][] = $condition;

            return;
        }

        $this->filters['groups'][$groupIndex]['conditions'][] = $condition;
    }

    public function removeCondition(int $index, ?int $groupIndex = null): void
    {
        if ($groupIndex === null) {
            unset($this->filters['conditions'][$index]);
            $this->filters['conditions'] = array_values($this->filters['conditions']);
        } else {
            unset($this->filters['groups'][$groupIndex]['conditions'][$index]);
            $this->filters['groups'][$groupIndex]['conditions'] = array_values(
                $this->filters['groups'][$groupIndex]['conditions']
            );
        }

        $this->resetPage();
    }

    public function addFilterGroup(): void
    {
        $this->filters['groups'][] = ['match' => FilterGroup::MATCH_ANY, 'conditions' => [], 'groups' => []];
        $this->addCondition(count($this->filters['groups']) - 1);
    }

    public function removeFilterGroup(int $groupIndex): void
    {
        unset($this->filters['groups'][$groupIndex]);
        $this->filters['groups'] = array_values($this->filters['groups']);
        $this->resetPage();
    }

    /**
     * Keep the operator legal when the chosen field changes type.
     */
    public function updatedFilters(mixed $value, ?string $key = null): void
    {
        $this->normaliseConditions();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filters = ['match' => FilterGroup::MATCH_ALL, 'conditions' => [], 'groups' => []];
        $this->resetPage();
    }

    public function activeFilterCount(): int
    {
        return count($this->filterGroup()->usableConditions($this->filterFieldMap()));
    }

    /**
     * One removable chip per condition that is actually being applied. Walking
     * the raw state keeps each chip's index, so it knows what to remove.
     *
     * @return array<int, array{label: string, index: int, group: int|null}>
     */
    public function activeFilterChips(): array
    {
        $fields = $this->filterFieldMap();

        $chip = function (mixed $state, int $index, ?int $group) use ($fields): ?array {
            if (! is_array($state)) {
                return null;
            }

            $condition = FilterCondition::fromArray($state);

            if ($condition === null) {
                return null;
            }

            $field = $fields[$condition->field] ?? null;

            if ($field === null || ! $condition->isUsable($field)) {
                return null;
            }

            return ['label' => $condition->summary($field), 'index' => $index, 'group' => $group];
        };

        $chips = [];

        foreach ((array) ($this->filters['conditions'] ?? []) as $index => $state) {
            $chips[] = $chip($state, (int) $index, null);
        }

        foreach ((array) ($this->filters['groups'] ?? []) as $groupIndex => $group) {
            foreach ((array) (is_array($group) ? ($group['conditions'] ?? []) : []) as $index => $state) {
                $chips[] = $chip($state, (int) $index, (int) $groupIndex);
            }
        }

        return array_values(array_filter($chips));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || ! $this->filterGroup()->isEmpty();
    }

    /**
     * @return array<string, string>
     */
    public function operatorOptionsFor(string $fieldKey): array
    {
        $field = $this->filterFieldMap()[$fieldKey] ?? null;

        return $field === null ? [] : FilterOperator::optionsFor($field->type);
    }

    public function fieldType(string $fieldKey): FilterFieldType
    {
        $field = $this->filterFieldMap()[$fieldKey] ?? null;

        return $field === null ? FilterFieldType::Text : $field->type;
    }

    // -- Selection ----------------------------------------------------------

    public function toggleSelection(int $id): void
    {
        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];

        $this->selectAllMatching = false;
    }

    /**
     * Select or clear every row on the page being viewed. The ids come from the
     * screen's own query, never from the browser.
     */
    public function togglePageSelection(): void
    {
        $ids = $this->currentPageIds();

        $this->selected = $this->pageIsFullySelected()
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique([...$this->selected, ...$ids]));

        $this->selectAllMatching = false;
    }

    public function pageIsFullySelected(): bool
    {
        $ids = $this->currentPageIds();

        if ($ids === []) {
            return false;
        }

        return array_diff($ids, $this->selected) === [];
    }

    /**
     * @return array<int, int>
     */
    public function currentPageIds(): array
    {
        $model = $this->dataViewModel();

        return $this->dataViewQuery()
            ->forPage($this->getPage(), $this->perPage)
            ->pluck($model->qualifyColumn($model->getKeyName()))
            ->map(fn (mixed $id) => (int) $id)
            ->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAllMatching = false;
    }

    public function selectAllMatchingFilters(): void
    {
        $this->selectAllMatching = true;
    }

    public function selectionCount(): int
    {
        return $this->selectAllMatching
            ? $this->dataViewQuery()->toBase()->getCountForPagination()
            : count($this->selected);
    }

    public function hasSelection(): bool
    {
        return $this->selectAllMatching || $this->selected !== [];
    }

    // -- Paging -------------------------------------------------------------

    public function setPerPage(int $perPage): void
    {
        $this->perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $this->rememberPreference(['per_page' => $this->perPage]);
        $this->resetPage();
    }

    // -- Query --------------------------------------------------------------

    /**
     * The screen's base query with search, filters and sorting applied.
     *
     * @return Builder<covariant Model>
     */
    public function dataViewQuery(): Builder
    {
        $query = $this->dataViewBaseQuery();

        if ($this->search !== '') {
            $columns = $this->dataViewSearchColumns();

            if ($columns !== []) {
                $query->where(function (Builder $inner) use ($columns) {
                    foreach ($columns as $column) {
                        $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$this->search.'%');
                    }
                });
            }
        }

        app(FilterApplier::class)->apply($query, $this->filterGroup(), $this->filterFieldMap());

        $sortColumn = collect($this->dataViewColumns())->firstWhere('key', $this->sortBy);

        if ($sortColumn !== null && $sortColumn->sortable) {
            $query->orderBy($sortColumn->sortColumn(), $this->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        // A stable tiebreaker, or paging can repeat or skip rows when the sort
        // column holds duplicates.
        return $query->orderBy($query->qualifyColumn($this->dataViewModel()->getKeyName()), 'desc');
    }

    // -- Cells --------------------------------------------------------------

    /**
     * What one cell shows. The default handles the common attribute types; a
     * module overrides this when a column needs links, chips or currency.
     */
    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        $value = $record->getAttribute($column->key);

        return match (true) {
            $value === null || $value === '' => new HtmlString('<span class="text-muted-foreground">&mdash;</span>'),
            $value instanceof CarbonInterface => $value->format('j M Y'),
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \BackedEnum => (string) $value->value,
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    // -- Kanban -------------------------------------------------------------

    /**
     * @return array<int, array{value: string, label: string, color: ?string}>
     */
    public function dataViewKanbanColumns(): array
    {
        return [];
    }

    /**
     * Move one record to another kanban column.
     *
     * The record is fetched through the visibility scope and checked against
     * the policy, and the target must be one of this board's own columns — the
     * browser cannot name an arbitrary record or value.
     */
    public function moveCard(int $id, string $value): void
    {
        $field = $this->dataViewKanbanField();

        if ($field === null) {
            return;
        }

        $allowed = array_column($this->dataViewKanbanColumns(), 'value');

        if (! in_array($value, $allowed, true)) {
            return;
        }

        $record = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($record === null) {
            return;
        }

        $this->authorize('update', $record);

        $record->setAttribute($field, $value);
        $record->save();
    }

    // -- Contract -----------------------------------------------------------

    abstract public function dataViewModule(): string;

    /**
     * @return array<int, Column>
     */
    abstract public function dataViewColumns(): array;

    /**
     * @return Builder<covariant Model>
     */
    abstract public function dataViewBaseQuery(): Builder;

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return [];
    }

    /**
     * The field a kanban board groups by, or null when the screen has none.
     */
    public function dataViewKanbanField(): ?string
    {
        return null;
    }

    // -- Internals ----------------------------------------------------------

    private function dataViewModel(): Model
    {
        return $this->dataViewBaseQuery()->getModel();
    }

    /**
     * @return array<int, string>
     */
    private function defaultVisibleColumns(): array
    {
        return collect($this->dataViewColumns())
            ->reject(fn (Column $column) => $column->hiddenByDefault)
            ->pluck('key')
            ->all();
    }

    /**
     * Drop unknown keys, de-duplicate, and keep locked columns present.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function sanitiseColumns(array $keys): array
    {
        $available = collect($this->dataViewColumns());
        $known = $available->pluck('key')->all();

        $kept = collect($keys)
            ->filter(fn (mixed $key) => is_string($key) && in_array($key, $known, true))
            ->unique()
            ->values();

        foreach ($available->where('locked', true) as $locked) {
            if (! $kept->contains($locked->key)) {
                $kept->prepend($locked->key);
            }
        }

        return $kept->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableColumnKeys(): array
    {
        return collect($this->dataViewColumns())->pluck('key')->all();
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function sanitisePins(array $keys): array
    {
        $known = $this->availableColumnKeys();

        return collect($keys)
            ->filter(fn (mixed $key) => is_string($key) && in_array($key, $known, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Coerce each condition so its operator is one its field type offers.
     */
    private function normaliseConditions(): void
    {
        $fields = $this->filterFieldMap();

        $fix = function (array $condition) use ($fields): array {
            $field = $fields[$condition['field'] ?? ''] ?? null;

            if ($field === null) {
                return $condition;
            }

            $allowed = array_map(fn (FilterOperator $operator) => $operator->value, $field->type->operators());

            if (! in_array($condition['operator'] ?? '', $allowed, true)) {
                $condition['operator'] = $allowed[0];
                $condition['value'] = null;
                $condition['second_value'] = null;
                $condition['selected'] = [];
            }

            return $condition;
        };

        $this->filters['conditions'] = array_map($fix, (array) ($this->filters['conditions'] ?? []));

        foreach ((array) ($this->filters['groups'] ?? []) as $index => $group) {
            $this->filters['groups'][$index]['conditions'] = array_map(
                $fix,
                (array) ($group['conditions'] ?? [])
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rememberPreference(array $attributes): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        UserViewPreference::remember($user, $this->dataViewModule(), $attributes);
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
