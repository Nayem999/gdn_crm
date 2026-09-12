<?php

namespace App\Livewire\Products;

use App\Domain\Products\Actions\DeleteProductAction;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Models\Product;
use App\Domain\Products\ProductExportSource;
use App\Domain\Products\ProductFields;
use App\Domain\Settings\NumberFormat;
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
use RuntimeException;

/**
 * The catalogue, built on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a row outside the
 * viewer's access level never reaches the page, whichever view they are in.
 */
#[Title('Products')]
class ProductsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() below can hand anything it does not style back to
    // the kit's default rendering.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    /**
     * The quick filter chips from the UI standard. Kept separate from the
     * filter builder so one does not clobber the other.
     */
    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'products';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return ProductFields::columns();
    }

    /**
     * @return Builder<Product>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Product::query()
            ->visibleTo(auth()->user())
            // Components are read by the margin and bundle cells; without this
            // a page of fifty rows is fifty extra queries.
            ->with(['owner:id,name', 'components.product:id,name,list_price']);

        return match ($this->quickFilter) {
            'active' => $query->where('products.is_active', true),
            'services' => $query->where('products.kind', ProductKind::Service->value),
            'bundles' => $query->where('products.kind', ProductKind::Bundle->value),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(ProductFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return ProductFields::searchColumns();
    }

    /**
     * Grouped by what a row is, which is the one grouping worth dragging
     * between: moving a product to "service" is a change somebody means.
     */
    public function dataViewKanbanField(): ?string
    {
        return 'kind';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        $board = [];

        foreach (ProductKind::cases() as $kind) {
            $board[] = [
                'value' => $kind->value,
                'label' => $kind->label(),
                'color' => $kind->color(),
            ];
        }

        return $board;
    }

    /**
     * The board's headers carry the catalogue value of each column.
     */
    public function dataViewKanbanSumField(): ?string
    {
        return 'list_price';
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('products.export') === true
            ? app(ProductExportSource::class)
            : null;
    }

    /**
     * How each cell reads. Chips and links live here rather than in the view so
     * the table, grid, list and kanban all show a field the same way.
     */
    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Product $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('products.edit', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->name).'</a>'
                .($record->is_active ? '' : ' <span class="text-xs text-muted-foreground">(off)</span>')
            ),
            'kind' => new HtmlString(ChipPalette::chip($record->kind()->label(), $record->kind()->color())),
            'unit' => $record->unit()->label(),
            'list_price' => NumberFormat::format($record->listPrice(), 2),
            'cost_price' => $record->cost_price === null
                ? $this->blank()
                : NumberFormat::format((float) $record->cost_price, 2),
            'margin' => $record->margin() === null
                ? $this->blank()
                : NumberFormat::format($record->margin(), 2),
            'tax_rate' => $record->tax_rate === null
                ? $this->blank()
                : NumberFormat::format((float) $record->tax_rate, 2).'%',
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            default => $this->defaultCellFor($record, $column),
        };
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['active', 'services', 'bundles'], true)
            && $this->quickFilter !== $chip
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

    // -- Actions -------------------------------------------------------------

    public function delete(int $productId): void
    {
        $product = $this->dataViewBaseQuery()->whereKey($productId)->first();

        if ($product === null) {
            return;
        }

        $this->authorize('delete', $product);

        try {
            app(DeleteProductAction::class)($product);
            $this->error = null;
        } catch (RuntimeException $refused) {
            // A product inside a bundle cannot go without changing what that
            // bundle contains. The message says which bundle.
            $this->error = $refused->getMessage();

            return;
        }

        $this->dispatch('notify', type: 'success', message: $product->name.' was removed.');
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
        return view('livewire.products.products-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
