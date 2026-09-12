<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\DTOs\ProductData;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductBundleItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates or updates one catalogue row, and what is in it.
 *
 * The one thing this refuses outright is a **bundle that contains itself**,
 * directly or through another bundle. Left alone it is not a data oddity: every
 * price, every cost and every explosion into line items walks the tree, and a
 * cycle means each of those recurses until the process dies. The check is a
 * walk of what is already stored plus what is being saved, so the first
 * unsaved edge closing a loop is caught before it is written.
 */
class SaveProductAction
{
    /**
     * @throws RuntimeException when a bundle would end up containing itself
     */
    public function __invoke(ProductData $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product): Product {
            $product = $product === null
                ? $this->create($data)
                : $this->update($product, $data);

            if ($data->kind->hasComponents()) {
                $this->guardAgainstCycles($product, $data->components);
            }

            $this->syncComponents($product, $data->components);

            return $product->fresh() ?? $product;
        });
    }

    private function create(ProductData $data): Product
    {
        $product = new Product;

        $product->forceFill([
            ...$data->toAttributes(),
            'owner_id' => $data->ownerId ?? auth()->id(),
        ])->save();

        return $product;
    }

    private function update(Product $product, ProductData $data): Product
    {
        $attributes = $data->toAttributes();

        // The owner only moves when one was chosen, so an edit that leaves the
        // field alone cannot silently unassign the row.
        if ($data->ownerId !== null) {
            $attributes['owner_id'] = $data->ownerId;
        }

        $product->forceFill($attributes)->save();

        return $product;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, position: int}>  $components
     */
    private function syncComponents(Product $product, array $components): void
    {
        $kept = [];

        foreach ($components as $component) {
            $item = ProductBundleItem::query()->updateOrCreate(
                ['bundle_id' => $product->id, 'product_id' => $component['product_id']],
                ['quantity' => $component['quantity'], 'position' => $component['position']],
            );

            $kept[] = $item->id;
        }

        ProductBundleItem::query()
            ->where('bundle_id', $product->id)
            ->whereNotIn('id', $kept === [] ? [0] : $kept)
            ->delete();
    }

    /**
     * Refuse a bundle that would contain itself.
     *
     * @param  array<int, array{product_id: int, quantity: float, position: int}>  $components
     *
     * @throws RuntimeException
     */
    private function guardAgainstCycles(Product $bundle, array $components): void
    {
        foreach ($components as $component) {
            if ($component['product_id'] === $bundle->id) {
                throw new RuntimeException('A bundle cannot contain itself.');
            }

            if ($this->reaches($component['product_id'], $bundle->id)) {
                throw new RuntimeException(
                    'That would make a loop: something in this bundle already contains it.'
                );
            }
        }
    }

    /**
     * Whether `$from` contains `$target`, at any depth.
     *
     * Breadth-first over what is stored, with a visited set — the walk must
     * terminate even if a cycle somehow got in before this guard existed.
     */
    private function reaches(int $from, int $target): bool
    {
        $queue = [$from];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $seen, true)) {
                continue;
            }

            $seen[] = $current;

            $children = ProductBundleItem::query()
                ->where('bundle_id', $current)
                ->pluck('product_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (in_array($target, $children, true)) {
                return true;
            }

            $queue = [...$queue, ...$children];
        }

        return false;
    }
}
