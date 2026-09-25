<?php

namespace App\Livewire\Activities;

use App\Domain\Activities\Actions\CancelActivityAction;
use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\DeleteActivityAction;
use App\Domain\Activities\Actions\ReopenActivityAction;
use App\Domain\Activities\ActivityExportSource;
use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The activities list — tasks, calls and meetings together.
 *
 * The component owns the query, including visibleTo(), so an activity outside
 * the viewer's access level never reaches the page in any of the four views.
 *
 * The board groups by **status**, and dropping a card into a column is the same
 * thing as pressing Complete: moveCard() goes through the complete, reopen and
 * cancel actions rather than writing the column, because they own `status`.
 */
#[Title('Activities')]
class ActivitiesIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() can hand anything it does not style back to the kit.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public const QUICK_FILTERS = ['mine', 'today', 'overdue', 'this_week', 'completed'];

    public function mount(): void
    {
        $this->authorize('viewAny', Activity::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'activities';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return ActivityFields::columns();
    }

    /**
     * @return Builder<Activity>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Activity::query()
            ->visibleTo(auth()->user())
            ->with(['owner:id,name', 'related']);

        // Soonest first when nobody has chosen a sort. Applied to the base
        // query rather than by defaulting $sortBy, because the kit's sort
        // property is bound to the URL: a default there would either show up in
        // every link or be overwritten when Livewire rehydrates the query
        // string. The kit appends its own ORDER BY after this one.
        if ($this->sortBy === '') {
            $query->orderBy('activities.due_at');
        }

        return match ($this->quickFilter) {
            'mine' => $query->where('activities.owner_id', auth()->id()),
            'today' => $query->open()->whereBetween('activities.due_at', [now()->startOfDay(), now()->endOfDay()]),
            'overdue' => $query->overdue(),
            'this_week' => $query->open()->whereBetween('activities.due_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'completed' => $query->withStatus(ActivityStatus::Completed),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(ActivityFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return ActivityFields::searchColumns();
    }

    public function dataViewKanbanField(): ?string
    {
        return 'status';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        return array_map(
            fn (ActivityStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            ActivityStatus::cases()
        );
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('activities.export') === true
            ? app(ActivityExportSource::class)
            : null;
    }

    // -- Cells ---------------------------------------------------------------

    /**
     * Whether the viewer may act on the rows in front of them.
     *
     * Asked once rather than per row: the base query already applies the access
     * scope, so everything on screen is visible and only the permission is left
     * to check. A policy call per row would be one visibility query per row.
     */
    #[Computed]
    public function canAct(): bool
    {
        return auth()->user()?->can('activities.update') === true;
    }

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Activity $record */
        return match ($column->key) {
            'subject' => $this->subjectCell($record),
            'type' => new HtmlString(ChipPalette::chip($record->type()->label(), $record->type()->color())),
            'status' => new HtmlString(ChipPalette::chip($record->status()->label(), $record->status()->color())),
            'priority' => $this->priorityCell($record),
            'due_at' => $this->dueCell($record),
            'related' => $this->relatedCell($record),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            'recurrence' => $this->recurrenceCell($record),
            'duration_minutes' => $record->duration_minutes === null
                ? $this->blank()
                : $record->duration_minutes.' min',
            'completed_at' => $record->completed_at?->format('j M Y, H:i') ?? $this->blank(),
            default => $this->defaultCellFor($record, $column),
        };
    }

    /**
     * The subject, with the round tick a task list is worked from.
     *
     * The button is in the cell rather than a column of its own so it reads as
     * part of the row in all four views, and so the list does not grow a column
     * that is blank for anyone who may only look.
     */
    private function subjectCell(Activity $record): HtmlString
    {
        $label = e($record->subject);

        $title = $this->canAct()
            ? '<a href="'.e(route('activities.edit', $record->id)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'.$label.'</a>'
            : '<span class="font-medium text-foreground">'.$label.'</span>';

        if ($record->isOccurrence()) {
            $title .= '<span class="ml-1.5 text-muted-foreground" title="One of a repeating series">'
                .'<svg class="inline h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
                .'<path d="M17 2l4 4-4 4M3 11v-1a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v1a4 4 0 01-4 4H3"/></svg>'
                .'<span class="sr-only">Repeating</span></span>';
        }

        if (! $this->canAct()) {
            return new HtmlString($title);
        }

        $tick = $record->isCompleted()
            ? '<button type="button" wire:click="reopen('.$record->id.')" title="Put it back on the list" '
                .'class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">'
                .'<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">'
                .'<path d="M20 6L9 17l-5-5"/></svg>'
                .'<span class="sr-only">Reopen '.$label.'</span></button>'
            : '<button type="button" wire:click="complete('.$record->id.')" title="Mark it done" '
                .'class="h-4 w-4 shrink-0 rounded-full border-2 border-border transition-colors hover:border-emerald-500">'
                .'<span class="sr-only">Complete '.$label.'</span></button>';

        return new HtmlString('<span class="flex items-center gap-2">'.$tick.'<span>'.$title.'</span></span>');
    }

    private function priorityCell(Activity $record): HtmlString
    {
        $priority = $record->priority();

        return new HtmlString(ChipPalette::chip($priority->label(), $priority->color()));
    }

    /**
     * An overdue activity says so in the cell rather than only in a filter — a
     * date in the past is easy to scan past.
     */
    private function dueCell(Activity $record): HtmlString
    {
        $label = e($record->dueLabel());

        if (! $record->isOverdue()) {
            return new HtmlString($label);
        }

        return new HtmlString(
            '<span class="text-destructive" title="Past its due date">'.$label.'</span>'
        );
    }

    private function relatedCell(Activity $record): string|HtmlString
    {
        $related = $record->related;

        if ($related === null) {
            return $this->blank();
        }

        $label = ActivityRelations::typeLabel($related).': '.ActivityRelations::label($related);
        $route = ActivityRelations::showRoute($related);

        if ($route === null) {
            return $label;
        }

        return new HtmlString(
            '<a href="'.e($route).'" wire:navigate class="text-muted-foreground hover:text-accent hover:underline">'
            .e($label).'</a>'
        );
    }

    private function recurrenceCell(Activity $record): string|HtmlString
    {
        $rule = $record->recurrence();

        if ($rule !== null) {
            return $rule->label();
        }

        return $record->isOccurrence() ? 'One of a series' : $this->blank();
    }

    // -- Board drag ----------------------------------------------------------

    /**
     * A card dropped into another status column.
     *
     * Overridden so the move goes through the actions that own `status` rather
     * than the kit's generic column update, and so every refusal comes back as
     * false — which is what puts the card back where it came from.
     */
    public function moveCard(int $id, string $value): bool
    {
        $activity = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($activity === null) {
            return false;
        }

        $this->authorize('update', $activity);

        $status = ActivityStatus::tryFrom($value);

        if ($status === null) {
            return false;
        }

        $moved = match ($status) {
            ActivityStatus::Completed => app(CompleteActivityAction::class)($activity),
            ActivityStatus::Cancelled => app(CancelActivityAction::class)($activity),
            ActivityStatus::Open => app(ReopenActivityAction::class)($activity),
        };

        if (! $moved) {
            // Dropped back into the column it was already in.
            return false;
        }

        $this->dispatch(
            'notify',
            type: 'success',
            message: $activity->subject.' is now '.strtolower($status->label()).'.',
        );

        return true;
    }

    // -- Actions -------------------------------------------------------------

    public function complete(int $id): void
    {
        $activity = $this->authorisedRecord($id);

        if ($activity === null) {
            return;
        }

        if (app(CompleteActivityAction::class)($activity)) {
            $this->dispatch('notify', type: 'success', message: $activity->subject.' is done.');
        }
    }

    public function reopen(int $id): void
    {
        $activity = $this->authorisedRecord($id);

        if ($activity === null) {
            return;
        }

        if (app(ReopenActivityAction::class)($activity)) {
            $this->dispatch('notify', type: 'success', message: $activity->subject.' is back on the list.');
        }
    }

    public function cancel(int $id): void
    {
        $activity = $this->authorisedRecord($id);

        if ($activity === null) {
            return;
        }

        if (app(CancelActivityAction::class)($activity)) {
            $this->dispatch('notify', type: 'success', message: $activity->subject.' was called off.');
        }
    }

    public function completeSelected(): void
    {
        $activities = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $done = 0;

        foreach ($activities as $activity) {
            if (auth()->user()?->can('update', $activity) && app(CompleteActivityAction::class)($activity)) {
                $done++;
            }
        }

        $this->clearSelection();
        $this->dispatch('notify', type: 'success', message: $done.' '.str('activity')->plural($done).' marked done.');
    }

    public function delete(int $id): void
    {
        $activity = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($activity === null) {
            return;
        }

        $this->authorize('delete', $activity);

        app(DeleteActivityAction::class)($activity);

        $this->clearSelection();
        $this->dispatch('activity-deleted', name: $activity->subject);
    }

    public function deleteSelected(): void
    {
        $activities = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $removed = 0;

        foreach ($activities as $activity) {
            if (auth()->user()?->can('delete', $activity)) {
                app(DeleteActivityAction::class)($activity);
                $removed++;
            }
        }

        $this->clearSelection();
        $this->dispatch('activity-deleted', name: $removed.' '.str('activity')->plural($removed));
    }

    /**
     * A record from the screen's own query, with the update policy applied.
     */
    private function authorisedRecord(int $id): ?Activity
    {
        $activity = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($activity === null) {
            return null;
        }

        $this->authorize('update', $activity);

        return $activity;
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, self::QUICK_FILTERS, true) && $this->quickFilter !== $chip
            ? $chip
            : '';

        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->quickFilter = '';
        $this->clearFilters();
        $this->search = '';
    }

    // -- Totals --------------------------------------------------------------

    /**
     * What the current filters contain, counted over the whole filtered set
     * rather than the page — so the figures describe the data and not what
     * happens to be on screen.
     *
     * @return array{count: int, open: int, overdue: int, completed: int}
     */
    #[Computed]
    public function totals(): array
    {
        // `applyScopes()` before `getQuery()`, never `getQuery()` alone.
        // Eloquent applies its global scopes at execution time, so dropping
        // straight to the base builder silently discards them — and Activity
        // soft-deletes, which would count removed activities in a figure
        // printed under the rows they are not in.
        $query = $this->dataViewQuery()
            ->applyScopes()
            ->getQuery()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);

        $row = $query
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COALESCE(SUM(activities.status = ?), 0) as open_count', [ActivityStatus::Open->value])
            ->selectRaw('COALESCE(SUM(activities.status = ?), 0) as completed_count', [ActivityStatus::Completed->value])
            // The same all-day allowance Activity::isOverdue() makes, so the
            // card and the row cannot disagree about what is late.
            ->selectRaw(
                'COALESCE(SUM(activities.status = ? AND ('
                .'(activities.all_day = 0 AND activities.due_at < ?) OR '
                .'(activities.all_day = 1 AND activities.due_at < ?))), 0) as overdue_count',
                [ActivityStatus::Open->value, now(), now()->startOfDay()]
            )
            ->first();

        return [
            'count' => (int) ($row->row_count ?? 0),
            'open' => (int) ($row->open_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'completed' => (int) ($row->completed_count ?? 0),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->dataViewQuery()->paginate($this->perPage);
    }

    public function render(): View
    {
        return view('livewire.activities.activities-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
