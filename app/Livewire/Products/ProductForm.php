<?php

namespace App\Livewire\Products;

use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Products\DTOs\ProductData;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Adds or edits one catalogue row.
 *
 * A bundle's components are edited here rather than on a screen of their own,
 * because what is in a bundle is what a bundle *is* — and `SaveProductAction`
 * writes the row and its parts in one transaction, so a bundle is never briefly
 * saved with the wrong contents.
 */
#[Title('Product')]
class ProductForm extends Component
{
    use AuthorizesRequests;
    use WithCustomFieldForm;

    public ?int $productId = null;

    public string $name = '';

    public string $sku = '';

    public string $kind = 'product';

    public string $unit = 'each';

    public string $description = '';

    public string $category = '';

    public string $costPrice = '';

    public string $listPrice = '';

    public string $taxRate = '';

    public bool $isActive = true;

    public string $ownerId = '';

    /**
     * The bundle's parts, in order.
     *
     * Typed as loosely as it really is: a wire-bound public property, so the
     * browser decides what arrives in it. `ProductData::fromArray()` is what
     * turns that into components, dropping anything empty or repeated.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $components = [];

    public ?string $error = null;

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->authorize('update', $product);
            $this->fillFrom($product);
            $this->loadCustomFields($product);

            return;
        }

        $this->authorize('create', Product::class);

        $this->ownerId = (string) auth()->id();
        $this->loadCustomFields();
    }

    // -- Options ---------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function kindOptions(): array
    {
        return ProductKind::options();
    }

    /**
     * @return array<string, string>
     */
    public function unitOptions(): array
    {
        return ProductUnit::options();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * What a bundle can be built from.
     *
     * Bundles are left out: a bundle of bundles is a tree somebody has to reason
     * about in their head when reading a quote, and SaveProductAction would in
     * any case refuse the ones that loop. Also excluded is the row being edited,
     * which is the most obvious loop of all.
     *
     * @return array<int, string>
     */
    public function componentOptions(): array
    {
        return Product::query()
            ->visibleTo(auth()->user())
            ->where('kind', '!=', ProductKind::Bundle->value)
            ->when($this->productId !== null, fn ($query) => $query->whereKeyNot($this->productId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function isBundle(): bool
    {
        return (ProductKind::tryFrom($this->kind) ?? ProductKind::Product)->hasComponents();
    }

    /**
     * What the parts come to at their own list prices, so somebody pricing a
     * bundle can see what they are discounting from.
     */
    public function componentTotal(): float
    {
        $ids = array_filter(array_map(
            fn (array $component): int => (int) ($component['product_id'] ?? 0),
            $this->components,
        ));

        if ($ids === []) {
            return 0.0;
        }

        $prices = Product::query()->whereKey($ids)->pluck('list_price', 'id');
        $total = 0.0;

        foreach ($this->components as $component) {
            $id = (int) ($component['product_id'] ?? 0);
            $total += (float) ($prices[$id] ?? 0) * (float) ($component['quantity'] ?? 0);
        }

        return round($total, 2);
    }

    // -- Editing ----------------------------------------------------------------

    public function addComponent(): void
    {
        $this->components[] = ['product_id' => '', 'quantity' => '1'];
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    /**
     * Changing away from a bundle drops the parts rather than keeping them
     * hidden: a product carrying components nothing reads is a surprise waiting
     * for whoever switches it back.
     */
    public function updatedKind(): void
    {
        if (! $this->isBundle()) {
            $this->components = [];
        } elseif ($this->components === []) {
            $this->addComponent();
        }
    }

    public function save(): void
    {
        $product = $this->productId === null
            ? null
            : Product::query()->visibleTo(auth()->user())->whereKey($this->productId)->first();

        if ($this->productId !== null && $product === null) {
            abort(404);
        }

        $this->authorize($product === null ? 'create' : 'update', $product ?? Product::class);

        $this->validateCustomFields($this->customFieldViewer());

        $this->validate($this->rules(), [], [
            'listPrice' => 'list price',
            'costPrice' => 'cost',
            'taxRate' => 'tax rate',
            'ownerId' => 'owner',
        ]);

        try {
            $saved = app(SaveProductAction::class)($this->definition(), $product);
        } catch (RuntimeException $refused) {
            // A bundle that would contain itself. Reported on the screen rather
            // than thrown, because it is a thing somebody did, not a fault.
            $this->error = $refused->getMessage();

            return;
        }

        $saved->saveCustomFields($this->customFields);

        $this->redirectRoute('products.edit', $saved, navigate: true);
    }

    private function definition(): ProductData
    {
        return ProductData::fromArray([
            'name' => $this->name,
            'sku' => $this->sku,
            'kind' => $this->kind,
            'unit' => $this->unit,
            'description' => $this->description,
            'category' => $this->category,
            'cost_price' => $this->costPrice,
            'list_price' => $this->listPrice,
            'tax_rate' => $this->taxRate,
            'is_active' => $this->isActive,
            'owner_id' => $this->ownerId,
            'components' => array_map(fn (array $component): array => [
                'product_id' => (int) ($component['product_id'] ?? 0),
                'quantity' => (float) ($component['quantity'] ?? 0),
            ], $this->components),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Unique when given, ignoring this row. A catalogue with two AB-1s
            // is a catalogue nobody can order from.
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($this->productId)->whereNull('deleted_at'),
            ],
            'kind' => ['required', Rule::in(array_keys(ProductKind::options()))],
            'unit' => ['required', Rule::in(array_keys(ProductUnit::options()))],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:255'],
            'costPrice' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'listPrice' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'taxRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ownerId' => ['required', 'integer', 'exists:users,id'],
            // A bundle with nothing in it is a product with a confusing label.
            'components' => [$this->isBundle() ? 'required' : 'nullable', 'array'],
            'components.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    private function fillFrom(Product $product): void
    {
        $this->productId = $product->id;
        $this->name = $product->name;
        $this->sku = (string) $product->sku;
        $this->kind = $product->kind()->value;
        $this->unit = $product->unit()->value;
        $this->description = (string) $product->description;
        $this->category = (string) $product->category;
        $this->costPrice = $product->cost_price === null ? '' : (string) $product->cost_price;
        $this->listPrice = (string) $product->list_price;
        $this->taxRate = $product->tax_rate === null ? '' : (string) $product->tax_rate;
        $this->isActive = (bool) $product->is_active;
        $this->ownerId = (string) $product->owner_id;

        $this->components = $product->components->map(fn ($item): array => [
            'product_id' => (string) $item->product_id,
            'quantity' => (string) $item->quantity,
        ])->all();
    }

    public function customFieldModule(): string
    {
        return 'products';
    }

    public function render(): View
    {
        return view('livewire.products.product-form');
    }
}
