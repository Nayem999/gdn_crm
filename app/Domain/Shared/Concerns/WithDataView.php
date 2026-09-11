<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterCondition;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Shared\Models\UserViewPreference;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
    use EditsConditions;

    // Every list screen pages, so paging arrives with the trait rather than
    // each screen having to remember it.
    use WithPagination;
    use WithSavedViews;

    public string $viewMode = ViewMode::Table->value;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sortBy = '';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    /**
     * The page sizes the screen offers. A constant because `setPerPage` and a
     * restored saved view both have to accept exactly this set.
     *
     * @var array<int, int>
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

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
    public array $filters = FilterGroup::EMPTY;

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
            $this->applyDefaultSavedView();

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

        // Last, so a chosen default view wins over the layout somebody happened
        // to leave the module in — that is what choosing one means.
        $this->applyDefaultSavedView();
    }

    // -- View mode ----------------------------------------------------------

    public function setViewMode(string $mode): void
    {
        $resolved = ViewMode::tryFrom($mode);

        // A mode the screen does not offer is refused, not just an unknown
        // one: a payload setting kanban on a list with nothing to group by
        // would leave a board with no columns and no explanation.
        if ($resolved === null || ! in_array($resolved, $this->availableViewModes(), true)) {
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

    /**
     * The fields this screen builds conditions on, for EditsConditions.
     *
     * @return array<int, FilterField>
     */
    public function conditionFields(): array
    {
        return $this->dataViewFilterFields();
    }

    /**
     * A changed filter means the current page may not exist any more.
     */
    protected function conditionsChanged(): void
    {
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
        $this->perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
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

        // One query for every row's answers rather than one per cell. Loaded
        // whenever the model can carry them, because the column manager can
        // turn a custom column on at any time.
        if (in_array(HasCustomFields::class, class_uses_recursive($query->getModel()), true)) {
            $query->with('customFieldValues');
        }

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
        // Custom fields (4.1) are answered in another table, so there is no
        // attribute to read. Handled in the kit rather than in each module's
        // cellFor, which is what makes a new field appear on every list without
        // anybody editing five components.
        if (CustomFieldColumns::isCustom($column->key)) {
            $display = CustomFieldColumns::display($record, $column->key);

            return $display === null || $display === ''
                ? new HtmlString('<span class="text-muted-foreground">&mdash;</span>')
                : $display;
        }

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
     * How many cards each kanban column has loaded so far.
     *
     * @var array<string, int>
     */
    public array $kanbanLimits = [];

    /**
     * Cards added each time a column's "load more" is used.
     */
    public const KANBAN_PAGE = 20;

    /**
     * A money-ish column to total per kanban column, or null when a sum would
     * mean nothing. Shown in the column header beside the count.
     */
    public function dataViewKanbanSumField(): ?string
    {
        return null;
    }

    public function kanbanLimitFor(string $value): int
    {
        return $this->kanbanLimits[$value] ?? self::KANBAN_PAGE;
    }

    /**
     * One column's cards.
     *
     * Queried per column rather than grouping the current page: a board built
     * from one page of 25 shows an arbitrary slice of each column and its
     * counts are simply wrong.
     *
     * @return Collection<int, Model>
     */
    public function kanbanCards(string $value)
    {
        $field = $this->dataViewKanbanField();

        if ($field === null) {
            return $this->dataViewModel()->newCollection();
        }

        $model = $this->dataViewModel();

        return $this->kanbanColumnQuery($value)
            ->orderByDesc($model->qualifyColumn($model->getKeyName()))
            ->limit($this->kanbanLimitFor($value))
            ->get();
    }

    /**
     * Count and total for every column, in one grouped query rather than one
     * per column.
     *
     * @return array<string, array{count: int, sum: float|null}>
     */
    public function kanbanTotals(): array
    {
        $field = $this->dataViewKanbanField();

        if ($field === null) {
            return [];
        }

        $model = $this->dataViewModel();
        $column = $model->qualifyColumn($field);
        $sumField = $this->dataViewKanbanSumField();

        $query = $this->dataViewQuery()
            ->getQuery()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->select($column)
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy($column);

        if ($sumField !== null) {
            $query->selectRaw('SUM('.$model->qualifyColumn($sumField).') as aggregate_sum');
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $totals[(string) $row->{$field}] = [
                'count' => (int) $row->aggregate_count,
                'sum' => $sumField === null ? null : (float) ($row->aggregate_sum ?? 0),
            ];
        }

        return $totals;
    }

    public function hasMoreKanbanCards(string $value): bool
    {
        return ($this->kanbanTotals()[$value]['count'] ?? 0) > $this->kanbanLimitFor($value);
    }

    /**
     * Show the next batch in one column, leaving the others alone.
     */
    public function loadMoreKanban(string $value): void
    {
        if (! in_array($value, array_column($this->dataViewKanbanColumns(), 'value'), true)) {
            return;
        }

        $this->kanbanLimits[$value] = $this->kanbanLimitFor($value) + self::KANBAN_PAGE;
    }

    /**
     * @return Builder<covariant Model>
     */
    private function kanbanColumnQuery(string $value)
    {
        $field = (string) $this->dataViewKanbanField();
        $model = $this->dataViewModel();

        return $this->dataViewQuery()->where($model->qualifyColumn($field), $value);
    }

    /**
     * Move one record to another kanban column.
     *
     * The record is fetched through the visibility scope and checked against
     * the policy, and the target must be one of this board's own columns — the
     * browser cannot name an arbitrary record or value.
     *
     * Returns whether the record actually moved, and that return value is
     * load-bearing: `$wire.call()` resolves with it, so the board puts a
     * rejected card back where it came from rather than leaving it in the wrong
     * column until a re-render happens to relocate it. Morphdom moving a keyed
     * node between two different parents is exactly the case not to rely on.
     */
    public function moveCard(int $id, string $value): bool
    {
        $field = $this->dataViewKanbanField();

        if ($field === null) {
            return false;
        }

        $allowed = array_column($this->dataViewKanbanColumns(), 'value');

        if (! in_array($value, $allowed, true)) {
            return false;
        }

        $record = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($record === null) {
            return false;
        }

        $this->authorize('update', $record);

        if ((string) $record->getAttribute($field) === $value) {
            // Dropped back where it started: nothing to write, and reporting a
            // move would make the board flash a change that did not happen.
            return false;
        }

        $record->setAttribute($field, $value);
        $record->save();

        return true;
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
    protected function defaultVisibleColumns(): array
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
    protected function sanitiseColumns(array $keys): array
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

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
