<?php

namespace App\Livewire\CustomModules;

use App\Domain\CustomModules\CustomModuleFields;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\CustomRecordExportSource;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The list for one generated module.
 *
 * One component serves every generated module, so all four view modes, the
 * column manager, the filter builder, saved views and the export arrive for a
 * module an administrator invented five minutes ago — without a line of code
 * per module. That is the whole point of 4.6.
 *
 * The module reaches it as a **key** from the URL and is resolved through the
 * registry; an unknown one 404s rather than becoming a query.
 */
class CustomRecordsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Locked]
    public string $moduleKey = '';

    public function mount(string $module): void
    {
        $this->moduleKey = $module;

        // Resolving it here is the guard: a key the registry does not hold
        // aborts before any query is built.
        $this->module();

        $this->authorize('viewAny', CustomRecord::class);

        $this->mountWithDataView();
    }

    public function module(): CustomModule
    {
        $module = app(CustomModuleRegistry::class)->find($this->moduleKey);

        if ($module === null) {
            abort(404);
        }

        return $module;
    }

    // -- Data view contract ---------------------------------------------------

    /**
     * The module key, which is also what the custom field machinery, the saved
     * views and the stored column layout are all keyed by — so each generated
     * module keeps its own.
     */
    public function dataViewModule(): string
    {
        return $this->moduleKey;
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return CustomModuleFields::columns($this->module());
    }

    /**
     * @return Builder<CustomRecord>
     */
    public function dataViewBaseQuery(): Builder
    {
        // forModule is not optional: every generated module shares this table,
        // and a query without it would mix two modules into one list.
        return CustomRecord::query()
            ->visibleTo(auth()->user())
            ->forModule($this->module())
            ->with('owner:id,name');
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(CustomModuleFields::filters($this->module()));
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return CustomModuleFields::searchColumns();
    }

    /**
     * No kanban board on a generated module.
     *
     * The kit groups a board by a **column**, in SQL — `kanbanTotals()` runs
     * one grouped query over the whole filtered set so a column's header count
     * describes the data rather than the page. A generated module has no status
     * column: everything past the title is a custom field in another table, so
     * there is nothing to group by. Returning null means the kit does not offer
     * the mode at all, which is better than a board with no columns.
     *
     * Giving generated modules a real status column tied to a 4.4 pipeline is
     * the way to lift this, and is a change to the table rather than to here.
     */
    public function dataViewKanbanField(): ?string
    {
        return null;
    }

    public function dataViewExportSource(): DataViewExportSource
    {
        return app(CustomRecordExportSource::class);
    }

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var CustomRecord $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('custom-modules.edit', [$this->moduleKey, $record->id])).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->name).'</a>'
            ),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            default => $this->defaultCellFor($record, $column),
        };
    }

    // -- Actions --------------------------------------------------------------

    public function delete(int $recordId): void
    {
        $record = $this->dataViewBaseQuery()->whereKey($recordId)->first();

        if ($record === null) {
            return;
        }

        $this->authorize('delete', $record);

        $name = $record->name;
        $record->delete();

        $this->clearSelection();
        $this->dispatch('notify', type: 'success', message: $name.' was removed.');
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->dataViewQuery()->paginate($this->perPage);
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }

    public function render(): View
    {
        return view('livewire.custom-modules.custom-records-index', [
            'module' => $this->module(),
        ]);
    }
}
