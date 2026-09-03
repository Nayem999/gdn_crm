---
paths:
  - 'app/Domain/Shared/Concerns/**'
  - 'app/Domain/Shared/DataView/**'
  - 'app/Domain/Shared/Filters/**'
  - 'app/Domain/Shared/Exports/**'
  - 'app/Jobs/GenerateDataViewExport.php'
  - 'resources/views/components/data-view.blade.php'
  - 'resources/views/components/data-view/**'
  - 'resources/views/components/filter-builder*'
  - 'resources/views/components/column-manager.blade.php'
  - 'resources/views/components/export-menu.blade.php'
---

# Data view (shared list-screen kit)

## The split: state in a trait, chrome in Blade
A module's list screen is a Livewire component that `use`s `WithDataView` (plus
`ExportsDataView` if it exports), implements `dataViewModule()`,
`dataViewColumns()` and `dataViewBaseQuery()`, and renders
`<x-data-view :view="$this" :records="$this->rows" />`.

The Blade components are presentational and take explicit props; `<x-data-view>`
is the one exception — it takes the Livewire component as `:view` and fans props
out to its children. Do not add a god component that owns module queries; each
module keeps its own query so its visibility scope and policies stay in force.

`tests/Fixtures/DataViewHarness.php` is the reference implementation. It is also
in phpstan's analysed paths, which is what keeps the traits from tripping
`trait.unused` before Phase 2 modules exist.

## WithDataView already uses WithPagination
Do not add `use WithPagination;` alongside `use WithDataView;` — PHP raises a
fatal conflict error. The trait needs `getPage()`/`resetPage()` for page
selection and sorting, so it brings pagination with it.

## Nothing from the browser names a column, table or record
Every browser-supplied key is checked against the screen's own registries before
it reaches SQL:

- Filter conditions are dropped unless the field is in `dataViewFilterFields()`
  **and** the operator is one `FilterFieldType::operators()` offers for it.
- Column show/hide/reorder/pin keys are filtered through `dataViewColumns()`.
- Sorting only happens for a column that exists and is `sortable`.
- `togglePageSelection()` reads ids from the screen's own query; the page never
  posts a list of ids to select.
- `moveCard()` checks the target against `dataViewKanbanColumns()`, loads the
  record through `dataViewBaseQuery()` (so the visibility scope applies), and
  calls `$this->authorize('update', $record)`.

Keep it that way. A half-filled filter row is ignored rather than matching
everything (`FilterCondition::isUsable()`), and negations are NULL-aware so
"does not contain X" still returns rows where the value was never set.

## Exports rebuild their own query, they never carry one
A queued export cannot serialise an Eloquent builder, so `ExportRequest` holds
only state (filters, search, sort, column keys, selected ids) plus the
`DataViewExportSource` class name. The module's source rebuilds the query with
its own scope. Never widen this into something that takes a table or model name
from the request.

`ExportRequest::$filename` is fixed in the constructor. It used to be computed
from `now()` on each call, which made the job write one path and tell the user
another whenever the two calls straddled a second.

Exports over `RunDataViewExport::QUEUE_THRESHOLD` (1000) rows go to the queue and
notify the user; at or below it they download inline.

## Per-user layout lives in user_view_preferences
One row per user per module holds view mode, column order, pinned columns and
per-page. A stored layout is re-checked against the columns the screen offers
now, so a removed or renamed column cannot come back from an old saved layout.
