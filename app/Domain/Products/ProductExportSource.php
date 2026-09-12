<?php

namespace App\Domain\Products;

use App\Domain\Products\Models\Product;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the products list for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — including visibleTo(), so an export can never contain rows the person
 * could not see on screen.
 */
class ProductExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Product>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Product::query()->visibleTo($user)->with(['owner', 'components.product']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            ProductFields::filters()
        );

        $sortable = ProductFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('products.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Product $record */
        return array_map(fn (string $key) => match ($key) {
            'kind' => $record->kind()->label(),
            'unit' => $record->unit()->label(),
            'margin' => $record->margin(),
            'owner' => $record->owner?->name,
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Products';
    }
}
