<?php

namespace App\Livewire\Products;

use App\Domain\Products\Actions\SetDefaultPriceBookAction;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Pricing\PriceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * The price books, and what is in the one being edited.
 *
 * A book is an **override list**, not a catalogue: it holds the products whose
 * price differs, and everything else keeps its list price. The screen says so
 * explicitly, because a half-filled book that silently priced the rest at
 * nothing would be the obvious reading otherwise.
 */
#[Title('Price books')]
class PriceBooks extends Component
{
    use AuthorizesRequests;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $validFrom = '';

    public string $validTo = '';

    public bool $isActive = true;

    public bool $editing = false;

    /** The book whose prices are on screen. */
    public ?int $openBookId = null;

    public string $entryProductId = '';

    public string $entryPrice = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', PriceBook::class);
    }

    /**
     * @return Collection<int, PriceBook>
     */
    #[Computed]
    public function books(): Collection
    {
        return PriceBook::query()
            ->withCount('entries')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function openBook(): ?PriceBook
    {
        return $this->openBookId === null
            ? null
            : PriceBook::query()->whereKey($this->openBookId)->first();
    }

    /**
     * @return Collection<int, PriceBookEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        if ($this->openBookId === null) {
            return new Collection;
        }

        return PriceBookEntry::query()
            ->where('price_book_id', $this->openBookId)
            ->with('product:id,name,sku,list_price')
            ->get()
            ->sortBy(fn (PriceBookEntry $entry): string => $entry->product === null ? '' : $entry->product->name)
            ->values();
    }

    /**
     * What can still be added to the open book: everything not already in it.
     *
     * @return array<int, string>
     */
    public function addableProducts(): array
    {
        $already = $this->entries()->pluck('product_id')->all();

        return Product::query()
            ->visibleTo(auth()->user())
            ->whereKeyNot($already === [] ? [0] : $already)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    // -- Books --------------------------------------------------------------------

    public function add(): void
    {
        $this->authorize('create', PriceBook::class);

        $this->resetEditor();
        $this->editing = true;
    }

    public function edit(int $bookId): void
    {
        $book = PriceBook::query()->whereKey($bookId)->first();

        if ($book === null) {
            return;
        }

        $this->authorize('update', $book);

        $this->editingId = $book->id;
        $this->name = $book->name;
        $this->description = (string) $book->description;
        $this->validFrom = $book->valid_from?->toDateString() ?? '';
        $this->validTo = $book->valid_to?->toDateString() ?? '';
        $this->isActive = (bool) $book->is_active;
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetEditor();
        $this->resetValidation();
    }

    public function save(): void
    {
        $book = $this->editingId === null
            ? null
            : PriceBook::query()->whereKey($this->editingId)->first();

        $this->authorize($book === null ? 'create' : 'update', $book ?? PriceBook::class);

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'validFrom' => ['nullable', 'date'],
            // A window that ends before it starts prices nothing, ever.
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
        ], [], ['validFrom' => 'start date', 'validTo' => 'end date']);

        $book ??= new PriceBook;

        $book->forceFill([
            'name' => $this->name,
            'description' => $this->description === '' ? null : $this->description,
            'valid_from' => $this->validFrom === '' ? null : $this->validFrom,
            'valid_to' => $this->validTo === '' ? null : $this->validTo,
            'is_active' => $this->isActive,
        ])->save();

        $this->resetEditor();
        unset($this->books);

        $this->dispatch('notify', type: 'success', message: $book->name.' saved.');
    }

    public function makeDefault(int $bookId): void
    {
        $book = PriceBook::query()->whereKey($bookId)->first();

        if ($book === null) {
            return;
        }

        $this->authorize('update', $book);

        try {
            app(SetDefaultPriceBookAction::class)($book);
            $this->error = null;
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->books);
    }

    public function delete(int $bookId): void
    {
        $book = PriceBook::query()->whereKey($bookId)->first();

        if ($book === null) {
            return;
        }

        $this->authorize('delete', $book);

        $name = $book->name;
        $book->delete();

        if ($this->openBookId === $bookId) {
            $this->openBookId = null;
        }

        unset($this->books, $this->entries);

        $this->dispatch('notify', type: 'success', message: $name.' was removed. Prices fall back to the catalogue.');
    }

    // -- Prices --------------------------------------------------------------------

    public function open(int $bookId): void
    {
        $this->openBookId = $this->openBookId === $bookId ? null : $bookId;
        $this->entryProductId = '';
        $this->entryPrice = '';

        unset($this->entries);
    }

    public function addEntry(): void
    {
        $book = $this->openBook();

        if ($book === null) {
            return;
        }

        $this->authorize('update', $book);

        $this->validate([
            'entryProductId' => ['required', 'integer', 'exists:products,id'],
            'entryPrice' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['entryProductId' => 'product', 'entryPrice' => 'price']);

        // updateOrCreate rather than create: the unique index would refuse a
        // second row for the same product, and a person retyping a price means
        // to change it.
        PriceBookEntry::query()->updateOrCreate(
            ['price_book_id' => $book->id, 'product_id' => (int) $this->entryProductId],
            ['price' => round((float) $this->entryPrice, 2)],
        );

        $this->entryProductId = '';
        $this->entryPrice = '';

        unset($this->entries, $this->books);
    }

    public function removeEntry(int $entryId): void
    {
        $book = $this->openBook();

        if ($book === null) {
            return;
        }

        $this->authorize('update', $book);

        PriceBookEntry::query()
            // Scoped to the open book: an id from elsewhere must not delete a
            // row this screen is not showing.
            ->where('price_book_id', $book->id)
            ->whereKey($entryId)
            ->delete();

        unset($this->entries, $this->books);
    }

    /**
     * What a product would actually be charged at in the open book, so the
     * screen shows the same figure a quote would.
     */
    public function resolvedPrice(Product $product): float
    {
        return app(PriceResolver::class)->priceFor($product, $this->openBook());
    }

    private function resetEditor(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->validFrom = '';
        $this->validTo = '';
        $this->isActive = true;
    }

    public function render(): View
    {
        return view('livewire.products.price-books');
    }
}
