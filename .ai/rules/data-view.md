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

## The kanban board queries per column, it never groups a page
`<x-data-view-kanban>` is not given `$records`. Grouping the current page would
mean a column's header count and total described one page rather than the data,
and a column with 200 cards would starve every other column of its share of the
page.

Instead the board asks the screen three things, each of which runs its own SQL
over the whole filtered set:

- `kanbanTotals()` — one grouped query returning
  `['<column value>' => ['count' => int, 'sum' => float|null]]`. It clones the
  filtered query *without* columns, orders, limit and offset, then groups by
  `dataViewKanbanField()`. This is what the header count and total come from,
  so they are true regardless of what is rendered.
- `kanbanCards($value)` — that column's cards only, newest first, limited to
  `kanbanLimitFor($value)`.
- `hasMoreKanbanCards($value)` / `loadMoreKanban($value)` — the footer button.
  `loadMoreKanban()` raises **only that column's** limit by `KANBAN_PAGE` (20),
  so loading more in one column leaves the others alone. It ignores a value that
  is not in `dataViewKanbanColumns()`.

Implement `dataViewKanbanSumField()` to get a summed money figure in the header
(leads sum `estimated_value`); return `null` and the header shows a count only.

`KANBAN_PAGE` is a trait constant, so tests must read it through the using class
(`LeadsIndex::KANBAN_PAGE`) — PHP 8.2 forbids `WithDataView::KANBAN_PAGE`.

The paginator is hidden in kanban mode; the per-column footers are the pager.

## Any aggregate that drops to the base builder must `applyScopes()` first
`kanbanTotals()`, and every module's own `totals()`, aggregate on the base query
builder — one row instead of hydrating a model per group for columns that are
not on the model. Getting there is `applyScopes()->getQuery()`, **never**
`getQuery()` alone (`toBase()` is the same thing and equally fine).

Eloquent applies its global scopes at *execution* time, not when the builder is
built, so `getQuery()` on its own silently throws them away. Every model with a
list screen soft-deletes, so the `deleted_at is null` never lands and the figure
printed above or below the rows counts records that are not in them — a count
that disagrees with what is on screen, with nothing to indicate why.

It fails quietly and only under data the happy-path tests do not create, so each
`totals()` carries a test that deletes a record and asserts the figure did not
follow it. `PipelineFunnel::totals()` is the same pattern on the dashboard.

## A card never shows the field the board groups by
`kanban.blade.php` filters `dataViewKanbanField()` out of the card's detail
lines. Without it every card in the "Scoping" column carried the line
"Stage: Scoping" — the column header repeated on each of its own cards, on all
three boards. The next visible column takes the freed line instead.

## The drag is optimistic, and `moveCard()` returns whether it moved
The card is already in its new column when the request goes out — Sortable put
it there. What makes that safe is the **return value**: `$wire.call()` resolves
with it, and `kanbanColumn` puts the card back on `false`. Do not change
`moveCard()` to return void, and keep every refusal path returning `false`
rather than throwing.

Dropping a card back into the column it was already in returns `false` too. It
is not an error, but reporting a move would flash a change that did not happen —
and on a deal it would push the closing stamp forward.

The revert restores the recorded **origin and slot** (`onStart` captures
`parent` + `nextElementSibling`; the revert uses `insertBefore`, whose null
reference appends). It does not rely on the re-render putting things right:
morphdom relocating a keyed node between two different parents is exactly the
case not to depend on.

While a move is in flight the card carries `data-card-pending` and Sortable's
`filter` excludes it, so a second drag cannot be applied to an origin the first
answer already changed.

Column headers expose `data-board-column` and `data-board-count`, and the drag
nudges the **count only** — a summed money figure is formatted server-side to
the configured separators and currency, and re-implementing that in JS would
drift from it. The sum arrives correct with the re-render.

A PHP test cannot execute a drag, and SortableJS ignores synthetic pointer
events, so `DealBoardTest` pins the JS pieces by name and the behaviour is
verified in a browser by calling the Alpine component's `submit()` with the
event shape Sortable passes.

## The filter builder's dropdowns are x-select, and need keys that move
Every dropdown in `<x-filter-builder>` is `<x-select>` — the UI standard allows
no plain `<select>` anywhere. That puts Tom Select behind `wire:ignore`, so
Livewire can neither refresh a dropdown's options nor move its selection, and a
`wire:key` that changes is the only thing that makes it rebuild. Two situations
need one, and they want different keys:

- **The options or the value's shape changed.** The comparison list depends on
  the chosen field, so its key carries the field; the value area's key carries
  field *and* operator, because the shape switches between an input, a pair of
  inputs, a single dropdown and a multi-select.
- **The row is holding a different condition.** Rows are keyed by index and
  `removeCondition()` re-indexes, so row 0's DOM gets handed whatever slid into
  index 0. The key therefore also carries a generation token — the sibling
  condition count, passed in as `:siblings` — which changes exactly when
  indices shift.

Do **not** key a dropdown on its own selected value. It works, but it tears the
node down on every pick, so an "is any of" list closes after each value and has
to be reopened. The sibling count rebuilds on add/remove only, which is when a
rebuild is actually needed.

The panel is anchored `right-0`: the toolbar sits at the right edge of the list,
and a left-anchored panel this wide runs off the viewport, taking the per-row
remove buttons with it and forcing a page-wide horizontal scrollbar.

## A custom field filters through EXISTS, and its negatives through NOT EXISTS
`FilterField` carries `customFieldId`, `customFieldColumn` and `customFieldIsList` when the field is a custom one (4.1). `FilterApplier` then reaches the answer through a correlated EXISTS subquery on `custom_field_values`, scoped to that one field id — which comes from the screen's own registry, never from the request.

The load-bearing part is the negatives. A record may have **no value row at all**, so `EXISTS(value NOT LIKE x)` silently drops every unanswered record and "does not contain" hides most of the list. Every negative operator is therefore expressed as `NOT EXISTS(the positive match)`: not_contains, not_equals, not_in, is_empty and is_false all go through the `NEGATIONS` map. That also gives the right answer for a checkbox nobody ticked — it counts as "no", matching what the non-custom branch already does for a NULL column.

A multiselect is stored as a JSON list, so "is" and "is any of" are `JSON_CONTAINS` containment tests rather than comparisons. JSON_CONTAINS exists on both MySQL 8 and the MariaDB this project develops against.

Note the pre-existing kit behaviour this sits on: an unknown operator or field is **dropped**, so the condition stops narrowing and the list returns everything. Get an operator's enum value wrong in a test and it will look like the filter matched too much.
