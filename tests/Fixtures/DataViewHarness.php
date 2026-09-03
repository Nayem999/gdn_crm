<?php

namespace Tests\Fixtures;

use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A minimal list screen built the way a real module will build one: it supplies
 * a module key, its columns, its query and its filter fields, and renders
 * <x-data-view>. Everything else comes from the shared traits.
 */
class DataViewHarness extends Component
{
    use ExportsDataView;
    use WithDataView;

    public function mount(): void
    {
        $this->mountWithDataView();
    }

    public function dataViewModule(): string
    {
        return 'data-view-records';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return [
            Column::locked('name', 'Name'),
            Column::make('stage', 'Stage'),
            Column::numeric('value', 'Value'),
            Column::make('closes_on', 'Closes on'),
            Column::optional('is_starred', 'Starred'),
        ];
    }

    /**
     * @return Builder<DataViewRecord>
     */
    public function dataViewBaseQuery(): Builder
    {
        return DataViewRecord::query();
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return [
            FilterField::text('name', 'Name'),
            FilterField::select('stage', 'Stage', ['new' => 'New', 'won' => 'Won', 'lost' => 'Lost']),
            FilterField::number('value', 'Value'),
            FilterField::date('closes_on', 'Closes on'),
            FilterField::boolean('is_starred', 'Starred'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return ['name'];
    }

    public function dataViewKanbanField(): ?string
    {
        return 'stage';
    }

    /**
     * @return array<int, array{value: string, label: string, color: ?string}>
     */
    public function dataViewKanbanColumns(): array
    {
        return [
            ['value' => 'new', 'label' => 'New', 'color' => 'blue'],
            ['value' => 'won', 'label' => 'Won', 'color' => 'emerald'],
            ['value' => 'lost', 'label' => 'Lost', 'color' => 'rose'],
        ];
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return new DataViewHarnessExportSource;
    }

    public function render(): string
    {
        return <<<'BLADE'
        <div>
            <x-data-view :view="$this" :records="$this->rows" empty-heading="No records yet" />
        </div>
        BLADE;
    }

    /**
     * The trait's query is typed against Model, so that is what a page of it is;
     * at runtime every row here is a DataViewRecord.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function rows()
    {
        return $this->dataViewQuery()->paginate($this->perPage);
    }
}
